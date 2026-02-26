<?php
/**
 * KDS (Kitchen Display System) API
 *
 * GET              - Active orders for kitchen display (status IN aceito, preparando)
 * GET action=stats - KDS performance stats
 * POST action=start_preparing - Mark order as "preparando"
 * POST action=item_ready      - Mark individual item as ready
 * POST action=order_ready     - Mark entire order as "pronto"
 * POST action=bump            - Bump/dismiss order from KDS
 */

require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Token ausente", 401);

    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== OmAuth::USER_TYPE_PARTNER) {
        response(false, null, "Nao autorizado", 401);
    }

    $partnerId = (int)$payload['uid'];

    // Tables om_market_kds_item_status and om_market_kds_bumps created via migration

    if ($_SERVER["REQUEST_METHOD"] === "GET") {
        $action = trim($_GET['action'] ?? '');

        if ($action === 'stats') {
            // KDS performance stats
            // Avg prep time today (from accepted_at to ready_at)
            $stmtAvg = $db->prepare("
                SELECT
                    COALESCE(
                        ROUND(AVG(EXTRACT(EPOCH FROM (ready_at - COALESCE(preparing_started_at, accepted_at))) / 60)::numeric, 1),
                        0
                    ) as avg_prep_time,
                    COUNT(*) as completed_today
                FROM om_market_orders
                WHERE partner_id = ?
                  AND DATE(ready_at) = CURRENT_DATE
                  AND ready_at IS NOT NULL
                  AND (preparing_started_at IS NOT NULL OR accepted_at IS NOT NULL)
            ");
            $stmtAvg->execute([$partnerId]);
            $statsRow = $stmtAvg->fetch();

            // Current queue size
            $stmtQueue = $db->prepare("
                SELECT COUNT(*) as queue_size
                FROM om_market_orders
                WHERE partner_id = ?
                  AND status IN ('aceito', 'preparando')
            ");
            $stmtQueue->execute([$partnerId]);
            $queueRow = $stmtQueue->fetch();

            // Orders by hour today
            $stmtHourly = $db->prepare("
                SELECT
                    EXTRACT(HOUR FROM ready_at) as hour,
                    COUNT(*) as count
                FROM om_market_orders
                WHERE partner_id = ?
                  AND DATE(ready_at) = CURRENT_DATE
                  AND ready_at IS NOT NULL
                GROUP BY EXTRACT(HOUR FROM ready_at)
                ORDER BY hour
            ");
            $stmtHourly->execute([$partnerId]);
            $hourlyData = $stmtHourly->fetchAll();

            // Slowest items today
            $stmtSlow = $db->prepare("
                SELECT
                    oi.name as item_name,
                    COUNT(*) as times_ordered,
                    ROUND(AVG(EXTRACT(EPOCH FROM (o.ready_at - COALESCE(o.preparing_started_at, o.accepted_at))) / 60)::numeric, 1) as avg_time
                FROM om_market_order_items oi
                JOIN om_market_orders o ON o.order_id = oi.order_id
                WHERE o.partner_id = ?
                  AND DATE(o.ready_at) = CURRENT_DATE
                  AND o.ready_at IS NOT NULL
                GROUP BY oi.name
                HAVING COUNT(*) >= 2
                ORDER BY avg_time DESC
                LIMIT 5
            ");
            $stmtSlow->execute([$partnerId]);
            $slowItems = $stmtSlow->fetchAll();

            response(true, [
                "avg_prep_time" => (float)($statsRow['avg_prep_time'] ?? 0),
                "completed_today" => (int)($statsRow['completed_today'] ?? 0),
                "queue_size" => (int)($queueRow['queue_size'] ?? 0),
                "hourly" => $hourlyData,
                "slow_items" => $slowItems
            ], "KDS stats");
        }

        // Default GET: active orders for KDS
        $stmtOrders = $db->prepare("
            SELECT
                o.order_id,
                o.order_number,
                o.customer_name,
                o.status,
                o.total,
                o.subtotal,
                o.notes,
                o.is_pickup,
                o.date_added,
                o.accepted_at,
                o.preparing_started_at,
                o.ready_at,
                o.date_modified,
                o.schedule_date,
                o.schedule_time,
                o.tempo_estimado_min as tempo_preparo,
                o.delivery_address,
                qt.numero as table_number,
                qt.nome as table_name
            FROM om_market_orders o
            LEFT JOIN om_market_qr_tables qt ON qt.id = o.table_id AND qt.partner_id = o.partner_id
            WHERE o.partner_id = ?
              AND o.status IN ('aceito', 'preparando')
              AND NOT EXISTS (
                  SELECT 1 FROM om_market_kds_bumps b
                  WHERE b.order_id = o.order_id AND b.partner_id = o.partner_id
              )
            ORDER BY
                CASE WHEN o.status = 'aceito' THEN 0 ELSE 1 END,
                o.accepted_at ASC NULLS LAST,
                o.date_added ASC
        ");
        $stmtOrders->execute([$partnerId]);
        $orders = $stmtOrders->fetchAll();

        $result = [];
        foreach ($orders as $order) {
            $orderId = (int)$order['order_id'];

            // Fetch items for this order
            $stmtItems = $db->prepare("
                SELECT
                    oi.id,
                    oi.product_id,
                    oi.name,
                    oi.quantity,
                    oi.price,
                    oi.observacao,
                    COALESCE(kis.ready, 0) as item_ready,
                    kis.ready_at as item_ready_at
                FROM om_market_order_items oi
                LEFT JOIN om_market_kds_item_status kis ON kis.order_id = oi.order_id AND kis.item_id = oi.id
                WHERE oi.order_id = ?
                ORDER BY oi.id ASC
            ");
            $stmtItems->execute([$orderId]);
            $rawItems = $stmtItems->fetchAll();

            $items = [];
            $totalItems = 0;
            $readyItems = 0;
            foreach ($rawItems as $item) {
                $isReady = (bool)$item['item_ready'];
                if ($isReady) $readyItems++;
                $totalItems++;
                $items[] = [
                    "id" => (int)$item['id'],
                    "product_id" => (int)$item['product_id'],
                    "name" => $item['name'],
                    "quantity" => (int)$item['quantity'],
                    "price" => (float)$item['price'],
                    "observacao" => $item['observacao'] ?? null,
                    "ready" => $isReady,
                    "ready_at" => $item['item_ready_at']
                ];
            }

            // Calculate time elapsed
            $referenceTime = $order['preparing_started_at'] ?? $order['accepted_at'] ?? $order['date_added'];
            $elapsedMinutes = 0;
            if ($referenceTime) {
                $ref = new DateTime($referenceTime);
                $now = new DateTime();
                $elapsedMinutes = (int)round(($now->getTimestamp() - $ref->getTimestamp()) / 60);
            }

            // Determine estimated prep time (default 20 min)
            $estimatedTime = (int)($order['tempo_preparo'] ?? 20);
            $isPriority = $elapsedMinutes > $estimatedTime;

            // Determine order type
            $orderType = 'delivery';
            if ($order['is_pickup']) {
                $orderType = 'pickup';
            }
            if ($order['table_number']) {
                $orderType = 'table';
            }

            $result[] = [
                "order_id" => $orderId,
                "order_number" => $order['order_number'],
                "customer_name" => $order['customer_name'],
                "status" => $order['status'],
                "total" => (float)$order['total'],
                "notes" => $order['notes'],
                "order_type" => $orderType,
                "table_number" => $order['table_number'] ? (int)$order['table_number'] : null,
                "table_name" => $order['table_name'],
                "is_pickup" => (bool)$order['is_pickup'],
                "items" => $items,
                "total_items" => $totalItems,
                "ready_items" => $readyItems,
                "elapsed_minutes" => $elapsedMinutes,
                "estimated_time" => $estimatedTime,
                "is_priority" => $isPriority,
                "accepted_at" => $order['accepted_at'],
                "preparing_started_at" => $order['preparing_started_at'],
                "date_added" => $order['date_added'],
                "schedule_date" => $order['schedule_date'],
                "schedule_time" => $order['schedule_time']
            ];
        }

        // Sort: priority first, then oldest
        usort($result, function($a, $b) {
            if ($a['is_priority'] && !$b['is_priority']) return -1;
            if (!$a['is_priority'] && $b['is_priority']) return 1;
            // Then by accepted_at ascending (oldest first)
            $aTime = $a['accepted_at'] ?? $a['date_added'];
            $bTime = $b['accepted_at'] ?? $b['date_added'];
            return strcmp($aTime, $bTime);
        });

        response(true, [
            "orders" => $result,
            "count" => count($result)
        ], "KDS orders");
    }

    // POST actions
    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        $input = getInput();
        $action = trim($input['action'] ?? '');
        $orderId = (int)($input['order_id'] ?? 0);

        if (!$action) {
            response(false, null, "action e obrigatoria", 400);
        }
        if (!$orderId) {
            response(false, null, "order_id e obrigatorio", 400);
        }

        // Validate order belongs to partner
        $stmtOrder = $db->prepare("SELECT order_id, status FROM om_market_orders WHERE order_id = ? AND partner_id = ?");
        $stmtOrder->execute([$orderId, $partnerId]);
        $order = $stmtOrder->fetch();

        if (!$order) {
            response(false, null, "Pedido nao encontrado", 404);
        }

        switch ($action) {
            case 'start_preparing':
                if ($order['status'] !== 'aceito') {
                    response(false, null, "Pedido precisa estar com status 'aceito' para iniciar preparo", 400);
                }
                $stmt = $db->prepare("
                    UPDATE om_market_orders
                    SET status = 'preparando', preparing_started_at = NOW(), updated_at = NOW(), date_modified = NOW()
                    WHERE order_id = ? AND partner_id = ?
                ");
                $stmt->execute([$orderId, $partnerId]);

                // Log event
                $db->prepare("INSERT INTO om_market_order_events (order_id, event_type, message, created_by, created_at) VALUES (?, ?, ?, ?, NOW())")
                    ->execute([$orderId, 'kds_start_preparing', 'Preparo iniciado via KDS', "partner:$partnerId"]);

                response(true, ["order_id" => $orderId, "new_status" => "preparando"], "Preparo iniciado");
                break;

            case 'item_ready':
                $itemId = (int)($input['item_id'] ?? 0);
                if (!$itemId) {
                    response(false, null, "item_id e obrigatorio", 400);
                }

                // Validate item belongs to this order
                $stmtItem = $db->prepare("SELECT id FROM om_market_order_items WHERE id = ? AND order_id = ?");
                $stmtItem->execute([$itemId, $orderId]);
                if (!$stmtItem->fetch()) {
                    response(false, null, "Item nao encontrado neste pedido", 404);
                }

                // Toggle ready status (upsert)
                $stmtCheck = $db->prepare("SELECT ready FROM om_market_kds_item_status WHERE order_id = ? AND item_id = ?");
                $stmtCheck->execute([$orderId, $itemId]);
                $existing = $stmtCheck->fetch();

                if ($existing) {
                    $newReady = !((bool)$existing['ready']);
                    $stmt = $db->prepare("
                        UPDATE om_market_kds_item_status
                        SET ready = ?, ready_at = CASE WHEN ? THEN NOW() ELSE NULL END
                        WHERE order_id = ? AND item_id = ?
                    ");
                    $stmt->execute([$newReady, $newReady, $orderId, $itemId]);
                } else {
                    $newReady = true;
                    $stmt = $db->prepare("
                        INSERT INTO om_market_kds_item_status (order_id, item_id, ready, ready_at)
                        VALUES (?, ?, 1, NOW())
                    ");
                    $stmt->execute([$orderId, $itemId]);
                }

                // Check if all items are ready
                $stmtTotal = $db->prepare("SELECT COUNT(*) as total FROM om_market_order_items WHERE order_id = ?");
                $stmtTotal->execute([$orderId]);
                $totalCount = (int)$stmtTotal->fetchColumn();

                $stmtReady = $db->prepare("SELECT COUNT(*) as ready FROM om_market_kds_item_status WHERE order_id = ? AND ready = 1");
                $stmtReady->execute([$orderId]);
                $readyCount = (int)$stmtReady->fetchColumn();

                response(true, [
                    "order_id" => $orderId,
                    "item_id" => $itemId,
                    "ready" => $newReady,
                    "total_items" => $totalCount,
                    "ready_items" => $readyCount,
                    "all_ready" => $readyCount >= $totalCount
                ], $newReady ? "Item marcado como pronto" : "Item desmarcado");
                break;

            case 'order_ready':
                if (!in_array($order['status'], ['aceito', 'preparando'])) {
                    response(false, null, "Pedido precisa estar em preparo para marcar como pronto", 400);
                }

                $stmt = $db->prepare("
                    UPDATE om_market_orders
                    SET status = 'pronto', ready_at = NOW(), updated_at = NOW(), date_modified = NOW()
                    WHERE order_id = ? AND partner_id = ?
                ");
                $stmt->execute([$orderId, $partnerId]);

                // Mark all items as ready
                $stmtAllItems = $db->prepare("SELECT id FROM om_market_order_items WHERE order_id = ?");
                $stmtAllItems->execute([$orderId]);
                $allItems = $stmtAllItems->fetchAll();
                foreach ($allItems as $item) {
                    $db->prepare("
                        INSERT INTO om_market_kds_item_status (order_id, item_id, ready, ready_at)
                        VALUES (?, ?, 1, NOW())
                        ON CONFLICT (order_id, item_id) DO UPDATE SET ready = 1, ready_at = NOW()
                    ")->execute([$orderId, (int)$item['id']]);
                }

                // Log event
                $db->prepare("INSERT INTO om_market_order_events (order_id, event_type, message, created_by, created_at) VALUES (?, ?, ?, ?, NOW())")
                    ->execute([$orderId, 'kds_order_ready', 'Pedido marcado como pronto via KDS', "partner:$partnerId"]);

                // Notify customer
                try {
                    require_once __DIR__ . '/../config/notify.php';
                    $stmtCust = $db->prepare("SELECT customer_id, customer_phone, order_number FROM om_market_orders WHERE order_id = ?");
                    $stmtCust->execute([$orderId]);
                    $orderData = $stmtCust->fetch();
                    if ($orderData) {
                        sendNotification($db, (int)$orderData['customer_id'], 'customer',
                            'Pedido pronto!',
                            'Seu pedido #' . $orderData['order_number'] . ' esta pronto para retirada/entrega',
                            ['order_id' => $orderId, 'status' => 'pronto', 'url' => '/pedidos?id=' . $orderId]
                        );
                    }
                } catch (Exception $e) {
                    error_log("[kds] Erro notificacao: " . $e->getMessage());
                }

                // Pusher notification
                try {
                    require_once dirname(__DIR__, 3) . "/includes/classes/PusherService.php";
                    PusherService::orderUpdate($partnerId, [
                        'id' => $orderId,
                        'status' => 'pronto',
                        'old_status' => $order['status'],
                        'action' => 'pronto'
                    ]);
                    PusherService::orderStatusUpdate($orderId, 'pronto', 'Pedido pronto via KDS');
                } catch (Exception $e) {
                    error_log("[kds] Pusher erro: " . $e->getMessage());
                }

                response(true, ["order_id" => $orderId, "new_status" => "pronto"], "Pedido marcado como pronto");
                break;

            case 'bump':
                // Bump = dismiss from KDS screen (order stays with current status)
                $stmt = $db->prepare("
                    INSERT INTO om_market_kds_bumps (order_id, partner_id, bumped_at)
                    VALUES (?, ?, NOW())
                    ON CONFLICT (order_id, partner_id) DO UPDATE SET bumped_at = NOW()
                ");
                $stmt->execute([$orderId, $partnerId]);

                response(true, ["order_id" => $orderId, "bumped" => true], "Pedido removido do KDS");
                break;

            default:
                response(false, null, "Acao invalida. Use: start_preparing, item_ready, order_ready, bump", 400);
        }
    }

    // Method not allowed
    if (!in_array($_SERVER["REQUEST_METHOD"], ['GET', 'POST'])) {
        response(false, null, "Metodo nao permitido", 405);
    }

} catch (Exception $e) {
    error_log("[partner/kds] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
