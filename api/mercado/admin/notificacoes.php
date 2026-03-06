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
        require_once __DIR__ . "/../helpers/notify.php";

        $input = getInput();
        $title = strip_tags(trim($input["title"] ?? ""));
        $body = strip_tags(trim($input["body"] ?? ""));
        $user_type = $input["user_type"] ?? $input["target_type"] ?? "customer";
        $user_id = (int)($input["user_id"] ?? $input["target_id"] ?? 0);
        $customer = trim($input["customer"] ?? "");

        if (!$title || !$body) response(false, null, "title e body obrigatorios", 400);

        $valid_types = ["customer", "shopper", "delivery", "admin"];
        if (!in_array($user_type, $valid_types, true)) $user_type = "customer";

        // Resolve customer by ID or search term
        if (!$user_id && $customer) {
            if (is_numeric($customer)) {
                $user_id = (int)$customer;
            } else {
                $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $customer);
                $stmt = $db->prepare("SELECT customer_id FROM om_customers WHERE name ILIKE ? OR email ILIKE ? LIMIT 1");
                $stmt->execute(["%{$escaped}%", "%{$escaped}%"]);
                $row = $stmt->fetch();
                if ($row) $user_id = (int)$row['customer_id'];
            }
        }

        if (!$user_id) response(false, null, "customer_id ou customer obrigatorio", 400);

        // Save to DB
        $stmt = $db->prepare("INSERT INTO om_notifications (user_type, user_id, title, body, type, created_at) VALUES (?, ?, ?, ?, 'admin_broadcast', NOW())");
        $stmt->execute([$user_type, $user_id, $title, $body]);
        $new_id = (int)$db->lastInsertId();

        // Actually send push notification
        $push_status = 'sent';
        $push_message = 'Notificacao salva e push enviado';
        try {
            $sent = notifyCustomer($db, $user_id, $title, $body, '/notificacoes', ['notification_id' => $new_id]);
            if ($sent === 0) {
                $push_status = 'token_invalid';
                $push_message = 'Notificacao salva mas push nao enviado (sem token valido)';
            } else {
                $push_status = 'delivered';
                $push_message = "Push enviado com sucesso ({$sent} dispositivo(s))";
            }
        } catch (Exception $e) {
            $push_status = 'failed';
            $push_message = 'Notificacao salva mas push falhou: ' . $e->getMessage();
            error_log("[admin/notificacoes] Push error: " . $e->getMessage());
        }

        response(true, ["id" => $new_id, "status" => $push_status, "message" => $push_message], $push_message);
    } else {
        response(false, null, "Metodo nao permitido", 405);
    }
} catch (Exception $e) {
    error_log("[admin/notificacoes] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
