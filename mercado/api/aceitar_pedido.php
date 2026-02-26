<?php
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }

require_once dirname(__DIR__) . "/config/database.php";

try {
    $pdo = getPDO();
} catch (PDOException $e) {
    echo json_encode(["success" => false, "error" => "Erro de conexão"]); exit;
}

$input = json_decode(file_get_contents("php://input"), true);
if (!$input) $input = $_POST;

$order_id = isset($input["order_id"]) ? (int)$input["order_id"] : 0;
$shopper_id = isset($input["shopper_id"]) ? (int)$input["shopper_id"] : 0;

if (!$order_id || !$shopper_id) {
    echo json_encode(["success" => false, "error" => "order_id e shopper_id obrigatórios"]); exit;
}

try {
    $pdo->beginTransaction();
    
    $stmt = $pdo->prepare("SELECT * FROM om_market_orders WHERE order_id = ?");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) throw new Exception("Pedido não encontrado");
    if (!in_array($order["status"], ["pending", "confirmed"])) throw new Exception("Pedido não disponível");
    if ($order["shopper_id"] && $order["shopper_id"] != $shopper_id) throw new Exception("Pedido já aceito");
    
    $stmt = $pdo->prepare("SELECT shopper_id, name, phone FROM om_market_shoppers WHERE shopper_id = ? AND status = 'active'");
    $stmt->execute([$shopper_id]);
    $shopper = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$shopper) throw new Exception("Shopper não encontrado");
    
    $shopper_name = trim($shopper["name"]) ?: "Shopper #$shopper_id";
    
    $stmt = $pdo->prepare("UPDATE om_market_orders SET shopper_id = ?, shopper_name = ?, shopper_phone = ?, status = 'confirmed', shopper_accepted_at = NOW(), confirmed_at = NOW(), date_modified = NOW(), chat_enabled = 1 WHERE order_id = ?");
    $stmt->execute([$shopper_id, $shopper_name, $shopper["phone"], $order_id]);
    
    $customer_first = explode(" ", trim($order["customer_name"]))[0];
    $shopper_first = explode(" ", $shopper_name)[0];
    $hora = (int)date("H");
    $saudacao = ($hora >= 5 && $hora < 12) ? "Bom dia" : (($hora >= 12 && $hora < 18) ? "Boa tarde" : "Boa noite");
    
    $mensagem = "$saudacao, $customer_first! 😊\n\nMe chamo $shopper_first e serei responsável pelo seu pedido!\n\nEstou começando a separar seus produtos agora. Qualquer dúvida, me chama aqui! 💚";
    
    $stmt = $pdo->prepare("INSERT INTO om_market_chat (order_id, sender_type, sender_id, sender_name, message, message_type, date_added) VALUES (?, 'shopper', ?, ?, ?, 'text', NOW())");
    $stmt->execute([$order_id, $shopper_id, $shopper_name, $mensagem]);
    
    $stmt = $pdo->prepare("INSERT INTO om_market_chat (order_id, sender_type, sender_id, sender_name, message, message_type, date_added) VALUES (?, 'system', 0, 'Sistema', ?, 'text', NOW())");
    $stmt->execute([$order_id, "✅ $shopper_first aceitou o pedido!"]);
    
    $pdo->commit();
    echo json_encode(["success" => true, "message" => "Pedido aceito!", "shopper_name" => $shopper_name]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}