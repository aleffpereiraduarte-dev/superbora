<?php
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }

require_once __DIR__ . '/../config/database.php';

$pdo = getPDO();

$input = json_decode(file_get_contents("php://input"), true) ?: $_POST;
$qr_code = trim($input["qr_code"] ?? "");
$delivery_id = (int)($input["delivery_id"] ?? 0);

if (!$qr_code || !$delivery_id) { echo json_encode(["success" => false, "error" => "Dados inválidos"]); exit; }

try {
    $pdo->beginTransaction();
    
    $stmt = $pdo->prepare("SELECT * FROM om_market_orders WHERE box_qr_code = ?");
    $stmt->execute([$qr_code]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) throw new Exception("QR Code inválido");
    if ($order["status"] !== "purchased") throw new Exception("Pedido não está pronto");
    
    $stmt = $pdo->prepare("SELECT * FROM om_market_deliveries WHERE delivery_id = ? AND is_active = 1");
    $stmt->execute([$delivery_id]);
    $delivery = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$delivery) throw new Exception("Entregador não encontrado");
    
    $palavras = ["BANANA", "LARANJA", "MORANGO", "ABACAXI", "MELANCIA", "UVA", "MANGA", "LIMAO"];
    $delivery_code = $palavras[array_rand($palavras)] . "-" . rand(100, 999);
    
    $pdo->prepare("UPDATE om_market_orders SET delivery_id = ?, delivery_name = ?, status = 'delivering', delivery_code = ?, handoff_at = NOW(), date_modified = NOW() WHERE order_id = ?")->execute([$delivery_id, $delivery["name"], $delivery_code, $order["order_id"]]);
    
    $delivery_first = explode(" ", $delivery["name"])[0];
    $pdo->prepare("INSERT INTO om_market_chat (order_id, sender_type, sender_id, sender_name, message, message_type, date_added) VALUES (?, 'system', 0, 'Sistema', ?, 'text', NOW())")->execute([$order["order_id"], "🚴 $delivery_first coletou seu pedido e está a caminho!"]);
    
    $pdo->commit();
    echo json_encode(["success" => true, "delivery_code" => $delivery_code, "order" => ["order_id" => $order["order_id"], "customer_name" => $order["customer_name"], "address" => $order["shipping_address"]]]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}