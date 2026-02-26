<?php
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }

require_once dirname(__DIR__) . "/config/database.php";

$pdo = getPDO();

$input = json_decode(file_get_contents("php://input"), true) ?: $_POST;
$order_id = (int)($input["order_id"] ?? 0);
$delivery_id = (int)($input["delivery_id"] ?? 0);
$code = strtoupper(trim($input["code"] ?? ""));

if (!$order_id || !$delivery_id || !$code) { echo json_encode(["success" => false, "error" => "Dados inválidos"]); exit; }

try {
    $pdo->beginTransaction();
    
    $stmt = $pdo->prepare("SELECT * FROM om_market_orders WHERE order_id = ? AND delivery_id = ?");
    $stmt->execute([$order_id, $delivery_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) throw new Exception("Pedido não encontrado");
    if ($order["status"] !== "delivering") throw new Exception("Pedido não está em entrega");
    if (strtoupper($order["delivery_code"]) !== $code) { echo json_encode(["success" => false, "error" => "Código incorreto"]); exit; }
    
    $chat_expires_at = date("Y-m-d H:i:s", strtotime("+60 minutes"));
    
    $pdo->prepare("UPDATE om_market_orders SET status = 'delivered', delivered_at = NOW(), chat_expires_at = ?, date_modified = NOW() WHERE order_id = ?")->execute([$chat_expires_at, $order_id]);
    $pdo->prepare("UPDATE om_market_deliveries SET total_deliveries = total_deliveries + 1 WHERE delivery_id = ?")->execute([$delivery_id]);
    $pdo->prepare("INSERT INTO om_market_chat (order_id, sender_type, sender_id, sender_name, message, message_type, date_added) VALUES (?, 'system', 0, 'Sistema', ?, 'text', NOW())")->execute([$order_id, "🎉 Pedido entregue com sucesso!\n\nObrigado por comprar no OneMundo!\n\n💬 O chat ficará disponível por mais 60 minutos."]);
    
    $pdo->commit();
    echo json_encode(["success" => true, "status" => "delivered", "chat_expires_at" => $chat_expires_at]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}