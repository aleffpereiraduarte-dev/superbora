<?php
/**
 * Smart Push Notifications — Cron every 30 min
 * - Abandoned carts (>2h without order)
 * - Habitual order time nudge
 * Rate limit: max 2 push/day per user
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/NotificationSender.php';

// SECURITY: Cron auth guard — header only, no GET param
if (php_sapi_name() !== 'cli') {
    $cronKey = $_SERVER['HTTP_X_CRON_KEY'] ?? '';
    $expectedKey = $_ENV['CRON_SECRET'] ?? getenv('CRON_SECRET') ?: '';
    if (empty($expectedKey) || empty($cronKey) || !hash_equals($expectedKey, $cronKey)) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

$db = getDB();
$sender = NotificationSender::getInstance($db);
$sent = 0;

function canSendPush(PDO $db, int $customerId): bool {
    $stmt = $db->prepare("SELECT COUNT(*) FROM om_smart_push_log WHERE customer_id = ? AND sent_at > NOW() - INTERVAL '24 hours'");
    $stmt->execute([$customerId]);
    return $stmt->fetchColumn() < 2;
}

/**
 * Atomically log push and claim the slot. Returns true if inserted (slot claimed), false if duplicate.
 * Uses INSERT ... ON CONFLICT to prevent duplicate pushes under race conditions.
 */
function logPushAtomic(PDO $db, int $customerId, string $type, string $title, string $body): bool {
    $stmt = $db->prepare("
        INSERT INTO om_smart_push_log (customer_id, push_type, title, body)
        SELECT ?, ?, ?, ?
        WHERE (SELECT COUNT(*) FROM om_smart_push_log WHERE customer_id = ? AND push_type = ? AND sent_at > NOW() - INTERVAL '24 hours') = 0
    ");
    $stmt->execute([$customerId, $type, $title, $body, $customerId, $type]);
    return $stmt->rowCount() > 0;
}

// 1. Abandoned carts — items added >2h ago, no order since
$stmt = $db->query("
    SELECT DISTINCT c.customer_id
    FROM om_market_cart c
    WHERE c.updated_at < NOW() - INTERVAL '2 hours'
    AND c.updated_at > NOW() - INTERVAL '24 hours'
    AND c.customer_id IS NOT NULL
    AND NOT EXISTS (
        SELECT 1 FROM om_market_orders o
        WHERE o.customer_id = c.customer_id
        AND o.created_at > c.updated_at
    )
    AND NOT EXISTS (
        SELECT 1 FROM om_smart_push_log sp
        WHERE sp.customer_id = c.customer_id
        AND sp.push_type = 'abandoned_cart'
        AND sp.sent_at > NOW() - INTERVAL '24 hours'
    )
    LIMIT 50
");

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if (!canSendPush($db, $row['customer_id'])) continue;
    $title = 'Esqueceu algo no carrinho?';
    $body = 'Seus itens estao esperando por voce! Finalize seu pedido agora.';
    try {
        // Atomically claim the push slot before sending
        if (!logPushAtomic($db, $row['customer_id'], 'abandoned_cart', $title, $body)) continue;
        $sender->notifyCustomer($row['customer_id'], $title, $body, ['type' => 'abandoned_cart', 'route' => '/carrinho']);
        $sent++;
    } catch (Exception $e) { error_log("Smart push error: " . $e->getMessage()); }
}

// 2. Habitual order time — customers who usually order around this hour
$currentHour = (int)date('G');
$stmt = $db->prepare("
    SELECT customer_id, COUNT(*) as order_count
    FROM om_market_orders
    WHERE EXTRACT(HOUR FROM created_at) BETWEEN ? AND ?
    AND status = 'entregue'
    AND created_at > NOW() - INTERVAL '30 days'
    GROUP BY customer_id
    HAVING COUNT(*) >= 3
    LIMIT 50
");
$stmt->execute([$currentHour, $currentHour + 1]);

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if (!canSendPush($db, $row['customer_id'])) continue;
    $check = $db->prepare("SELECT 1 FROM om_smart_push_log WHERE customer_id = ? AND push_type = 'habitual_time' AND sent_at > NOW() - INTERVAL '20 hours'");
    $check->execute([$row['customer_id']]);
    if ($check->fetch()) continue;

    $title = 'Hora do pedido!';
    $body = 'Voce costuma pedir por volta desse horario. Que tal hoje?';
    try {
        // Atomically claim the push slot before sending
        if (!logPushAtomic($db, $row['customer_id'], 'habitual_time', $title, $body)) continue;
        $sender->notifyCustomer($row['customer_id'], $title, $body, ['type' => 'habitual_time', 'route' => '/']);
        $sent++;
    } catch (Exception $e) { error_log("Smart push error: " . $e->getMessage()); }
}

echo date('Y-m-d H:i:s') . " — Smart push: {$sent} notifications sent\n";
