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

    if ($_SERVER["REQUEST_METHOD"] !== "POST") response(false, null, "Metodo nao permitido", 405);

    $input = getInput();
    $order_id = (int)($input['order_id'] ?? 0);
    $amount = (float)($input['amount'] ?? 0);
    $reason = strip_tags(trim($input['reason'] ?? ''));

    if (!$order_id || $amount <= 0) response(false, null, "order_id e amount obrigatorios", 400);

    $db->beginTransaction();

    // SECURITY: Lock order row to prevent double-refund race condition
    $stmt = $db->prepare("SELECT * FROM om_market_orders WHERE order_id = ? FOR UPDATE");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch();
    if (!$order) { $db->rollBack(); response(false, null, "Pedido nao encontrado", 404); }

    if ($order['status'] === 'refunded') {
        $db->rollBack();
        response(false, null, "Pedido ja foi reembolsado", 409);
    }

    $previousRefund = (float)($order['refund_amount'] ?? 0);
    $orderTotal = (float)$order['total'];

    if (($previousRefund + $amount) > $orderTotal) {
        $db->rollBack();
        response(false, null, "Valor do reembolso excede o total do pedido (ja reembolsado: R$ " . number_format($previousRefund, 2, ',', '.') . ")", 400);
    }

    $newRefundTotal = $previousRefund + $amount;
    $isFullyRefunded = (abs($newRefundTotal - $orderTotal) < 0.01);
    $newStatus = $isFullyRefunded ? 'refunded' : $order['status'];

    // Update order with refund amount and conditionally update status
    $stmt = $db->prepare("UPDATE om_market_orders SET refund_amount = ?, status = ?, updated_at = NOW() WHERE order_id = ?");
    $stmt->execute([$newRefundTotal, $newStatus, $order_id]);

    // Only mark sale as refunded if fully refunded
    if ($isFullyRefunded) {
        $stmt = $db->prepare("UPDATE om_market_sales SET status = 'refunded' WHERE order_id = ?");
        $stmt->execute([$order_id]);
    }

    // Timeline entry
    $refundType = $isFullyRefunded ? "total" : "parcial";
    $desc = "Reembolso {$refundType} de R$ " . number_format($amount, 2, ',', '.') . " processado (total reembolsado: R$ " . number_format($newRefundTotal, 2, ',', '.') . ")";
    if ($reason) $desc .= " - Motivo: {$reason}";

    $stmt = $db->prepare("
        INSERT INTO om_order_timeline (order_id, status, description, actor_type, actor_id, created_at)
        VALUES (?, ?, ?, 'admin', ?, NOW())
    ");
    $timelineStatus = $isFullyRefunded ? 'refunded' : 'partial_refund';
    $stmt->execute([$order_id, $timelineStatus, $desc, $admin_id]);

    $db->commit();

    om_audit()->log('refund', 'order', $order_id, null, ['amount' => $amount, 'reason' => $reason], $desc);

    response(true, ['order_id' => $order_id, 'refund_amount' => $amount], "Reembolso processado");
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log("[admin/order-refund] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
