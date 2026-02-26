<?php
/**
 * /api/mercado/notifications/list.php
 * GET - lista notificacoes do usuario
 * GET ?unread=1 - conta nao lidas
 * POST { id } - marcar como lida
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
    if (!$payload) response(false, null, "Token invalido", 401);

    $userId = (int)$payload['uid'];
    $userType = $payload['type'];
    $method = $_SERVER["REQUEST_METHOD"];

    // POST - Marcar como lida
    if ($method === "POST") {
        $input = getInput();
        $notifId = (int)($input['id'] ?? 0);

        if ($notifId) {
            $db->prepare("UPDATE om_market_notifications SET is_read = 1, read_at = NOW() WHERE notification_id = ? AND recipient_id = ? AND recipient_type = ?")
                ->execute([$notifId, $userId, $userType]);
        } else {
            // Marcar todas como lidas
            $db->prepare("UPDATE om_market_notifications SET is_read = 1, read_at = NOW() WHERE recipient_id = ? AND recipient_type = ? AND is_read = 0")
                ->execute([$userId, $userType]);
        }

        response(true, null, "Notificacoes atualizadas");
    }

    // GET
    if ($method === "GET") {
        // Contagem de nao lidas
        if (isset($_GET['unread'])) {
            $stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM om_market_notifications WHERE recipient_id = ? AND recipient_type = ? AND is_read = 0");
            $stmt->execute([$userId, $userType]);
            $count = (int)$stmt->fetch()['cnt'];
            response(true, ['unread_count' => $count]);
        }

        // Lista completa
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(50, max(10, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $stmt = $db->prepare("
            SELECT notification_id AS id, title, message AS body, data, is_read, sent_at AS created_at
            FROM om_market_notifications
            WHERE recipient_id = ? AND recipient_type = ?
            ORDER BY sent_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$userId, $userType, $limit, $offset]);
        $notifications = $stmt->fetchAll();

        foreach ($notifications as &$n) {
            $n['data'] = $n['data'] ? json_decode($n['data'], true) : null;
            $n['is_read'] = (bool)$n['is_read'];
        }

        // Total nao lidas
        $stmt2 = $db->prepare("SELECT COUNT(*) AS cnt FROM om_market_notifications WHERE recipient_id = ? AND recipient_type = ? AND is_read = 0");
        $stmt2->execute([$userId, $userType]);
        $unread = (int)$stmt2->fetch()['cnt'];

        response(true, [
            'notifications' => $notifications,
            'unread_count' => $unread,
            'page' => $page
        ]);
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[notifications/list] Erro: " . $e->getMessage());
    response(false, null, "Erro ao buscar notificacoes", 500);
}
