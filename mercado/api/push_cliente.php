<?php
/**
 * API Push Notifications para Cliente
 * Actions: subscribe, unsubscribe, send, get_pending
 */
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }

require_once dirname(__DIR__) . '/config/database.php';
$pdo = getPDO();

$action = $_REQUEST["action"] ?? "";
$customer_id = (int)($_REQUEST["customer_id"] ?? 0);

switch ($action) {
    case "subscribe":
        $endpoint = $_POST["endpoint"] ?? "";
        $p256dh = $_POST["p256dh"] ?? "";
        $auth = $_POST["auth"] ?? "";

        if (!$customer_id || !$endpoint) {
            echo json_encode(["success" => false, "error" => "Dados incompletos"]);
            exit;
        }

        if (isPostgreSQL()) {
            $stmt = $pdo->prepare("INSERT INTO om_push_subscriptions (customer_id, endpoint, p256dh, auth)
                VALUES (?, ?, ?, ?)
                ON CONFLICT (customer_id) DO UPDATE SET endpoint = EXCLUDED.endpoint, p256dh = EXCLUDED.p256dh, auth = EXCLUDED.auth");
        } else {
            $stmt = $pdo->prepare("INSERT INTO om_push_subscriptions (customer_id, endpoint, p256dh, auth)
                VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE endpoint=VALUES(endpoint), p256dh=VALUES(p256dh), auth=VALUES(auth)");
        }
        $stmt->execute([$customer_id, $endpoint, $p256dh, $auth]);

        echo json_encode(["success" => true, "message" => "Inscrito com sucesso"]);
        break;

    case "unsubscribe":
        $stmt = $pdo->prepare("DELETE FROM om_push_subscriptions WHERE customer_id = ?");
        $stmt->execute([$customer_id]);
        echo json_encode(["success" => true]);
        break;

    case "send":
        $order_id = (int)($_POST["order_id"] ?? 0);
        $title = $_POST["title"] ?? "OneMundo";
        $message = $_POST["message"] ?? "";
        $type = $_POST["type"] ?? "order_update";
        $status = "pending";

        // Salvar notificacao
        $stmt = $pdo->prepare("INSERT INTO om_notifications_log (customer_id, order_id, type, title, message, status) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$customer_id, $order_id, $type, $title, $message, $status]);

        echo json_encode(["success" => true, "notification_id" => $pdo->lastInsertId()]);
        break;

    case "get_pending":
        $stmt = $pdo->prepare("SELECT * FROM om_notifications_log WHERE customer_id = ? AND status = 'pending' ORDER BY sent_at DESC LIMIT 10");
        $stmt->execute([$customer_id]);
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(["success" => true, "notifications" => $notifications]);
        break;

    default:
        echo json_encode(["success" => false, "error" => "Action invalida", "actions" => ["subscribe","unsubscribe","send","get_pending"]]);
}
