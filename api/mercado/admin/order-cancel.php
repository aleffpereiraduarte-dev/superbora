<?php
/**
 * POST /api/mercado/admin/order-cancel.php
 *
 * Cancela um pedido a partir do painel administrativo.
 *
 * Body: {
 *   order_id: int,
 *   reason: string,
 *   reason_category?: string,
 *   auto_refund?: bool,
 *   notify_customer?: bool,
 *   notify_partner?: bool
 * }
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";
require_once __DIR__ . "/../helpers/notify.php";
require_once __DIR__ . "/../helpers/ws-customer-broadcast.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();
    $admin_id = (int)$payload['uid'];

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') response(false, null, "Metodo nao permitido", 405);

    $input = getInput();
    $order_id = (int)($input['order_id'] ?? 0);
    $reason = strip_tags(trim($input['reason'] ?? ''));
    $reason_category = strip_tags(trim($input['reason_category'] ?? 'admin'));
    $auto_refund = (bool)($input['auto_refund'] ?? false);
    $notify_customer = (bool)($input['notify_customer'] ?? true);
    $notify_partner = (bool)($input['notify_partner'] ?? true);

    if (!$order_id) response(false, null, "order_id obrigatorio", 400);
    if (!$reason) response(false, null, "reason obrigatorio", 400);

    $db->beginTransaction();

    // Lock order row to prevent race conditions
    $stmt = $db->prepare("SELECT * FROM om_market_orders WHERE order_id = ? FOR UPDATE");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch();

    if (!$order) {
        $db->rollBack();
        response(false, null, "Pedido nao encontrado", 404);
    }

    $non_cancellable = ['entregue', 'delivered', 'cancelado', 'cancelled', 'refunded'];
    if (in_array($order['status'], $non_cancellable)) {
        $db->rollBack();
        response(false, null, "Pedido nao pode ser cancelado (status: {$order['status']})", 409);
    }

    $old_status = $order['status'];

    // Update order status
    $stmt = $db->prepare("
        UPDATE om_market_orders
        SET status = 'cancelado',
            cancelado_por = 'admin',
            cancelamento_motivo = ?,
            cancelamento_categoria = ?,
            cancelled_at = NOW(),
            updated_at = NOW()
        WHERE order_id = ?
    ");
    $stmt->execute([$reason, $reason_category, $order_id]);

    // Timeline entry
    $desc = "Pedido cancelado pelo admin. Motivo: {$reason}";
    if ($reason_category !== 'admin') $desc .= " (categoria: {$reason_category})";

    $stmt = $db->prepare("
        INSERT INTO om_order_timeline (order_id, status, description, actor_type, actor_id, created_at)
        VALUES (?, 'cancelado', ?, 'admin', ?, NOW())
    ");
    $stmt->execute([$order_id, $desc, $admin_id]);

    // Auto refund if requested
    $refund_id = null;
    if ($auto_refund && (float)$order['total'] > 0) {
        $refund_amount = (float)$order['total'] - (float)($order['refund_amount'] ?? 0);
        if ($refund_amount > 0) {
            // Update refund amount on order
            $stmt = $db->prepare("UPDATE om_market_orders SET refund_amount = ?, status = 'refunded' WHERE order_id = ?");
            $stmt->execute([(float)$order['total'], $order_id]);

            // Create refund record
            try {
                $stmt = $db->prepare("
                    INSERT INTO om_market_refunds (order_id, customer_id, amount, reason, status, created_at, reviewed_at, reviewed_by)
                    VALUES (?, ?, ?, ?, 'approved', NOW(), NOW(), ?)
                    RETURNING id
                ");
                $stmt->execute([$order_id, $order['customer_id'], $refund_amount, "Cancelamento admin: {$reason}", $admin_id]);
                $row = $stmt->fetch();
                $refund_id = $row ? (int)$row['id'] : null;
            } catch (Exception $e) {
                // om_market_refunds table might not have all columns — log and continue
                error_log("[order-cancel] Refund record error: " . $e->getMessage());
            }

            // Update sale status if exists
            try {
                $db->prepare("UPDATE om_market_sales SET status = 'refunded' WHERE order_id = ?")->execute([$order_id]);
            } catch (Exception $e) {
                // Table may not exist
            }

            // Refund timeline entry
            $refundDesc = "Reembolso automatico de R$ " . number_format($refund_amount, 2, ',', '.') . " processado";
            $stmt = $db->prepare("
                INSERT INTO om_order_timeline (order_id, status, description, actor_type, actor_id, created_at)
                VALUES (?, 'refunded', ?, 'admin', ?, NOW())
            ");
            $stmt->execute([$order_id, $refundDesc, $admin_id]);
        }
    }

    $db->commit();

    // Notifications (outside transaction — non-critical)
    $customer_id = (int)$order['customer_id'];
    $partner_id = (int)$order['partner_id'];
    $orderNum = $order['order_number'] ?? "#{$order_id}";

    if ($notify_customer && $customer_id) {
        try {
            notifyCustomer(
                $db,
                $customer_id,
                "Pedido {$orderNum} cancelado",
                "Seu pedido foi cancelado. Motivo: {$reason}" . ($auto_refund ? " O reembolso sera processado." : ""),
                "/pedidos",
                ['order_id' => $order_id, 'type' => 'order_cancelled']
            );
        } catch (Exception $e) {
            error_log("[order-cancel] Notify customer error: " . $e->getMessage());
        }
    }

    if ($notify_partner && $partner_id) {
        try {
            notifyPartner(
                $db,
                $partner_id,
                "Pedido {$orderNum} cancelado",
                "O pedido foi cancelado pelo suporte. Motivo: {$reason}",
                '/painel/mercado/pedidos.php',
                ['order_id' => $order_id, 'type' => 'order_cancelled']
            );
        } catch (Exception $e) {
            error_log("[order-cancel] Notify partner error: " . $e->getMessage());
        }
    }

    // WebSocket broadcast
    try {
        wsBroadcastToCustomer($customer_id, 'order_update', [
            'order_id' => $order_id,
            'status' => 'cancelado',
            'reason' => $reason,
        ]);
        wsBroadcastToOrder($order_id, 'order_update', [
            'order_id' => $order_id,
            'status' => 'cancelado',
            'reason' => $reason,
        ]);
    } catch (Exception $e) {
        error_log("[order-cancel] WS broadcast error: " . $e->getMessage());
    }

    // Audit log
    om_audit()->log(
        'order_cancel',
        'order',
        $order_id,
        ['status' => $old_status],
        ['status' => 'cancelado', 'reason' => $reason, 'auto_refund' => $auto_refund, 'refund_id' => $refund_id],
        "Pedido {$orderNum} cancelado pelo admin. Motivo: {$reason}"
    );

    response(true, [
        'order_id' => $order_id,
        'old_status' => $old_status,
        'new_status' => $auto_refund ? 'refunded' : 'cancelado',
        'refund_id' => $refund_id,
    ], "Pedido cancelado com sucesso");

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log("[admin/order-cancel] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
