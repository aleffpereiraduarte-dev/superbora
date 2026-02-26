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
    $new_status = $input['status'] ?? '';
    $reason = strip_tags(trim($input['reason'] ?? ''));

    if (!$order_id || !$new_status) response(false, null, "order_id e status obrigatorios", 400);

    $valid = ['pending','confirmed','preparing','ready','collecting','in_transit','entregue','cancelled','refunded'];
    if (!in_array($new_status, $valid)) response(false, null, "Status invalido", 400);

    // Valid status transitions (admin can force most, but not backwards into processing states)
    $allowedTransitions = [
        'pending'     => ['confirmed', 'cancelled', 'refunded'],
        'confirmed'   => ['preparing', 'cancelled', 'refunded'],
        'preparing'   => ['ready', 'cancelled', 'refunded'],
        'ready'       => ['collecting', 'cancelled', 'refunded'],
        'collecting'  => ['in_transit', 'cancelled', 'refunded'],
        'in_transit'  => ['entregue', 'cancelled', 'refunded'],
        'entregue'    => ['refunded'],
        'cancelled'   => ['refunded'],
        'refunded'    => [],
    ];

    $db->beginTransaction();
    try {
        // Lock the order row for atomic status change
        $stmt = $db->prepare("SELECT status FROM om_market_orders WHERE order_id = ? FOR UPDATE");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch();
        if (!$order) {
            $db->rollBack();
            response(false, null, "Pedido nao encontrado", 404);
        }

        $old_status = $order['status'];

        // Enforce valid transitions
        $allowed = $allowedTransitions[$old_status] ?? [];
        if (!in_array($new_status, $allowed)) {
            $db->rollBack();
            response(false, null, "Transicao de '{$old_status}' para '{$new_status}' nao permitida", 422);
        }

        // Update order
        $stmt = $db->prepare("UPDATE om_market_orders SET status = ?, updated_at = NOW(), date_modified = NOW() WHERE order_id = ?");
        $stmt->execute([$new_status, $order_id]);

        // Timeline entry
        $desc = "Status alterado de '{$old_status}' para '{$new_status}'";
        if ($reason) $desc .= " - Motivo: {$reason}";

        $stmt = $db->prepare("
            INSERT INTO om_order_timeline (order_id, status, description, actor_type, actor_id, created_at)
            VALUES (?, ?, ?, 'admin', ?, NOW())
        ");
        $stmt->execute([$order_id, $new_status, $desc, $admin_id]);

        $db->commit();
    } catch (Exception $txEx) {
        $db->rollBack();
        throw $txEx;
    }

    // Audit log (outside transaction, non-critical)
    om_audit()->logStatusChange('order', $order_id, $old_status, $new_status);

    response(true, [
        'order_id' => $order_id,
        'old_status' => $old_status,
        'new_status' => $new_status
    ], "Status atualizado");
} catch (Exception $e) {
    error_log("[admin/order-status] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
