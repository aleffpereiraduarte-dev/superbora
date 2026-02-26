<?php
/**
 * GET/POST /api/mercado/partner/order-queue.php
 *
 * Kanban Queue Management for Partner Orders
 *
 * GET: Returns orders grouped by status with timing info
 * POST: Move order between statuses with timestamp tracking
 *
 * Body (POST): {
 *   "order_id": 123,
 *   "from_status": "pendente",
 *   "to_status": "preparando",
 *   "prep_time_estimate": 20  // optional, minutes
 * }
 */

require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requirePartner();
    $partnerId = (int)$payload['uid'];

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // GET: Return orders grouped by status
        handleGetQueue($db, $partnerId);
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // POST: Move order between statuses
        handleMoveOrder($db, $partnerId);
    } else {
        response(false, null, "Metodo nao permitido", 405);
    }

} catch (Exception $e) {
    error_log("[partner/order-queue] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}

/**
 * GET: Return orders grouped by kanban columns
 */
function handleGetQueue($db, $partnerId) {
    // Kanban statuses to fetch
    $kanbanStatuses = ['pendente', 'aceito', 'preparando', 'pronto', 'em_entrega'];

    // Also include some recent delivered orders (last 2 hours)
    $sql = "
        SELECT
            o.order_id,
            o.order_number,
            o.customer_id,
            o.customer_name,
            o.customer_phone,
            o.shopper_id,
            o.status,
            o.subtotal,
            o.delivery_fee,
            o.total,
            o.forma_pagamento,
            o.delivery_address,
            o.notes,
            o.is_pickup,
            o.date_added,
            o.date_modified,
            o.accepted_at,
            o.preparing_started_at,
            o.ready_at,
            o.prep_time_estimate,
            o.schedule_date,
            o.schedule_time,
            (SELECT COUNT(*) FROM om_market_order_items WHERE order_id = o.order_id) as total_items,
            (SELECT STRING_AGG(sub.item_desc, ', ')
             FROM (
                SELECT oi.quantity || 'x ' || COALESCE(oi.product_name, oi.name, 'Item') as item_desc
                FROM om_market_order_items oi
                WHERE oi.order_id = o.order_id
                LIMIT 3
             ) sub) as items_summary
        FROM om_market_orders o
        WHERE o.partner_id = ?
          AND (
            o.status IN ('pendente', 'aceito', 'preparando', 'pronto', 'em_entrega')
            OR (o.status = 'entregue' AND o.date_modified >= NOW() - INTERVAL '2 hours')
          )
        ORDER BY
            CASE o.status
                WHEN 'pendente' THEN 1
                WHEN 'aceito' THEN 2
                WHEN 'preparando' THEN 3
                WHEN 'pronto' THEN 4
                WHEN 'em_entrega' THEN 5
                WHEN 'entregue' THEN 6
                ELSE 7
            END,
            o.date_added ASC
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute([$partnerId]);
    $orders = $stmt->fetchAll();

    // Group by status
    $grouped = [
        'pendente' => [],
        'aceito' => [],
        'preparando' => [],
        'pronto' => [],
        'em_entrega' => [],
        'entregue' => []
    ];

    $counts = [
        'pendente' => 0,
        'aceito' => 0,
        'preparando' => 0,
        'pronto' => 0,
        'em_entrega' => 0,
        'entregue' => 0
    ];

    $now = new DateTime();

    foreach ($orders as $order) {
        $status = $order['status'];

        // Map pending -> pendente if needed
        if ($status === 'pending') $status = 'pendente';
        if ($status === 'confirmed') $status = 'aceito';
        if ($status === 'delivering') $status = 'em_entrega';
        if ($status === 'delivered') $status = 'entregue';

        if (!isset($grouped[$status])) continue;

        // Calculate time tracking
        $dateAdded = new DateTime($order['date_added']);
        $waitingMinutes = (int)(($now->getTimestamp() - $dateAdded->getTimestamp()) / 60);

        // Prep time tracking
        $prepStarted = $order['preparing_started_at'] ? new DateTime($order['preparing_started_at']) : null;
        $prepMinutes = $prepStarted ? (int)(($now->getTimestamp() - $prepStarted->getTimestamp()) / 60) : null;
        $prepEstimate = (int)($order['prep_time_estimate'] ?? 20); // default 20 min

        // Urgency calculation
        $urgency = 'normal';
        if ($status === 'pendente') {
            if ($waitingMinutes > 5) $urgency = 'critical';
            elseif ($waitingMinutes > 3) $urgency = 'warning';
        } elseif ($status === 'preparando' && $prepMinutes !== null) {
            if ($prepMinutes > $prepEstimate) $urgency = 'critical';
            elseif ($prepMinutes > ($prepEstimate * 0.75)) $urgency = 'warning';
        } elseif ($status === 'pronto') {
            $readyAt = $order['ready_at'] ? new DateTime($order['ready_at']) : null;
            $waitingReadyMin = $readyAt ? (int)(($now->getTimestamp() - $readyAt->getTimestamp()) / 60) : 0;
            if ($waitingReadyMin > 15) $urgency = 'critical';
            elseif ($waitingReadyMin > 5) $urgency = 'warning';
        }

        $item = [
            'order_id' => (int)$order['order_id'],
            'order_number' => $order['order_number'],
            'customer_id' => (int)$order['customer_id'],
            'customer_name' => $order['customer_name'],
            'customer_phone' => $order['customer_phone'],
            'status' => $status,
            'total' => (float)$order['total'],
            'subtotal' => (float)$order['subtotal'],
            'delivery_fee' => (float)$order['delivery_fee'],
            'payment_method' => $order['forma_pagamento'],
            'is_pickup' => (bool)$order['is_pickup'],
            'total_items' => (int)$order['total_items'],
            'items_summary' => $order['items_summary'],
            'notes' => $order['notes'],
            'address' => $order['delivery_address'],
            'date_added' => $order['date_added'],
            'date_modified' => $order['date_modified'],
            'accepted_at' => $order['accepted_at'],
            'preparing_started_at' => $order['preparing_started_at'],
            'ready_at' => $order['ready_at'],
            'prep_time_estimate' => $prepEstimate,
            'waiting_minutes' => $waitingMinutes,
            'prep_minutes' => $prepMinutes,
            'urgency' => $urgency,
            'schedule_date' => $order['schedule_date'],
            'schedule_time' => $order['schedule_time'],
            'is_scheduled' => !empty($order['schedule_date'])
        ];

        $grouped[$status][] = $item;
        $counts[$status]++;
    }

    // Calculate stats
    $totalActive = $counts['pendente'] + $counts['aceito'] + $counts['preparando'] + $counts['pronto'] + $counts['em_entrega'];

    // Average prep time for today's completed orders
    $stmtAvg = $db->prepare("
        SELECT AVG(EXTRACT(EPOCH FROM (ready_at - preparing_started_at)) / 60) as avg_prep
        FROM om_market_orders
        WHERE partner_id = ?
          AND preparing_started_at IS NOT NULL
          AND ready_at IS NOT NULL
          AND DATE(date_added) = CURRENT_DATE
    ");
    $stmtAvg->execute([$partnerId]);
    $avgRow = $stmtAvg->fetch();
    $avgPrepTime = $avgRow && $avgRow['avg_prep'] ? round((float)$avgRow['avg_prep']) : null;

    response(true, [
        'queue' => $grouped,
        'counts' => $counts,
        'total_active' => $totalActive,
        'avg_prep_time_today' => $avgPrepTime,
        'timestamp' => date('c')
    ], "Fila de pedidos");
}

/**
 * POST: Move order between statuses
 */
function handleMoveOrder($db, $partnerId) {
    $input = getInput();

    $orderId = (int)($input['order_id'] ?? 0);
    $fromStatus = trim($input['from_status'] ?? '');
    $toStatus = trim($input['to_status'] ?? '');
    $prepTimeEstimate = isset($input['prep_time_estimate']) ? (int)$input['prep_time_estimate'] : null;

    if (!$orderId) {
        response(false, null, "order_id e obrigatorio", 400);
    }

    if (!$toStatus) {
        response(false, null, "to_status e obrigatorio", 400);
    }

    // Valid status transitions
    $validStatuses = ['pendente', 'aceito', 'preparando', 'pronto', 'em_entrega', 'entregue', 'cancelado'];
    if (!in_array($toStatus, $validStatuses)) {
        response(false, null, "Status invalido: $toStatus", 400);
    }

    // Use transaction to prevent race conditions
    $db->beginTransaction();

    try {
        // Fetch current order with FOR UPDATE lock
        $stmt = $db->prepare("SELECT order_id, status, partner_categoria FROM om_market_orders WHERE order_id = ? AND partner_id = ? FOR UPDATE");
        $stmt->execute([$orderId, $partnerId]);
        $order = $stmt->fetch();

        if (!$order) {
            $db->rollBack();
            response(false, null, "Pedido nao encontrado", 404);
        }

        $currentStatus = $order['status'];

        // Validate fromStatus if provided
        if ($fromStatus && $currentStatus !== $fromStatus) {
            $db->rollBack();
            response(false, null, "Status atual do pedido mudou. Esperado: $fromStatus, Atual: $currentStatus", 409);
        }

        // Define allowed transitions
        $transitions = [
            'pendente' => ['aceito', 'cancelado'],
            'aceito' => ['preparando', 'cancelado'],
            'preparando' => ['pronto'],
            'pronto' => ['em_entrega', 'entregue'],
            'em_entrega' => ['entregue'],
        ];

        if (!isset($transitions[$currentStatus]) || !in_array($toStatus, $transitions[$currentStatus])) {
            $db->rollBack();
            response(false, null, "Transicao invalida de '$currentStatus' para '$toStatus'", 400);
        }

        // Build update query
        $updates = ["status = ?", "date_modified = NOW()"];
        $params = [$toStatus];

        // Add timestamp fields based on transition
        switch ($toStatus) {
            case 'aceito':
                $updates[] = "accepted_at = NOW()";
                break;
            case 'preparando':
                $updates[] = "preparing_started_at = NOW()";
                if ($prepTimeEstimate !== null && $prepTimeEstimate > 0) {
                    $updates[] = "prep_time_estimate = ?";
                    $params[] = $prepTimeEstimate;
                }
                break;
            case 'pronto':
                $updates[] = "ready_at = NOW()";
                break;
            case 'entregue':
                $updates[] = "completed_at = NOW()";
                break;
            case 'cancelado':
                $updates[] = "completed_at = NOW()";
                break;
        }

        // Include current status in WHERE to prevent race conditions
        $params[] = $orderId;
        $params[] = $partnerId;
        $params[] = $currentStatus;

        $sql = "UPDATE om_market_orders SET " . implode(", ", $updates) . " WHERE order_id = ? AND partner_id = ? AND status = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        if ($stmt->rowCount() === 0) {
            $db->rollBack();
            response(false, null, "Pedido ja foi atualizado por outra acao. Atualize a pagina.", 409);
        }

        $db->commit();
    } catch (Exception $txErr) {
        if ($db->inTransaction()) $db->rollBack();
        throw $txErr;
    }

    // Log event
    $eventMessages = [
        'aceito' => 'Pedido aceito pelo parceiro (Kanban)',
        'preparando' => 'Preparo iniciado (Kanban)',
        'pronto' => 'Pedido pronto (Kanban)',
        'em_entrega' => 'Pedido saiu para entrega',
        'entregue' => 'Pedido entregue',
        'cancelado' => 'Pedido cancelado pelo parceiro (Kanban)',
    ];

    $eventMsg = $eventMessages[$toStatus] ?? "Status alterado para $toStatus";

    $stmt = $db->prepare("INSERT INTO om_market_order_events (order_id, event_type, message, created_by, created_at) VALUES (?, ?, ?, ?, NOW())");
    $stmt->execute([$orderId, "kanban_$toStatus", $eventMsg, "partner:$partnerId"]);

    // Dispatch delivery if needed (when marking as 'pronto')
    $dispatchResult = null;
    if ($toStatus === 'pronto') {
        $categoria = strtolower(trim($order['partner_categoria'] ?? ''));
        $categoriaMercado = in_array($categoria, ['mercado', 'supermercado']);

        if (!$categoriaMercado) {
            try {
                require_once __DIR__ . '/../helpers/delivery.php';
                $stmtOrder = $db->prepare("SELECT * FROM om_market_orders WHERE order_id = ?");
                $stmtOrder->execute([$orderId]);
                $orderData = $stmtOrder->fetch();
                if ($orderData) {
                    $dispatchResult = dispatchToBoraUm($db, $orderData);
                    if ($dispatchResult['success']) {
                        $db->prepare("INSERT INTO om_market_order_events (order_id, event_type, message, created_by, created_at) VALUES (?, ?, ?, ?, NOW())")
                            ->execute([$orderId, 'delivery_dispatched', 'Entregador solicitado (Kanban)', 'system']);
                    }
                }
            } catch (Exception $dispatchErr) {
                error_log("[order-queue] Erro dispatch: " . $dispatchErr->getMessage());
            }
        }
    }

    // Send notifications to customer
    try {
        require_once __DIR__ . '/../config/notify.php';
        $stmtCust = $db->prepare("SELECT customer_id, customer_phone, order_number FROM om_market_orders WHERE order_id = ?");
        $stmtCust->execute([$orderId]);
        $orderData = $stmtCust->fetch();

        if ($orderData) {
            $notifTitles = [
                'aceito' => 'Pedido confirmado!',
                'preparando' => 'Seu pedido esta sendo preparado',
                'pronto' => 'Pedido pronto!',
                'cancelado' => 'Pedido cancelado',
            ];

            $notifBodies = [
                'aceito' => 'O estabelecimento confirmou seu pedido #' . $orderData['order_number'],
                'preparando' => 'Seu pedido #' . $orderData['order_number'] . ' esta sendo preparado',
                'pronto' => 'Seu pedido #' . $orderData['order_number'] . ' esta pronto para retirada/entrega',
                'cancelado' => 'Infelizmente seu pedido #' . $orderData['order_number'] . ' foi cancelado',
            ];

            if (isset($notifTitles[$toStatus])) {
                sendNotification($db, (int)$orderData['customer_id'], 'customer',
                    $notifTitles[$toStatus], $notifBodies[$toStatus],
                    ['order_id' => $orderId, 'status' => $toStatus, 'url' => '/pedidos?id=' . $orderId]
                );

                // FCM Push
                try {
                    require_once __DIR__ . '/../helpers/NotificationSender.php';
                    $notifSender = NotificationSender::getInstance($db);
                    $notifSender->notifyCustomer(
                        (int)$orderData['customer_id'],
                        $notifTitles[$toStatus],
                        $notifBodies[$toStatus],
                        [
                            'order_id' => $orderId,
                            'order_number' => $orderData['order_number'],
                            'status' => $toStatus,
                            'type' => 'order_status_kanban',
                        ]
                    );
                } catch (Exception $fcmErr) {
                    error_log("[order-queue] FCM erro: " . $fcmErr->getMessage());
                }

                // WhatsApp
                $customerPhone = $orderData['customer_phone'];
                if ($customerPhone) {
                    try {
                        require_once __DIR__ . '/../helpers/zapi-whatsapp.php';
                        $on = $orderData['order_number'];
                        switch ($toStatus) {
                            case 'aceito':     whatsappOrderAccepted($customerPhone, $on); break;
                            case 'preparando': whatsappOrderPreparing($customerPhone, $on); break;
                            case 'pronto':     whatsappOrderReady($customerPhone, $on); break;
                            case 'cancelado':  whatsappOrderCancelled($customerPhone, $on); break;
                        }
                    } catch (Exception $we) {
                        error_log("[order-queue] WhatsApp erro: " . $we->getMessage());
                    }
                }
            }
        }
    } catch (Exception $notifErr) {
        error_log("[order-queue] Notificacao erro: " . $notifErr->getMessage());
    }

    $responseData = [
        'order_id' => $orderId,
        'from_status' => $currentStatus,
        'to_status' => $toStatus,
        'timestamp' => date('c')
    ];

    if ($dispatchResult) {
        $responseData['dispatch'] = $dispatchResult;
    }

    response(true, $responseData, "Pedido movido para $toStatus");
}
