<?php
/**
 * POST /api/mercado/customer/push-opened.php
 *
 * Track when a customer opens/taps a push notification.
 * Used to measure conversion rates of smart push campaigns.
 *
 * Body: { "push_id": 123 }
 *
 * Optionally also accepts: { "push_id": 123, "order_id": 456 }
 * to link a push notification to a resulting order (conversion tracking).
 *
 * Requires: Customer authentication (Bearer token)
 */

require_once __DIR__ . "/../config/database.php";

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, "Metodo nao permitido", 405);
}

try {
    $db = getDB();
    $customerId = requireCustomerAuth();

    $input = getInput();
    $pushId = (int)($input['push_id'] ?? 0);
    $orderId = !empty($input['order_id']) ? (int)$input['order_id'] : null;

    if (!$pushId) {
        response(false, null, "push_id e obrigatorio", 400);
    }

    // Verify the push belongs to this customer (prevent data manipulation)
    $stmt = $db->prepare("
        SELECT id, opened_at, order_id
        FROM om_smart_push_log
        WHERE id = ? AND customer_id = ?
    ");
    $stmt->execute([$pushId, $customerId]);
    $push = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$push) {
        response(false, null, "Notificacao nao encontrada", 404);
    }

    // Build update dynamically — only set opened_at if not already set
    $updates = [];
    $params = [];

    if (empty($push['opened_at'])) {
        $updates[] = "opened_at = NOW()";
    }

    // Always update clicked_at (can track re-engagement)
    $updates[] = "clicked_at = NOW()";

    // Link to order if provided and not already linked
    if ($orderId && empty($push['order_id'])) {
        // Verify the order belongs to this customer
        $orderCheck = $db->prepare("SELECT order_id FROM om_market_orders WHERE order_id = ? AND customer_id = ?");
        $orderCheck->execute([$orderId, $customerId]);
        if ($orderCheck->fetch()) {
            $updates[] = "order_id = ?";
            $params[] = $orderId;
        }
    }

    if (!empty($updates)) {
        $params[] = $pushId;
        $params[] = $customerId;
        $db->prepare("
            UPDATE om_smart_push_log
            SET " . implode(', ', $updates) . "
            WHERE id = ? AND customer_id = ?
        ")->execute($params);
    }

    // Also log engagement event for analytics
    try {
        $db->prepare("
            INSERT INTO om_notification_engagement (notification_id, customer_id, action, metadata)
            VALUES (?, ?, 'push_opened', ?::jsonb)
        ")->execute([
            $pushId,
            $customerId,
            json_encode([
                'push_type' => 'smart_push',
                'order_id' => $orderId,
                'timestamp' => date('c'),
            ]),
        ]);
    } catch (Exception $e) {
        // Non-critical — don't fail the request
        error_log("[push-opened] Engagement log error: " . $e->getMessage());
    }

    response(true, ['tracked' => true], "Abertura registrada");

} catch (Exception $e) {
    error_log("[push-opened] Erro: " . $e->getMessage());
    response(false, null, "Erro ao registrar abertura", 500);
}
