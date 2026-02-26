<?php
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();
    $admin_id = $payload['uid'];

    if ($_SERVER["REQUEST_METHOD"] === "GET") {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;

        $stmt = $db->query("SELECT COUNT(*) as total FROM om_market_orders WHERE status = 'refunded'");
        $total = (int)$stmt->fetch()['total'];

        $stmt = $db->prepare("
            SELECT o.order_id, o.total, o.status, o.created_at, o.updated_at,
                   c.name as customer_name,
                   c.email as customer_email,
                   p.name as partner_name
            FROM om_market_orders o
            LEFT JOIN om_customers c ON o.customer_id = c.customer_id
            LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
            WHERE o.status = 'refunded'
            ORDER BY o.updated_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([(int)$limit, (int)$offset]);
        $refunds = $stmt->fetchAll();

        response(true, [
            'reembolsos' => $refunds,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => ceil($total / $limit)
            ]
        ], "Reembolsos listados");

    } elseif ($_SERVER["REQUEST_METHOD"] === "POST") {
        $input = getInput();
        $order_id = (int)($input['id'] ?? $input['order_id'] ?? 0);
        $action = $input['action'] ?? '';
        $reason = trim($input['reason'] ?? '');

        if (!$order_id || !in_array($action, ['approve', 'deny'])) {
            response(false, null, "id e action (approve/deny) obrigatorios", 400);
        }

        if ($action === 'approve') {
            $db->beginTransaction();
            try {
                // Lock the order row to prevent concurrent refund processing
                $stmt = $db->prepare("SELECT status, total, refund_amount FROM om_market_orders WHERE order_id = ? FOR UPDATE");
                $stmt->execute([$order_id]);
                $order = $stmt->fetch();

                if (!$order) {
                    $db->rollBack();
                    response(false, null, "Pedido nao encontrado", 404);
                }

                if ($order['status'] === 'refunded') {
                    $db->rollBack();
                    response(false, null, "Pedido ja foi reembolsado", 409);
                }

                $refundAmount = (float)($input['amount'] ?? 0);
                $orderTotal = (float)$order['total'];
                $previousRefund = (float)($order['refund_amount'] ?? 0);

                // If no specific amount provided, refund the remaining balance
                if ($refundAmount <= 0) {
                    $refundAmount = $orderTotal - $previousRefund;
                }

                $newRefundTotal = $previousRefund + $refundAmount;
                if ($newRefundTotal > $orderTotal + 0.01) {
                    $db->rollBack();
                    response(false, null, "Valor do reembolso excede o total do pedido", 400);
                }

                $isFullyRefunded = (abs($newRefundTotal - $orderTotal) < 0.01);
                $newStatus = $isFullyRefunded ? 'refunded' : $order['status'];

                $stmt = $db->prepare("UPDATE om_market_orders SET refund_amount = ?, status = ?, updated_at = NOW() WHERE order_id = ?");
                $stmt->execute([$newRefundTotal, $newStatus, $order_id]);

                if ($isFullyRefunded) {
                    $stmt = $db->prepare("UPDATE om_market_sales SET status = 'refunded' WHERE order_id = ?");
                    $stmt->execute([$order_id]);
                }

                $refundType = $isFullyRefunded ? "total" : "parcial";
                $desc = "Reembolso {$refundType} de R$ " . number_format($refundAmount, 2, ',', '.') . " aprovado pelo admin";
                if ($reason) $desc .= ". Motivo: {$reason}";

                $stmt = $db->prepare("
                    INSERT INTO om_order_timeline (order_id, status, description, actor_type, actor_id, created_at)
                    VALUES (?, 'refunded', ?, 'admin', ?, NOW())
                ");
                $stmt->execute([$order_id, $desc, $admin_id]);

                $db->commit();
            } catch (Exception $txEx) {
                $db->rollBack();
                throw $txEx;
            }

            om_audit()->log('approve', 'refund', $order_id, null, ['reason' => $reason], $desc);
            response(true, ['order_id' => $order_id], "Reembolso aprovado");
        } else {
            $desc = "Reembolso negado pelo admin";
            if ($reason) $desc .= ". Motivo: {$reason}";

            $stmt = $db->prepare("
                INSERT INTO om_order_timeline (order_id, status, description, actor_type, actor_id, created_at)
                VALUES (?, 'refund_denied', ?, 'admin', ?, NOW())
            ");
            $stmt->execute([$order_id, $desc, $admin_id]);

            om_audit()->log('reject', 'refund', $order_id, null, ['reason' => $reason], $desc);
            response(true, ['order_id' => $order_id], "Reembolso negado");
        }
    } else {
        response(false, null, "Metodo nao permitido", 405);
    }
} catch (Exception $e) {
    error_log("[admin/reembolsos] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
