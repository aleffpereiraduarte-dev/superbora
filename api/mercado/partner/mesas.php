<?php
/**
 * Waiter-facing table management endpoint
 *
 * GET                            - List all tables with active orders
 * GET ?table_id=X                - Table detail with orders and items
 * POST action=entregar_item      - Mark item(s) as delivered to table
 * POST action=cobrar             - Collect payment for table orders
 * POST action=fechar_mesa        - Close table (clear for next customer)
 *
 * Authenticated: staff (type=staff/team) or partner owner (type=partner)
 */

require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    // Ensure tracking table exists
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS om_market_mesa_item_delivery (
            id SERIAL PRIMARY KEY,
            order_id INTEGER NOT NULL,
            item_id INTEGER NOT NULL,
            delivered_by INTEGER,
            delivered_at TIMESTAMP NOT NULL DEFAULT NOW(),
            UNIQUE(order_id, item_id)
        )");
    } catch (Exception $e) { /* already exists */ }

    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Token ausente", 401);

    $payload = om_auth()->validateToken($token);
    if (!$payload) response(false, null, "Token invalido ou expirado", 401);

    // Accept partner, staff, or team tokens
    $type = $payload['type'] ?? '';
    $partnerId = null;

    if ($type === OmAuth::USER_TYPE_PARTNER) {
        $partnerId = (int)$payload['uid'];
    } elseif ($type === 'staff' || $type === 'team') {
        $partnerId = (int)($payload['data']['partner_id'] ?? 0);
        if (!$partnerId) response(false, null, "Token invalido: partner_id ausente", 401);
    } else {
        response(false, null, "Nao autorizado", 401);
    }

    $method = $_SERVER['REQUEST_METHOD'];

    // ═══════════════════════════════════════════════════════════════════════
    // GET - List tables or table detail
    // ═══════════════════════════════════════════════════════════════════════
    if ($method === 'GET') {
        $tableId = (int)($_GET['table_id'] ?? 0);

        if ($tableId > 0) {
            // ── Table detail ──
            $stmtTable = $db->prepare("
                SELECT id, numero, nome, capacidade, ativo
                FROM om_market_qr_tables
                WHERE id = ? AND partner_id = ?
            ");
            $stmtTable->execute([$tableId, $partnerId]);
            $table = $stmtTable->fetch(PDO::FETCH_ASSOC);

            if (!$table) {
                response(false, null, "Mesa nao encontrada", 404);
            }

            // Active orders for this table
            $stmtOrders = $db->prepare("
                SELECT
                    o.order_id, o.order_number, o.status, o.subtotal, o.total,
                    o.customer_name, o.notes, o.date_added, o.forma_pagamento,
                    o.payment_method, o.accepted_at, o.ready_at
                FROM om_market_orders o
                WHERE o.table_id = ? AND o.partner_id = ?
                  AND o.status IN ('pendente', 'aceito', 'preparando', 'pronto', 'entregue')
                ORDER BY o.date_added ASC
            ");
            $stmtOrders->execute([$tableId, $partnerId]);
            $orders = $stmtOrders->fetchAll(PDO::FETCH_ASSOC);

            $totalBill = 0;
            $allPaid = true;
            $orderList = [];

            // Batch fetch all items for all orders (avoid N+1)
            $orderIds = array_map(fn($o) => (int)$o['order_id'], $orders);
            $itemsByOrder = [];
            if (!empty($orderIds)) {
                $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
                $stmtAllItems = $db->prepare("
                    SELECT
                        oi.order_id,
                        oi.id,
                        oi.product_id,
                        oi.name,
                        oi.quantity,
                        oi.price,
                        oi.observacao,
                        COALESCE(kis.ready, false) as item_ready,
                        COALESCE(
                            (SELECT 1 FROM om_market_mesa_item_delivery mid
                             WHERE mid.order_id = oi.order_id AND mid.item_id = oi.id LIMIT 1),
                            0
                        ) as item_delivered
                    FROM om_market_order_items oi
                    LEFT JOIN om_market_kds_item_status kis ON kis.order_id = oi.order_id AND kis.item_id = oi.id
                    WHERE oi.order_id IN ($placeholders)
                    ORDER BY oi.order_id, oi.id ASC
                ");
                $stmtAllItems->execute($orderIds);
                foreach ($stmtAllItems->fetchAll(PDO::FETCH_ASSOC) as $item) {
                    $itemsByOrder[(int)$item['order_id']][] = $item;
                }
            }

            foreach ($orders as $order) {
                $orderId = (int)$order['order_id'];
                $rawItems = $itemsByOrder[$orderId] ?? [];

                $items = [];
                foreach ($rawItems as $item) {
                    $itemStatus = 'preparando';
                    if ((bool)$item['item_delivered']) {
                        $itemStatus = 'entregue';
                    } elseif ((bool)$item['item_ready']) {
                        $itemStatus = 'pronto';
                    } elseif (in_array($order['status'], ['pendente', 'aceito'])) {
                        $itemStatus = 'pendente';
                    }

                    $items[] = [
                        'id' => (int)$item['id'],
                        'product_id' => (int)$item['product_id'],
                        'name' => $item['name'],
                        'quantity' => (int)$item['quantity'],
                        'price' => (float)$item['price'],
                        'total' => round((float)$item['price'] * (int)$item['quantity'], 2),
                        'observacao' => $item['observacao'] ?? null,
                        'status' => $itemStatus,
                    ];
                }

                $orderTotal = (float)$order['total'];
                $totalBill += $orderTotal;

                $isPaid = in_array($order['status'], ['entregue']);
                if (!$isPaid) $allPaid = false;

                $orderList[] = [
                    'order_id' => $orderId,
                    'order_number' => $order['order_number'],
                    'status' => $order['status'],
                    'total' => $orderTotal,
                    'customer_name' => $order['customer_name'],
                    'notes' => $order['notes'],
                    'date_added' => $order['date_added'],
                    'is_paid' => $isPaid,
                    'items' => $items,
                ];
            }

            // Calculate time since first order
            $firstOrderTime = null;
            $elapsedMinutes = 0;
            if (!empty($orders)) {
                $firstOrderTime = $orders[0]['date_added'];
                if ($firstOrderTime) {
                    $ref = new DateTime($firstOrderTime);
                    $now = new DateTime();
                    $elapsedMinutes = max(0, (int)round(($now->getTimestamp() - $ref->getTimestamp()) / 60));
                }
            }

            // Determine status
            $tableStatus = 'livre';
            if (!empty($orders)) {
                $hasUnpaid = false;
                foreach ($orders as $o) {
                    if (!in_array($o['status'], ['entregue'])) {
                        $hasUnpaid = true;
                        break;
                    }
                }
                $tableStatus = $hasUnpaid ? 'ocupada' : 'aguardando_pagamento';
            }

            response(true, [
                'table' => [
                    'id' => (int)$table['id'],
                    'numero' => (int)$table['numero'],
                    'nome' => $table['nome'],
                    'capacidade' => (int)$table['capacidade'],
                    'status' => $tableStatus,
                ],
                'orders' => $orderList,
                'total_bill' => round($totalBill, 2),
                'all_paid' => $allPaid,
                'elapsed_minutes' => $elapsedMinutes,
                'first_order_time' => $firstOrderTime,
            ]);
        }

        // ── List all tables with summary ──
        $stmtTables = $db->prepare("
            SELECT
                t.id, t.numero, t.nome, t.capacidade, t.ativo,
                COUNT(o.order_id) FILTER (WHERE o.status IN ('pendente','aceito','preparando','pronto')) as active_orders,
                COALESCE(SUM(o.total) FILTER (WHERE o.status IN ('pendente','aceito','preparando','pronto')), 0) as total_value,
                MIN(o.date_added) FILTER (WHERE o.status IN ('pendente','aceito','preparando','pronto')) as first_order_time,
                COUNT(o.order_id) FILTER (WHERE o.status = 'pronto') as ready_orders
            FROM om_market_qr_tables t
            LEFT JOIN om_market_orders o ON o.table_id = t.id AND o.partner_id = t.partner_id
                AND o.status IN ('pendente','aceito','preparando','pronto')
            WHERE t.partner_id = ? AND t.ativo = true
            GROUP BY t.id, t.numero, t.nome, t.capacidade, t.ativo
            ORDER BY t.numero ASC
        ");
        $stmtTables->execute([$partnerId]);
        $tables = $stmtTables->fetchAll(PDO::FETCH_ASSOC);

        $now = new DateTime();
        $tableList = [];
        foreach ($tables as $t) {
            $elapsedMinutes = 0;
            if ($t['first_order_time']) {
                $ref = new DateTime($t['first_order_time']);
                $elapsedMinutes = max(0, (int)round(($now->getTimestamp() - $ref->getTimestamp()) / 60));
            }

            $status = 'livre';
            if ((int)$t['active_orders'] > 0) {
                $status = 'ocupada';
            }

            $tableList[] = [
                'id' => (int)$t['id'],
                'numero' => (int)$t['numero'],
                'nome' => $t['nome'],
                'capacidade' => (int)$t['capacidade'],
                'status' => $status,
                'active_orders' => (int)$t['active_orders'],
                'total_value' => round((float)$t['total_value'], 2),
                'elapsed_minutes' => $elapsedMinutes,
                'first_order_time' => $t['first_order_time'],
                'has_ready_items' => (int)$t['ready_orders'] > 0,
            ];
        }

        response(true, [
            'tables' => $tableList,
            'total_tables' => count($tableList),
            'occupied_tables' => count(array_filter($tableList, fn($t) => $t['status'] !== 'livre')),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // POST - Actions
    // ═══════════════════════════════════════════════════════════════════════
    if ($method === 'POST') {
        $input = getInput();
        $action = trim($input['action'] ?? '');

        if (empty($action)) {
            response(false, null, "action e obrigatoria", 400);
        }

        // ── Entregar item ──
        if ($action === 'entregar_item') {
            $orderId = (int)($input['order_id'] ?? 0);
            $itemId = (int)($input['item_id'] ?? 0);

            if (!$orderId) {
                response(false, null, "order_id e obrigatorio", 400);
            }

            // Verify order belongs to partner
            $stmtOrder = $db->prepare("
                SELECT order_id, table_id FROM om_market_orders
                WHERE order_id = ? AND partner_id = ?
            ");
            $stmtOrder->execute([$orderId, $partnerId]);
            $order = $stmtOrder->fetch(PDO::FETCH_ASSOC);
            if (!$order) {
                response(false, null, "Pedido nao encontrado", 404);
            }

            if ($itemId > 0) {
                // Mark single item as delivered
                $stmtItem = $db->prepare("SELECT id FROM om_market_order_items WHERE id = ? AND order_id = ?");
                $stmtItem->execute([$itemId, $orderId]);
                if (!$stmtItem->fetch()) {
                    response(false, null, "Item nao encontrado", 404);
                }

                $deliveredBy = ($type === 'staff' || $type === 'team') ? (int)$payload['uid'] : null;
                $db->prepare("
                    INSERT INTO om_market_mesa_item_delivery (order_id, item_id, delivered_by, delivered_at)
                    VALUES (?, ?, ?, NOW())
                    ON CONFLICT (order_id, item_id) DO NOTHING
                ")->execute([$orderId, $itemId, $deliveredBy]);

                response(true, ['order_id' => $orderId, 'item_id' => $itemId], "Item marcado como entregue!");
            } else {
                // Mark all items as delivered
                $stmtAllItems = $db->prepare("SELECT id FROM om_market_order_items WHERE order_id = ?");
                $stmtAllItems->execute([$orderId]);
                $allItems = $stmtAllItems->fetchAll(PDO::FETCH_ASSOC);

                $deliveredBy = ($type === 'staff' || $type === 'team') ? (int)$payload['uid'] : null;
                $count = 0;
                foreach ($allItems as $item) {
                    $db->prepare("
                        INSERT INTO om_market_mesa_item_delivery (order_id, item_id, delivered_by, delivered_at)
                        VALUES (?, ?, ?, NOW())
                        ON CONFLICT (order_id, item_id) DO NOTHING
                    ")->execute([$orderId, (int)$item['id'], $deliveredBy]);
                    $count++;
                }

                response(true, ['order_id' => $orderId, 'items_delivered' => $count], "Todos os itens marcados como entregues!");
            }
        }

        // ── Cobrar (collect payment) ──
        if ($action === 'cobrar') {
            $tableId = (int)($input['table_id'] ?? 0);
            $paymentMethod = trim($input['payment_method'] ?? '');
            $amountReceived = (float)($input['amount_received'] ?? 0);

            if (!$tableId) {
                response(false, null, "table_id e obrigatorio", 400);
            }
            if (!in_array($paymentMethod, ['dinheiro', 'cartao', 'pix'])) {
                response(false, null, "payment_method deve ser: dinheiro, cartao ou pix", 400);
            }

            // Verify table belongs to partner
            $stmtTable = $db->prepare("SELECT id FROM om_market_qr_tables WHERE id = ? AND partner_id = ?");
            $stmtTable->execute([$tableId, $partnerId]);
            if (!$stmtTable->fetch()) {
                response(false, null, "Mesa nao encontrada", 404);
            }

            // Get active orders for this table
            $stmtOrders = $db->prepare("
                SELECT order_id, total
                FROM om_market_orders
                WHERE table_id = ? AND partner_id = ?
                  AND status IN ('pendente', 'aceito', 'preparando', 'pronto')
            ");
            $stmtOrders->execute([$tableId, $partnerId]);
            $orders = $stmtOrders->fetchAll(PDO::FETCH_ASSOC);

            if (empty($orders)) {
                response(false, null, "Nenhum pedido ativo nesta mesa", 400);
            }

            $totalBill = 0;
            foreach ($orders as $o) {
                $totalBill += (float)$o['total'];
            }

            $change = 0;
            if ($paymentMethod === 'dinheiro' && $amountReceived > 0) {
                $change = round($amountReceived - $totalBill, 2);
                if ($change < 0) $change = 0;
            }

            // Mark all orders as entregue (paid/completed)
            $db->beginTransaction();
            try {
                foreach ($orders as $o) {
                    $db->prepare("
                        UPDATE om_market_orders
                        SET status = 'entregue',
                            forma_pagamento = ?,
                            delivered_at = NOW(),
                            date_modified = NOW()
                        WHERE order_id = ? AND partner_id = ?
                    ")->execute([$paymentMethod, (int)$o['order_id'], $partnerId]);
                }
                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }

            response(true, [
                'table_id' => $tableId,
                'total_bill' => round($totalBill, 2),
                'payment_method' => $paymentMethod,
                'amount_received' => $amountReceived,
                'change' => $change,
                'orders_paid' => count($orders),
            ], "Pagamento registrado com sucesso!");
        }

        // ── Fechar mesa ──
        if ($action === 'fechar_mesa') {
            $tableId = (int)($input['table_id'] ?? 0);

            if (!$tableId) {
                response(false, null, "table_id e obrigatorio", 400);
            }

            // Verify table belongs to partner
            $stmtTable = $db->prepare("SELECT id FROM om_market_qr_tables WHERE id = ? AND partner_id = ?");
            $stmtTable->execute([$tableId, $partnerId]);
            if (!$stmtTable->fetch()) {
                response(false, null, "Mesa nao encontrada", 404);
            }

            // Check all orders are paid (entregue) or cancelled
            $stmtUnpaid = $db->prepare("
                SELECT COUNT(*) FROM om_market_orders
                WHERE table_id = ? AND partner_id = ?
                  AND status IN ('pendente', 'aceito', 'preparando', 'pronto')
            ");
            $stmtUnpaid->execute([$tableId, $partnerId]);
            $unpaidCount = (int)$stmtUnpaid->fetchColumn();

            if ($unpaidCount > 0) {
                response(false, null, "Existem {$unpaidCount} pedido(s) nao pago(s). Cobre antes de fechar a mesa.", 400);
            }

            // Clear table_id from completed orders (so they don't show up next time)
            $db->prepare("
                UPDATE om_market_orders
                SET table_id = NULL, date_modified = NOW()
                WHERE table_id = ? AND partner_id = ?
                  AND status IN ('entregue', 'cancelado')
            ")->execute([$tableId, $partnerId]);

            // Clean up delivery tracking for this table's orders
            try {
                $db->prepare("
                    DELETE FROM om_market_mesa_item_delivery
                    WHERE order_id IN (
                        SELECT order_id FROM om_market_orders
                        WHERE table_id = ? AND partner_id = ?
                    )
                ")->execute([$tableId, $partnerId]);
            } catch (Exception $e) {
                // Non-critical - table may not exist yet
            }

            response(true, [
                'table_id' => $tableId,
            ], "Mesa fechada com sucesso!");
        }

        response(false, null, "Acao invalida. Use: entregar_item, cobrar, fechar_mesa", 400);
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[partner/mesas] Error: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
