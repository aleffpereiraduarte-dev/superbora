<?php
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }

require_once dirname(__DIR__) . "/config/database.php";

$pdo = getPDO();

$input = json_decode(file_get_contents("php://input"), true) ?: $_POST;
$order_id = (int)($input["order_id"] ?? 0);
$shopper_id = (int)($input["shopper_id"] ?? 0);

if (!$order_id || !$shopper_id) { echo json_encode(["success" => false, "error" => "Dados inválidos"]); exit; }

try {
    $stmt = $pdo->prepare("SELECT * FROM om_market_orders WHERE order_id = ? AND shopper_id = ?");
    $stmt->execute([$order_id, $shopper_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) throw new Exception("Pedido não encontrado");
    if ($order["status"] !== "confirmed") throw new Exception("Pedido não está confirmado");
    
    $pdo->prepare("UPDATE om_market_orders SET status = 'shopping', shopping_started_at = NOW(), date_modified = NOW() WHERE order_id = ?")->execute([$order_id]);
    
    $shopper_first = explode(" ", $order["shopper_name"])[0];
    $pdo->prepare("INSERT INTO om_market_chat (order_id, sender_type, sender_id, sender_name, message, message_type, date_added) VALUES (?, 'system', 0, 'Sistema', ?, 'text', NOW())")->execute([$order_id, "🛒 $shopper_first chegou no mercado e começou a separar!"]);
    
    echo json_encode(["success" => true, "status" => "shopping"]);
} catch (Exception $e) {
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}