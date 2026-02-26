<?php
/**
 * POST /api/mercado/partner/order-action.php
 * Acoes no pedido: confirmar, preparando, pronto, cancelar
 * Body: { "order_id": 123, "action": "confirmar|preparando|pronto|cancelar" }
 */

require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/PusherService.php";

setCorsHeaders();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    response(false, null, "Metodo nao permitido", 405);
}

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Token ausente", 401);

    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== OmAuth::USER_TYPE_PARTNER) {
        response(false, null, "Nao autorizado", 401);
    }

    $partnerId = $payload['uid'];
    $input = getInput();

    $orderId = (int)($input['order_id'] ?? 0);
    $action = trim($input['action'] ?? '');

    if (!$orderId || !$action) {
        response(false, null, "order_id e action sao obrigatorios", 400);
    }

    $validActions = ['confirmar', 'preparando', 'pronto', 'cancelar'];
    if (!in_array($action, $validActions)) {
        response(false, null, "Acao invalida. Use: " . implode(', ', $validActions), 400);
    }

    // Buscar pedido e validar pertence ao parceiro
    $stmt = $db->prepare("SELECT order_id, status, partner_categoria, is_pickup, customer_id, coupon_id, loyalty_points_used, cashback_discount, subtotal, payment_method, forma_pagamento, stripe_payment_intent_id FROM om_market_orders WHERE order_id = ? AND partner_id = ?");
    $stmt->execute([$orderId, $partnerId]);
    $order = $stmt->fetch();

    if (!$order) {
        response(false, null, "Pedido nao encontrado", 404);
    }

    $currentStatus = $order['status'];

    // Maquina de estados (status enum: aceito, preparando, pronto, cancelado)
    $transitions = [
        'confirmar'  => ['from' => ['pendente', 'pending'], 'to' => 'aceito'],
        'preparando' => ['from' => ['aceito'], 'to' => 'preparando'],
        'pronto'     => ['from' => ['preparando'], 'to' => 'pronto'],
        'cancelar'   => ['from' => ['pendente', 'pending', 'aceito'], 'to' => 'cancelado'],
    ];

    $transition = $transitions[$action];

    if (!in_array($currentStatus, $transition['from'])) {
        response(false, null, "Nao e possivel '$action' um pedido com status '$currentStatus'", 400);
    }

    $newStatus = $transition['to'];

    // Timestamp fields por acao
    $timestampField = null;
    switch ($action) {
        case 'confirmar':
            $timestampField = 'accepted_at';
            break;
        case 'preparando':
            $timestampField = 'preparing_started_at';
            break;
        case 'pronto':
            $timestampField = 'ready_at';
            break;
        case 'cancelar':
            $timestampField = 'completed_at';
            break;
    }

    $sql = "UPDATE om_market_orders SET status = ?, updated_at = NOW(), date_modified = NOW()";
    $params = [$newStatus];

    if ($timestampField) {
        $sql .= ", $timestampField = NOW()";
    }

    // Include current status in WHERE to prevent race conditions
    $sql .= " WHERE order_id = ? AND partner_id = ? AND status = ?";
    $params[] = $orderId;
    $params[] = $partnerId;
    $params[] = $currentStatus;

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    if ($stmt->rowCount() === 0) {
        response(false, null, "Pedido ja foi atualizado por outra acao. Atualize a pagina.", 409);
    }

    // Registrar evento
    $eventMsg = [
        'confirmar'  => 'Pedido confirmado pelo parceiro',
        'preparando' => 'Parceiro iniciou preparo',
        'pronto'     => 'Pedido pronto para retirada/entrega',
        'cancelar'   => 'Pedido cancelado pelo parceiro',
    ];

    $stmt = $db->prepare("INSERT INTO om_market_order_events (order_id, event_type, message, created_by, created_at) VALUES (?, ?, ?, ?, NOW())");
    $stmt->execute([$orderId, "partner_$action", $eventMsg[$action], "partner:$partnerId"]);

    // Financial compensation on cancellation — wrapped in a single transaction for atomicity
    if ($action === 'cancelar') {
        $customerId = (int)($order['customer_id'] ?? 0);

        $db->beginTransaction();
        try {
            // 1. Restore coupon usage
            $couponId = (int)($order['coupon_id'] ?? 0);
            if ($couponId && $customerId) {
                $db->prepare("DELETE FROM om_market_coupon_usage WHERE coupon_id = ? AND customer_id = ? AND order_id = ?")
                   ->execute([$couponId, $customerId, $orderId]);
                $db->prepare("UPDATE om_market_coupons SET current_uses = GREATEST(0, current_uses - 1) WHERE id = ?")->execute([$couponId]);
            }

            // 2. Credit back loyalty points
            $pointsUsed = (int)($order['loyalty_points_used'] ?? 0);
            if ($pointsUsed > 0 && $customerId) {
                $db->prepare("UPDATE om_market_loyalty_points SET current_points = current_points + ?, updated_at = NOW() WHERE customer_id = ?")
                   ->execute([$pointsUsed, $customerId]);
                $db->prepare("INSERT INTO om_market_loyalty_transactions (customer_id, points, type, source, reference_id, description, created_at) VALUES (?, ?, 'refund', 'order_cancelled', ?, ?, NOW())")
                   ->execute([$customerId, $pointsUsed, $orderId, "Estorno cancelamento parceiro pedido #{$orderId}"]);
            }

            // 3. Reverse cashback earned (mark as reversed)
            $cashbackUsed = (float)($order['cashback_discount'] ?? 0);
            if ($cashbackUsed > 0) {
                require_once __DIR__ . '/../helpers/cashback.php';
                refundCashback($db, $orderId);
            }

            // 4. Restore stock
            $stmtItems = $db->prepare("SELECT product_id, quantity FROM om_market_order_items WHERE order_id = ?");
            $stmtItems->execute([$orderId]);
            $items = $stmtItems->fetchAll();
            foreach ($items as $item) {
                $db->prepare("UPDATE om_market_products SET quantity = quantity + ? WHERE product_id = ?")
                   ->execute([$item['quantity'], $item['product_id']]);
            }

            $db->commit();
        } catch (Exception $compErr) {
            if ($db->inTransaction()) $db->rollBack();
            error_log("[order-action] CANCEL COMPENSATION FAILED order #{$orderId}: " . $compErr->getMessage());
        }

        // 5. Log cancellation for financial reconciliation
        error_log("[order-action] CANCEL COMPENSATION order #{$orderId}: coupon={$couponId} points={$pointsUsed} cashback=R\${$cashbackUsed} partner={$partnerId}");
    }

    // Despachar entregador quando parceiro marca "pronto"
    // Logica: verificar plano do parceiro (uses_platform_delivery)
    // - Plano Premium: despachar BoraUm automaticamente
    // - Plano Basico: parceiro entrega com entregador proprio, nao despacha
    // - Mercado/supermercado: shopper ja faz a entrega
    $dispatchResult = null;
    if ($action === 'pronto') {
        $categoria = strtolower(trim($order['partner_categoria'] ?? ''));
        $categoriaMercado = in_array($categoria, ['mercado', 'supermercado']);
        $isPickup = (bool)($order['is_pickup'] ?? false);

        if (!$categoriaMercado && !$isPickup) {
            // Verificar plano do parceiro
            $stmtPartnerPlan = $db->prepare("
                SELECT pp.uses_platform_delivery, pp.slug as plan_slug
                FROM om_market_partners p
                LEFT JOIN om_partner_plans pp ON pp.id = p.plan_id
                WHERE p.partner_id = ?
            ");
            $stmtPartnerPlan->execute([$partnerId]);
            $partnerPlanInfo = $stmtPartnerPlan->fetch();

            $usesPlatformDelivery = (bool)($partnerPlanInfo['uses_platform_delivery'] ?? false);

            if ($usesPlatformDelivery) {
                // Plano Premium: despachar entregador BoraUm automaticamente
                try {
                    require_once __DIR__ . '/../helpers/delivery.php';
                    $stmtOrder = $db->prepare("SELECT * FROM om_market_orders WHERE order_id = ?");
                    $stmtOrder->execute([$orderId]);
                    $orderData = $stmtOrder->fetch();
                    if ($orderData) {
                        $dispatchResult = dispatchToBoraUm($db, $orderData);
                        if ($dispatchResult['success']) {
                            $db->prepare("INSERT INTO om_market_order_events (order_id, event_type, message, created_by, created_at) VALUES (?, ?, ?, ?, NOW())")
                                ->execute([$orderId, 'delivery_dispatched', 'Entregador BoraUm solicitado (Plano Premium)', 'system']);
                        }
                    }
                } catch (Exception $dispatchErr) {
                    error_log("[order-action] Erro dispatch BoraUm: " . $dispatchErr->getMessage());
                }
            } else {
                // Plano Basico: parceiro entrega com entregador proprio
                $db->prepare("INSERT INTO om_market_order_events (order_id, event_type, message, created_by, created_at) VALUES (?, ?, ?, ?, NOW())")
                    ->execute([$orderId, 'own_delivery', 'Entrega pelo proprio parceiro (Plano Basico)', 'system']);
            }
        }
        // Mercado/supermercado: shopper ja faz a entrega
        // Retirada (pickup): cliente busca no local
    }

    // Feature 9: Notificar cliente sobre mudanca de status
    // Usa queue async (Redis) quando disponivel, fallback para sync
    try {
        require_once __DIR__ . '/../config/queue.php';
        $stmtCust = $db->prepare("SELECT customer_id, customer_phone, order_number FROM om_market_orders WHERE order_id = ?");
        $stmtCust->execute([$orderId]);
        $orderData = $stmtCust->fetch();
        if ($orderData) {
            $notifTitles = [
                'confirmar' => 'Pedido confirmado!',
                'preparando' => 'Seu pedido esta sendo preparado',
                'pronto' => 'Pedido pronto!',
                'cancelar' => 'Pedido cancelado',
            ];
            $notifBodies = [
                'confirmar' => 'O estabelecimento confirmou seu pedido #' . $orderData['order_number'],
                'preparando' => 'Seu pedido #' . $orderData['order_number'] . ' esta sendo preparado',
                'pronto' => 'Seu pedido #' . $orderData['order_number'] . ' esta pronto para retirada/entrega',
                'cancelar' => 'Infelizmente seu pedido #' . $orderData['order_number'] . ' foi cancelado',
            ];

            $title = $notifTitles[$action];
            $body = $notifBodies[$action];
            $notifData = [
                'order_id' => $orderId,
                'order_number' => $orderData['order_number'],
                'status' => $newStatus,
                'url' => '/pedidos?id=' . $orderId,
                'type' => 'order_status_' . $action,
            ];
            $customerId = (int)$orderData['customer_id'];
            $customerPhone = $orderData['customer_phone'];

            $queue = JobQueue::getInstance();
            if ($queue->isAvailable()) {
                // Async: enqueue all notifications (processed by worker in background)
                // In-app notification
                $queue->push('in_app_notification', [
                    'user_id' => $customerId, 'user_type' => 'customer',
                    'title' => $title, 'body' => $body, 'data' => $notifData
                ]);
                // FCM Push
                $queue->push('push_notification', [
                    'user_id' => $customerId, 'user_type' => 'customer',
                    'title' => $title, 'body' => $body, 'data' => $notifData
                ]);
                // WhatsApp
                if ($customerPhone) {
                    $waTypes = ['confirmar' => 'order_accepted', 'preparando' => 'order_preparing',
                                'pronto' => 'order_ready', 'cancelar' => 'order_cancelled'];
                    $queue->push('whatsapp', [
                        'phone' => $customerPhone,
                        'type' => $waTypes[$action],
                        'params' => ['order_number' => $orderData['order_number']]
                    ]);
                }
            } else {
                // Sync fallback: send notifications directly
                require_once __DIR__ . '/../config/notify.php';
                sendNotification($db, $customerId, 'customer', $title, $body, $notifData);

                try {
                    require_once __DIR__ . '/../helpers/NotificationSender.php';
                    NotificationSender::getInstance($db)->notifyCustomer($customerId, $title, $body, $notifData);
                } catch (Exception $fcmErr) {
                    error_log("[order-action] FCM push erro: " . $fcmErr->getMessage());
                }

                if ($customerPhone) {
                    try {
                        require_once __DIR__ . '/../helpers/zapi-whatsapp.php';
                        $on = $orderData['order_number'];
                        switch ($action) {
                            case 'confirmar':  whatsappOrderAccepted($customerPhone, $on); break;
                            case 'preparando': whatsappOrderPreparing($customerPhone, $on); break;
                            case 'pronto':     whatsappOrderReady($customerPhone, $on); break;
                            case 'cancelar':   whatsappOrderCancelled($customerPhone, $on); break;
                        }
                    } catch (Exception $we) {
                        error_log("[order-action] WhatsApp erro: " . $we->getMessage());
                    }
                }
            }
        }
    } catch (Exception $notifErr) {
        error_log("[order-action] Erro notificacao: " . $notifErr->getMessage());
    }

    $finalStatus = $dispatchResult && $dispatchResult['success'] ? 'aguardando_entregador' : $newStatus;

    // Persist aguardando_entregador status if dispatch succeeded
    if ($finalStatus === 'aguardando_entregador') {
        try {
            $db->prepare("UPDATE om_market_orders SET status = 'aguardando_entregador', date_modified = NOW() WHERE order_id = ? AND partner_id = ?")
               ->execute([$orderId, $partnerId]);
        } catch (Exception $persistErr) {
            error_log("[order-action] Erro ao persistir aguardando_entregador para pedido #{$orderId}: " . $persistErr->getMessage());
        }
    }

    // Pusher: notificar parceiro sobre a atualizacao do pedido em tempo real
    try {
        PusherService::orderUpdate($partnerId, [
            'id' => $orderId,
            'order_number' => $orderData['order_number'] ?? null,
            'status' => $finalStatus,
            'old_status' => $currentStatus,
            'action' => $action
        ]);

        // Tambem notificar no canal do pedido (para tracking do cliente)
        PusherService::orderStatus($partnerId, [
            'order_id' => $orderId,
            'status' => $finalStatus,
            'message' => $eventMsg[$action]
        ]);
    } catch (Exception $pusherErr) {
        error_log("[order-action] Pusher erro: " . $pusherErr->getMessage());
    }

    $responseData = [
        "order_id" => $orderId,
        "old_status" => $currentStatus,
        "new_status" => $finalStatus,
        "action" => $action
    ];
    if ($dispatchResult) {
        $responseData['dispatch'] = $dispatchResult;
    }
    response(true, $responseData);

} catch (Exception $e) {
    error_log("[partner/order-action] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar acao", 500);
}
