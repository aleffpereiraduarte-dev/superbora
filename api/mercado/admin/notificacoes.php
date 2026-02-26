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
    $admin_id = $payload["uid"];

    if ($_SERVER["REQUEST_METHOD"] === "GET") {
        $page = max(1, (int)($_GET["page"] ?? 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;

        $stmt = $db->query("SELECT COUNT(*) as total FROM om_notifications");
        $total = (int)$stmt->fetch()["total"];

        $stmt = $db->prepare("
            SELECT notification_id, user_type, user_id, title, body, type, status, is_read, created_at
            FROM om_notifications
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([(int)$limit, (int)$offset]);
        $notificacoes = $stmt->fetchAll();

        response(true, [
            "notificacoes" => $notificacoes,
            "pagination" => ["page" => $page, "limit" => $limit, "total" => $total, "pages" => ceil($total / $limit)]
        ], "Notificacoes listadas");

    } elseif ($_SERVER["REQUEST_METHOD"] === "POST") {
        $input = getInput();
        $title = strip_tags(trim($input["title"] ?? ""));
        $body = strip_tags(trim($input["body"] ?? ""));
        $user_type = $input["user_type"] ?? $input["target_type"] ?? "customer";
        $user_id = (int)($input["user_id"] ?? $input["target_id"] ?? 0);

        if (!$title || !$body) response(false, null, "title e body obrigatorios", 400);

        $valid_types = ["customer", "shopper", "delivery", "admin"];
        if (!in_array($user_type, $valid_types, true)) $user_type = "customer";

        $stmt = $db->prepare("INSERT INTO om_notifications (user_type, user_id, title, body, type, created_at) VALUES (?, ?, ?, ?, 'admin_broadcast', NOW())");
        $stmt->execute([$user_type, $user_id ?: 0, $title, $body]);
        $new_id = (int)$db->lastInsertId();

        response(true, ["id" => $new_id], "Notificacao enviada");
    } else {
        response(false, null, "Metodo nao permitido", 405);
    }
} catch (Exception $e) {
    error_log("[admin/notificacoes] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
