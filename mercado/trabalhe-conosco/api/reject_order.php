<?php
require_once dirname(__DIR__, 2) . '/config/database.php';
/**
 * API: Shopper recusa pedido
 * POST /api/reject_order.php
 */

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

session_start();

$workerId = $_SESSION["worker_id"] ?? null;
if (!$workerId) {
    http_response_code(401);
    exit(json_encode(["success" => false, "error" => "Não autenticado"]));
}

$input = json_decode(file_get_contents("php://input"), true);
$notificationId = $input["notification_id"] ?? $_POST["notification_id"] ?? null;

if (!$notificationId) {
    http_response_code(400);
    exit(json_encode(["success" => false, "error" => "notification_id obrigatório"]));
}

try {
    $db = new PDO("mysql:host=localhost;dbname=love1;charset=utf8mb4",
        "love1",
        DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    $db->prepare("
        UPDATE om_shopper_notifications 
        SET status = 'recusada', responded_at = NOW()
        WHERE notification_id = ? AND worker_id = ?
    ")->execute([$notificationId, $workerId]);
    
    echo json_encode(["success" => true, "message" => "Pedido recusado"]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
