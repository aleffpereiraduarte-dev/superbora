<?php
/**
 * /api/mercado/customer/notifications.php
 * Customer notifications endpoint
 *
 * GET              - list notifications with unread count
 * GET ?count_only=1 - return only unread count (lightweight, used by header badge)
 * POST { notification_id }  - mark single notification as read
 * POST { mark_all_read }    - mark all notifications as read
 */

require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Autenticacao necessaria", 401);
    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== 'customer') response(false, null, "Nao autorizado", 401);

    $customerId = (int)$payload['uid'];
    $method = $_SERVER["REQUEST_METHOD"];

    // ── POST: mark as read ──────────────────────────────────────────
    if ($method === "POST") {
        $input = getInput();

        if (!empty($input['mark_all_read'])) {
            // Mark all unread notifications as read
            $db->prepare("UPDATE om_market_notifications SET is_read = 1, read_at = NOW() WHERE recipient_id = ? AND recipient_type = 'customer' AND is_read = 0")
                ->execute([$customerId]);
            response(true, ['unread_count' => 0], "Todas notificacoes marcadas como lidas");
        }

        $notifId = (int)($input['notification_id'] ?? 0);
        if ($notifId) {
            $db->prepare("UPDATE om_market_notifications SET is_read = 1, read_at = NOW() WHERE notification_id = ? AND recipient_id = ? AND recipient_type = 'customer'")
                ->execute([$notifId, $customerId]);

            // Return updated unread count
            $stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM om_market_notifications WHERE recipient_id = ? AND recipient_type = 'customer' AND is_read = 0");
            $stmt->execute([$customerId]);
            $remaining = (int)$stmt->fetch()['cnt'];

            response(true, ['unread_count' => $remaining], "Notificacao marcada como lida");
        }

        response(false, null, "Parametros invalidos", 400);
    }

    // ── GET ─────────────────────────────────────────────────────────
    if ($method === "GET") {

        // Lightweight count-only mode (used by header badge)
        if (isset($_GET['count_only'])) {
            $stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM om_market_notifications WHERE recipient_id = ? AND recipient_type = 'customer' AND is_read = 0");
            $stmt->execute([$customerId]);
            $count = (int)$stmt->fetch()['cnt'];
            response(true, ['unread_count' => $count]);
        }

        // Full notification list
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(50, max(10, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $stmt = $db->prepare("
            SELECT notification_id AS id, title, message AS body, type, data,
                   action_url, is_read, read_at, sent_at AS created_at
            FROM om_market_notifications
            WHERE recipient_id = ? AND recipient_type = 'customer'
            ORDER BY sent_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$customerId, $limit, $offset]);
        $notifications = $stmt->fetchAll();

        foreach ($notifications as &$n) {
            $n['data'] = $n['data'] ? json_decode($n['data'], true) : null;
            $n['is_read'] = (bool)$n['is_read'];
        }

        // Unread count
        $stmtCount = $db->prepare("SELECT COUNT(*) AS cnt FROM om_market_notifications WHERE recipient_id = ? AND recipient_type = 'customer' AND is_read = 0");
        $stmtCount->execute([$customerId]);
        $unreadCount = (int)$stmtCount->fetch()['cnt'];

        response(true, [
            'notifications' => $notifications,
            'unread_count' => $unreadCount,
            'page' => $page
        ]);
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[customer/notifications] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar notificacoes", 500);
}
