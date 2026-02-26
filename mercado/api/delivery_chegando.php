<?php
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }

require_once __DIR__ . '/../config/database.php';

$pdo = getPDO();

$input = json_decode(file_get_contents("php://input"), true) ?: $_POST;
$order_id = (int)($input["order_id"] ?? 0);
$delivery_id = (int)($input["delivery_id"] ?? 0);

if (!$order_id || !$delivery_id) { echo json_encode(["success" => false, "error" => "Dados inválidos"]); exit; }

try {
    $stmt = $pdo->prepare("SELECT * FROM om_market_orders WHERE order_id = ? AND delivery_id = ?");
    $stmt->execute([$order_id, $delivery_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) throw new Exception("Pedido não encontrado");
    if ($order["status"] !== "delivering") throw new Exception("Pedido não está em entrega");
    
    $delivery_first = explode(" ", $order["delivery_name"])[0];
    $pdo->prepare("INSERT INTO om_market_chat (order_id, sender_type, sender_id, sender_name, message, message_type, date_added) VALUES (?, 'system', 0, 'Sistema', ?, 'text', NOW())")->execute([$order_id, "📍 $delivery_first está chegando!\n\n🔑 Seu código: " . $order["delivery_code"] . "\n\nFale esse código para o entregador!"]);
    
    echo json_encode(["success" => true, "delivery_code" => $order["delivery_code"]]);
} catch (Exception $e) {
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}