<?php
/**
 * /api/mercado/mesa/fechar-mesa.php
 *
 * Partner-authenticated endpoint for waiters to manage table orders.
 *
 * GET  ?table_id=X          - List active orders for a table with totals
 * POST action=mark_delivered - Mark order items as delivered to table
 * POST action=cobrar        - Mark order as paid (waiter collected payment)
 * POST action=fechar         - Close table (all orders paid, clear table)
 *
 * Auth: OmAuth JWT partner type
 */

require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $payload = om_auth()->requirePartner();
    $partnerId = (int)$payload['uid'];

    $method = $_SERVER["REQUEST_METHOD"];

    // ===== GET: List active orders for a table =====
    if ($method === 'GET') {
        $tableId = (int)($_GET['table_id'] ?? 0);
        if ($tableId <= 0) {
            response(false, null, "table_id e obrigatorio", 400);
        }

        // Verify table belongs to this partner
        $stmtTable = dbQuery($db,
            "SELECT id, numero, nome FROM om_market_qr_tables WHERE id = ? AND partner_id = ? AND ativo = true LIMIT 1",
            [$tableId, $partnerId]
        );
        $table = $stmtTable->fetch();
        if (!$table) {
            response(false, null, "Mesa nao encontrada", 404);
        }

        // Fetch active orders for this table (not cancelled, not closed)
        $stmtOrders = dbQuery($db,
            "SELECT o.order_id, o.order_number, o.status, o.subtotal, o.total,
                    o.forma_pagamento, o.payment_status, o.customer_name, o.date_added,
                    o.pix_code, o.notes
             FROM om_market_orders o
             WHERE o.table_id = ? AND o.partner_id = ?
               AND o.status NOT IN ('cancelado', 'recusado')
               AND o.date_added >= NOW() - INTERVAL '24 hours'
             ORDER BY o.date_added DESC",
            [$tableId, $partnerId]
        );
        $orders = $stmtOrders->fetchAll();

        $totalMesa = 0;
        $totalPago = 0;
        $orderList = [];

        foreach ($orders as $o) {
            $oTotal = round((float)$o['total'], 2);
            $isPaid = in_array($o['payment_status'], ['paid', 'pago'], true);
            $totalMesa += $oTotal;
            if ($isPaid) $totalPago += $oTotal;

            // Fetch items for each order
            $stmtItems = dbQuery($db,
                "SELECT name, quantity, price, total FROM om_market_order_items WHERE order_id = ? ORDER BY id",
                [(int)$o['order_id']]
            );
            $items = $stmtItems->fetchAll();

            $orderList[] = [
                'order_id' => (int)$o['order_id'],
                'order_number' => $o['order_number'],
                'status' => $o['status'],
                'total' => $oTotal,
                'payment_method' => $o['forma_pagamento'],
                'paid' => $isPaid,
                'customer_name' => $o['customer_name'],
                'created_at' => $o['date_added'],
                'notes' => $o['notes'],
                'items' => array_map(function($item) {
                    return [
                        'name' => $item['name'],
                        'quantity' => (int)$item['quantity'],
                        'price' => round((float)$item['price'], 2),
                        'total' => round((float)$item['total'], 2),
                    ];
                }, $items),
            ];
        }

        response(true, [
            'table' => [
                'id' => (int)$table['id'],
                'numero' => (int)$table['numero'],
                'nome' => $table['nome'],
            ],
            'orders' => $orderList,
            'total_mesa' => round($totalMesa, 2),
            'total_pago' => round($totalPago, 2),
            'total_pendente' => round($totalMesa - $totalPago, 2),
            'orders_count' => count($orderList),
        ], "Pedidos da mesa");
    }

    // ===== POST: Actions =====
    elseif ($method === 'POST') {
        $input = getInput();
        $action = trim($input['action'] ?? '');

        if (!in_array($action, ['mark_delivered', 'cobrar', 'fechar'], true)) {
            response(false, null, "action invalida. Use: mark_delivered, cobrar, fechar", 400);
        }

        // --- mark_delivered: Mark order as delivered to table ---
        if ($action === 'mark_delivered') {
            $orderId = (int)($input['order_id'] ?? 0);
            if ($orderId <= 0) {
                response(false, null, "order_id e obrigatorio", 400);
            }

            $db->beginTransaction();
            try {
                $stmt = dbQuery($db,
                    "SELECT order_id, status, table_id FROM om_market_orders
                     WHERE order_id = ? AND partner_id = ? FOR UPDATE",
                    [$orderId, $partnerId]
                );
                $order = $stmt->fetch();

                if (!$order) {
                    $db->rollBack();
                    response(false, null, "Pedido nao encontrado", 404);
                }

                // Can only mark as delivered if preparing or accepted
                if (!in_array($order['status'], ['aceito', 'preparando', 'pronto'], true)) {
                    $db->rollBack();
                    response(false, null, "Pedido nao pode ser marcado como entregue (status: {$order['status']})", 400);
                }

                dbQuery($db,
                    "UPDATE om_market_orders SET status = 'entregue', date_modified = NOW() WHERE order_id = ?",
                    [$orderId]
                );

                $db->commit();
                response(true, ['order_id' => $orderId, 'status' => 'entregue'], "Pedido marcado como entregue na mesa");

            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                throw $e;
            }
        }

        // --- cobrar: Mark order as paid (waiter collected payment) ---
        elseif ($action === 'cobrar') {
            $orderId = (int)($input['order_id'] ?? 0);
            if ($orderId <= 0) {
                response(false, null, "order_id e obrigatorio", 400);
            }

            $db->beginTransaction();
            try {
                $stmt = dbQuery($db,
                    "SELECT order_id, status, payment_status, table_id FROM om_market_orders
                     WHERE order_id = ? AND partner_id = ? FOR UPDATE",
                    [$orderId, $partnerId]
                );
                $order = $stmt->fetch();

                if (!$order) {
                    $db->rollBack();
                    response(false, null, "Pedido nao encontrado", 404);
                }

                if (in_array($order['payment_status'], ['paid', 'pago'], true)) {
                    $db->rollBack();
                    response(true, ['order_id' => $orderId, 'already_paid' => true], "Pedido ja estava pago");
                }

                dbQuery($db,
                    "UPDATE om_market_orders SET payment_status = 'paid', date_modified = NOW() WHERE order_id = ?",
                    [$orderId]
                );

                $db->commit();
                response(true, ['order_id' => $orderId, 'paid' => true], "Pagamento registrado");

            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                throw $e;
            }
        }

        // --- fechar: Close table (all orders must be paid) ---
        elseif ($action === 'fechar') {
            $tableId = (int)($input['table_id'] ?? 0);
            if ($tableId <= 0) {
                response(false, null, "table_id e obrigatorio", 400);
            }

            // Verify table belongs to this partner
            $stmtTable = dbQuery($db,
                "SELECT id, numero FROM om_market_qr_tables WHERE id = ? AND partner_id = ? AND ativo = true LIMIT 1",
                [$tableId, $partnerId]
            );
            $table = $stmtTable->fetch();
            if (!$table) {
                response(false, null, "Mesa nao encontrada", 404);
            }

            $db->beginTransaction();
            try {
                // Check for unpaid orders
                $stmtUnpaid = dbQuery($db,
                    "SELECT COUNT(*) as cnt FROM om_market_orders
                     WHERE table_id = ? AND partner_id = ?
                       AND status NOT IN ('cancelado', 'recusado')
                       AND (payment_status IS NULL OR payment_status NOT IN ('paid', 'pago'))
                       AND date_added >= NOW() - INTERVAL '24 hours'",
                    [$tableId, $partnerId]
                );
                $unpaid = (int)$stmtUnpaid->fetch()['cnt'];

                if ($unpaid > 0) {
                    $db->rollBack();
                    response(false, null, "Existem {$unpaid} pedido(s) nao pago(s) nesta mesa. Cobre antes de fechar.", 400);
                }

                // Mark all active orders as completed/closed
                dbQuery($db,
                    "UPDATE om_market_orders SET status = 'entregue', date_modified = NOW()
                     WHERE table_id = ? AND partner_id = ?
                       AND status NOT IN ('cancelado', 'recusado', 'entregue')
                       AND date_added >= NOW() - INTERVAL '24 hours'",
                    [$tableId, $partnerId]
                );

                $db->commit();
                response(true, [
                    'table_id' => $tableId,
                    'table_numero' => (int)$table['numero'],
                    'closed' => true
                ], "Mesa fechada com sucesso");

            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                throw $e;
            }
        }
    }

    else {
        response(false, null, "Metodo nao permitido", 405);
    }

} catch (Exception $e) {
    error_log("[mesa/fechar-mesa] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar solicitacao", 500);
}
