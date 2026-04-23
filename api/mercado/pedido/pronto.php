<?php
/**
 * POST /api/mercado/pedido/pronto.php
 * Marca pedido como "pronto" + auto-chama driver (Feature 5)
 * Body: { "order_id": 10 }
 */
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../helpers/notify.php";
require_once __DIR__ . "/../helpers/delivery.php";
require_once __DIR__ . '/../helpers/ws-customer-broadcast.php';
require_once __DIR__ . '/../helpers/zapi-whatsapp.php';
require_once __DIR__ . '/../helpers/eta-calculator.php';
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, "Método não permitido", 405);
}

// CSRF protection: require JSON content type for session-auth endpoints
$ct = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
if (stripos($ct, 'application/json') === false) {
    response(false, null, "Content-Type deve ser application/json", 400);
}

try {
    session_start();
    session_write_close();
    $db = getDB();

    $mercado_id = $_SESSION['mercado_id'] ?? 0;
    if (!$mercado_id) {
        response(false, null, "Não autorizado", 401);
    }

    $input = getInput();
    $order_id = (int)($input['order_id'] ?? 0);

    if (!$order_id) {
        response(false, null, "order_id obrigatório", 400);
    }

    $db->beginTransaction();
    $stmt = $db->prepare("SELECT * FROM om_market_orders WHERE order_id = ? AND partner_id = ? FOR UPDATE");
    $stmt->execute([$order_id, $mercado_id]);
    $pedido = $stmt->fetch();

    if (!$pedido) {
        $db->rollBack();
        response(false, null, "Pedido não encontrado", 404);
    }

    $statusPermitidos = ['preparando'];
    if (!in_array($pedido['status'], $statusPermitidos)) {
        $db->rollBack();
        if ($pedido['status'] === 'aceito' || $pedido['status'] === 'pendente') {
            response(false, null, "Marque o pedido como 'preparando' antes de finalizar", 409);
        }
        response(false, null, "Pedido não pode ser marcado como pronto (status atual: {$pedido['status']})", 409);
    }

    // Feature 5: Determine delivery_type based on partner options (inside transaction)
    $stmt = $db->prepare("SELECT categoria, aceita_boraum, entrega_propria, aceita_retirada FROM om_market_partners WHERE partner_id = ?");
    $stmt->execute([$mercado_id]);
    $parceiro = $stmt->fetch();

    $categoria = $pedido['partner_categoria'] ?? $parceiro['categoria'] ?? 'mercado';
    $aceitaBoraum = (bool)($parceiro['aceita_boraum'] ?? true);
    $entregaPropria = (bool)($parceiro['entrega_propria'] ?? false);
    $isPickup = (bool)($pedido['is_pickup'] ?? false);
    $categorias_mercado = ['mercado', 'supermercado'];

    // Determine delivery_type to set atomically with status change
    $deliveryType = null;
    if ($isPickup) {
        $deliveryType = 'retirada';
    } elseif ($entregaPropria && !$aceitaBoraum) {
        $deliveryType = 'proprio';
    } elseif ($aceitaBoraum) {
        $deliveryType = 'boraum';
    }

    // Marcar como pronto + set delivery_type atomically
    if ($deliveryType) {
        $stmt = $db->prepare("
            UPDATE om_market_orders SET
                status = 'pronto',
                ready_at = NOW(),
                delivery_type = ?,
                date_modified = NOW()
            WHERE order_id = ? AND status = 'preparando'
        ");
        $stmt->execute([$deliveryType, $order_id]);
    } else {
        $stmt = $db->prepare("
            UPDATE om_market_orders SET
                status = 'pronto',
                ready_at = NOW(),
                date_modified = NOW()
            WHERE order_id = ? AND status = 'preparando'
        ");
        $stmt->execute([$order_id]);
    }
    if ($stmt->rowCount() === 0) {
        $db->rollBack();
        response(false, null, "Pedido já foi alterado por outra sessão (status atual: {$pedido['status']})", 409);
    }
    $db->commit();

    // WebSocket broadcast (never breaks the flow)
    try {
        $customer_id_ws = (int)($pedido['customer_id'] ?? 0);
        if ($customer_id_ws) {
            wsBroadcastToCustomer($customer_id_ws, 'order_update', [
                'order_id' => $order_id,
                'status' => 'pronto',
                'previous_status' => $pedido['status'],
                'is_pickup' => $isPickup,
            ]);
        }
        wsBroadcastToOrder($order_id, 'order_update', [
            'order_id' => $order_id,
            'status' => 'pronto',
            'is_pickup' => $isPickup,
        ]);
        $pid_ws = (int)($pedido['partner_id'] ?? 0);
        if ($pid_ws && function_exists('wsBroadcastToPartner')) {
            wsBroadcastToPartner($pid_ws, 'order_update', [
                'order_id' => $order_id, 'status' => 'pronto',
                'previous_status' => $pedido['status'], 'customer_id' => $customer_id_ws,
                'is_pickup' => $isPickup,
            ]);
        }
        if (function_exists('wsBroadcastToAdmin')) {
            wsBroadcastToAdmin('order_update', [
                'order_id' => $order_id, 'partner_id' => $pid_ws, 'status' => 'pronto',
                'previous_status' => $pedido['status'], 'customer_id' => $customer_id_ws,
                'is_pickup' => $isPickup,
            ]);
        }
    } catch (\Throwable $e) {}

    // Post-commit: Notificar cliente (mensagem diferente para pickup vs entrega)
    $customer_id = (int)($pedido['customer_id'] ?? 0);
    if ($customer_id) {
        if ($isPickup) {
            notifyCustomer($db, $customer_id,
                'Pedido pronto para retirada!',
                "Seu pedido #{$pedido['order_number']} esta pronto! Dirija-se ao estabelecimento para retirar.",
                '/mercado/pedido.php?id=' . $order_id
            );
        } else {
            notifyCustomer($db, $customer_id,
                'Pedido pronto!',
                "Seu pedido #{$pedido['order_number']} esta pronto! Estamos chamando um entregador.",
                '/mercado/pedido.php?id=' . $order_id
            );
        }
    }

    // WhatsApp notification with delivery ETA (never breaks the flow)
    try {
        $customerPhone = $pedido['customer_phone'] ?? '';
        if ($customerPhone) {
            // Calculate delivery-only ETA (order is ready, only delivery time remains)
            $deliveryMinutes = 0;
            try {
                $distKm = isset($pedido['distancia_km']) ? (float)$pedido['distancia_km'] : 5.0;
                $deliveryMinutes = calculateSmartETA($db, $mercado_id, $distKm, 'pronto');
            } catch (\Throwable $etaErr) {
                error_log("[pronto] ETA calc error: " . $etaErr->getMessage());
                // Fallback: use distance * 4 min/km, min 10
                $distKm = isset($pedido['distancia_km']) ? (float)$pedido['distancia_km'] : 5.0;
                $deliveryMinutes = max(10, (int)round($distKm * 4));
            }

            // Get partner name
            $partnerName = $pedido['partner_name'] ?? '';
            if (!$partnerName) {
                $partnerName = $parceiro['trade_name'] ?? $parceiro['name'] ?? '';
            }

            $waResult = whatsappOrderReady($customerPhone, $pedido['order_number'], $partnerName, $deliveryMinutes, $isPickup);
            error_log("[pronto] WhatsApp pedido #{$pedido['order_number']} phone=****" . substr($customerPhone, -4) . " delivery={$deliveryMinutes}min pickup=" . ($isPickup ? 'yes' : 'no') . " success=" . ($waResult['success'] ? 'yes' : 'no'));
        }
    } catch (\Throwable $waErr) {
        error_log("[pronto] WhatsApp error: " . $waErr->getMessage());
    }

    // Proactive WhatsApp: log to conversation (message already sent above)
    try {
        require_once __DIR__ . '/../helpers/whatsapp-order-updates.php';
        sendOrderStatusWhatsApp($db, $order_id, 'pronto', true);
    } catch (\Throwable $e) {
        error_log("[pronto] Proactive WA update error: " . $e->getMessage());
    }

    // Post-commit: Auto-dispatch BoraUm (external API call, must be outside transaction)
    $entrega = null;
    $routeId = (int)($pedido['route_id'] ?? 0);

    if ($isPickup) {
        error_log("[pronto] Pedido #$order_id e retirada - sem dispatch");
    } elseif ($entregaPropria && !$aceitaBoraum) {
        error_log("[pronto] Pedido #$order_id usa entrega propria do parceiro");
    } elseif ($aceitaBoraum && $routeId) {
        // ============================================================
        // ROUTE-AWARE DISPATCH: Check if all route orders are ready
        // ============================================================
        error_log("[pronto] Pedido #$order_id pertence a rota #$routeId - verificando se todos os pedidos estao prontos");

        if (areAllRouteOrdersReady($db, $routeId)) {
            // All orders in route are ready - dispatch the full route
            error_log("[pronto] Rota #$routeId: TODOS os pedidos prontos - despachando rota completa");
            $entrega = dispatchRouteToBoraUm($db, $routeId);
            error_log("[pronto] Route dispatch rota #$routeId | Resultado: " . json_encode($entrega));

            // Notify all customers in the route via WebSocket
            try {
                $stmtRouteOrders = $db->prepare("
                    SELECT order_id, customer_id FROM om_market_orders
                    WHERE route_id = ? AND status NOT IN ('cancelado', 'cancelled', 'refunded')
                ");
                $stmtRouteOrders->execute([$routeId]);
                $routeOrders = $stmtRouteOrders->fetchAll(PDO::FETCH_ASSOC);
                foreach ($routeOrders as $ro) {
                    if ((int)$ro['order_id'] !== $order_id && (int)$ro['customer_id']) {
                        wsBroadcastToCustomer((int)$ro['customer_id'], 'order_update', [
                            'order_id' => (int)$ro['order_id'],
                            'status' => 'aguardando_entregador',
                            'route_id' => $routeId,
                            'route_dispatched' => true,
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                error_log("[pronto] Route WS broadcast error: " . $e->getMessage());
            }
        } else {
            // Not all orders ready yet - just wait
            error_log("[pronto] Rota #$routeId: ainda aguardando outros pedidos ficarem prontos - sem dispatch");
        }
    } elseif ($aceitaBoraum) {
        // Single order dispatch (no route)
        $entrega = dispatchToBoraUm($db, $pedido);
        error_log("[pronto] Auto-dispatch BoraUm para pedido #$order_id | Categoria: $categoria | Resultado: " . json_encode($entrega));
    } else {
        error_log("[pronto] Pedido #$order_id sem dispatch automatico (propria=$entregaPropria, boraum=$aceitaBoraum)");
    }

    $responseData = [
        "order_id" => $order_id,
        "status" => "pronto",
        "ready_at" => date('c'),
        "entrega" => $entrega,
    ];
    if ($routeId) {
        $responseData['route_id'] = $routeId;
        $responseData['route_all_ready'] = $routeId ? areAllRouteOrdersReady($db, $routeId) : false;
    }
    $msg = "Pedido pronto!";
    if ($entrega && !empty($entrega['success']) && !empty($entrega['boraum_dispatched'])) {
        $msg .= $routeId ? " Rota despachada para entregador." : " Entregador sendo chamado.";
    } elseif ($entrega && !empty($entrega['success'])) {
        $msg .= " Entregador sendo chamado.";
    } elseif ($routeId && !areAllRouteOrdersReady($db, $routeId)) {
        $msg .= " Aguardando outros pedidos da rota ficarem prontos.";
    } elseif ($aceitaBoraum && !$isPickup && !($entregaPropria && !$aceitaBoraum)) {
        $responseData['delivery_dispatch'] = 'failed';
        $msg .= " Aviso: falha ao chamar entregador. Tente despachar manualmente.";
        error_log("[pronto] WARN: BoraUm dispatch failed for order #$order_id");
    }

    response(true, $responseData, $msg);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("[pronto] Erro: " . $e->getMessage());
    response(false, null, "Erro ao atualizar pedido", 500);
}
