<?php
/**
 * Customer Notifications API
 * GET - List notifications (supports count_only=1 for badge count)
 * POST - Mark notification as read
 */
require_once __DIR__ . "/../../config/database.php";
require_once dirname(__DIR__, 4) . "/includes/classes/OmAuth.php";

setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];

/**
 * Get authenticated customer ID (signature-verified)
 */
function getCustomerId(): ?int {
    $token = om_auth()->getTokenFromRequest();
    if (!$token) return null;
    $payload = om_auth()->validateToken($token);
    if (!$payload || ($payload['type'] ?? '') !== 'customer') return null;
    return (int)($payload['uid'] ?? 0) ?: null;
}

try {

$db = getDB();
OmAuth::getInstance()->setDb($db);

// GET - List notifications or count
if ($method === 'GET') {
    $countOnly = isset($_GET['count_only']) && $_GET['count_only'] == '1';
    $customerId = getCustomerId();

    if (!$customerId) {
        if ($countOnly) {
            response(true, ['unread_count' => 0]);
        }
        response(false, null, 'Nao autenticado', 401);
    }

    // If count only, just return unread count
    if ($countOnly) {
        $stmt = $db->prepare("
            SELECT COUNT(*) as cnt FROM om_market_notifications
            WHERE recipient_id = ? AND recipient_type = 'customer' AND is_read = 0
        ");
        $stmt->execute([$customerId]);
        $count = $stmt->fetchColumn();
        response(true, ['unread_count' => (int)$count]);
    }

    // Get full notifications list
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = 20;
    $offset = ($page - 1) * $limit;

    $stmt = $db->prepare("
        SELECT notification_id as id, title, message, type, data, is_read, sent_at as created_at
        FROM om_market_notifications
        WHERE recipient_id = ? AND recipient_type = 'customer'
        ORDER BY sent_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$customerId, $limit, $offset]);
    $notifications = $stmt->fetchAll();

    // Get total and unread count
    $stmt = $db->prepare("SELECT COUNT(*) FROM om_market_notifications WHERE recipient_id = ? AND recipient_type = 'customer'");
    $stmt->execute([$customerId]);
    $total = $stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) FROM om_market_notifications WHERE recipient_id = ? AND recipient_type = 'customer' AND is_read = 0");
    $stmt->execute([$customerId]);
    $unreadCount = $stmt->fetchColumn();

    // Format data
    foreach ($notifications as &$n) {
        $n['data'] = $n['data'] ? json_decode($n['data'], true) : null;
    }

    response(true, [
        'notifications' => $notifications,
        'unread_count' => (int)$unreadCount,
        'total' => (int)$total,
        'page' => $page,
        'has_more' => ($offset + count($notifications)) < $total
    ]);
}

// POST - Mark as read
if ($method === 'POST') {
    $customerId = getCustomerId();

    if (!$customerId) {
        response(false, null, 'Nao autenticado', 401);
    }

    $input = getInput();
    $notificationId = $input['notification_id'] ?? null;
    $markAllRead = $input['mark_all_read'] ?? false;

    if ($markAllRead) {
        $stmt = $db->prepare("
            UPDATE om_market_notifications
            SET is_read = 1, read_at = NOW()
            WHERE recipient_id = ? AND recipient_type = 'customer' AND is_read = 0
        ");
        $stmt->execute([$customerId]);
        response(true, ['marked' => $stmt->rowCount()]);
    }

    if (!$notificationId) {
        response(false, null, 'notification_id obrigatorio', 400);
    }

    $stmt = $db->prepare("
        UPDATE om_market_notifications
        SET is_read = 1, read_at = NOW()
        WHERE notification_id = ? AND recipient_id = ? AND recipient_type = 'customer' AND is_read = 0
    ");
    $stmt->execute([$notificationId, $customerId]);

    response(true, ['success' => $stmt->rowCount() > 0]);
}

response(false, null, 'Metodo nao permitido', 405);

} catch (Exception $e) {
    error_log("[customer/notifications] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar notificacoes", 500);
}
