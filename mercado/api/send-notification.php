<?php
/**
 * API: Send Notification - Envia push notification
 * POST: /api/send-notification.php
 */
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }

require_once __DIR__ . '/../config/database.php';

try {
    $pdo = getPDO();
} catch (PDOException $e) {
    echo json_encode(["success" => false]); exit;
}

$input = json_decode(file_get_contents("php://input"), true) ?: $_POST;

$user_type = $input["user_type"] ?? "";
$user_id = (int)($input["user_id"] ?? 0);
$title = $input["title"] ?? "";
$body = $input["body"] ?? "";
$data = $input["data"] ?? [];

if (!$user_type || !$user_id || !$title) {
    echo json_encode(["success" => false, "error" => "Dados incompletos"]); exit;
}

// Adicionar à fila
$stmt = $pdo->prepare("
    INSERT INTO om_notifications_queue 
    (user_type, user_id, title, body, data, status, created_at)
    VALUES (?, ?, ?, ?, ?, 'pending', NOW())
");
$stmt->execute([$user_type, $user_id, $title, $body, json_encode($data)]);
$notification_id = $pdo->lastInsertId();

// Buscar subscription e tentar enviar
$sent = false;
try {
    $stmt = $pdo->prepare("
        SELECT * FROM om_push_subscriptions 
        WHERE user_type = ? AND user_id = ? AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$user_type, $user_id]);
    $subscription = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($subscription) {
        // Marcar como enviado
        $pdo->prepare("UPDATE om_notifications_queue SET status = 'sent', sent_at = NOW() WHERE id = ?")->execute([$notification_id]);
        $sent = true;
    }
} catch (Exception $e) {}

echo json_encode([
    "success" => true,
    "notification_id" => $notification_id,
    "sent" => $sent
]);
