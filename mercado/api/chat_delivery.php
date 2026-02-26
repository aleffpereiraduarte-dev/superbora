<?php
/**
 * 💬 API DE CHAT SHOPPER ↔ DELIVERY
 */
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

session_start();

require_once dirname(__DIR__) . '/config/database.php';

try {
    $pdo = getPDO();
} catch (PDOException $e) {
    die(json_encode(["success" => false, "error" => "DB Error"]));
}

$input = json_decode(file_get_contents("php://input"), true) ?? $_POST;
$action = $input["action"] ?? $_GET["action"] ?? "";

// ══════════════════════════════════════════════════════════════════════════════
// ENVIAR MENSAGEM
// ══════════════════════════════════════════════════════════════════════════════
if ($action === "send") {
    $order_id = intval($input["order_id"] ?? 0);
    $message = trim($input["message"] ?? "");
    $sender_type = $input["sender_type"] ?? ""; // shopper ou delivery
    $sender_id = intval($input["sender_id"] ?? 0);
    
    if (!$order_id || !$message || !$sender_type || !$sender_id) {
        echo json_encode(["success" => false, "error" => "Dados inválidos"]);
        exit;
    }
    
    // Buscar nome do sender
    if ($sender_type === "shopper") {
        $stmt = $pdo->prepare("SELECT name FROM om_market_shoppers WHERE shopper_id = ?");
    } else {
        $stmt = $pdo->prepare("SELECT name FROM om_market_deliveries WHERE delivery_id = ?");
    }
    $stmt->execute([$sender_id]);
    $sender_name = $stmt->fetchColumn() ?: ucfirst($sender_type);
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO om_order_chat (order_id, sender_type, sender_id, sender_name, message, chat_type) 
            VALUES (?, ?, ?, ?, ?, \"delivery\")
        ");
        $stmt->execute([$order_id, $sender_type, $sender_id, $sender_name, $message]);
        
        echo json_encode(["success" => true, "chat_id" => $pdo->lastInsertId()]);
    } catch (Exception $e) {
        echo json_encode(["success" => false, "error" => $e->getMessage()]);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// BUSCAR MENSAGENS
// ══════════════════════════════════════════════════════════════════════════════
if ($action === "get") {
    $order_id = intval($_GET["order_id"] ?? $input["order_id"] ?? 0);
    $last_id = intval($_GET["last_id"] ?? $input["last_id"] ?? 0);
    
    if (!$order_id) {
        echo json_encode(["success" => false, "error" => "Order ID obrigatório"]);
        exit;
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT chat_id, sender_type, sender_id, sender_name, message, created_at
            FROM om_order_chat 
            WHERE order_id = ? AND chat_type = \"delivery\" AND chat_id > ?
            ORDER BY created_at ASC
        ");
        $stmt->execute([$order_id, $last_id]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(["success" => true, "messages" => $messages]);
    } catch (Exception $e) {
        echo json_encode(["success" => false, "error" => $e->getMessage()]);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// MARCAR COMO LIDO
// ══════════════════════════════════════════════════════════════════════════════
if ($action === "read") {
    $order_id = intval($input["order_id"] ?? 0);
    $reader_type = $input["reader_type"] ?? "";
    
    if (!$order_id || !$reader_type) {
        echo json_encode(["success" => false]);
        exit;
    }
    
    // Marcar mensagens do outro como lidas
    $other_type = ($reader_type === "shopper") ? "delivery" : "shopper";
    
    $stmt = $pdo->prepare("UPDATE om_order_chat SET is_read = 1 WHERE order_id = ? AND chat_type = \"delivery\" AND sender_type = ?");
    $stmt->execute([$order_id, $other_type]);
    
    echo json_encode(["success" => true]);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// MENSAGEM AUTOMÁTICA QUANDO DELIVERY ACEITA
// ══════════════════════════════════════════════════════════════════════════════
if ($action === "delivery_accepted") {
    $order_id = intval($input["order_id"] ?? 0);
    $delivery_id = intval($input["delivery_id"] ?? 0);
    $shopper_id = intval($input["shopper_id"] ?? 0);
    
    if (!$order_id || !$delivery_id) {
        echo json_encode(["success" => false, "error" => "Dados inválidos"]);
        exit;
    }
    
    // Buscar nomes
    $stmt = $pdo->prepare("SELECT name FROM om_market_deliveries WHERE delivery_id = ?");
    $stmt->execute([$delivery_id]);
    $delivery_name = $stmt->fetchColumn() ?: "Delivery";
    
    // Mensagem automática do sistema
    $auto_msg = "🚴 $delivery_name aceitou a entrega e está a caminho do mercado!";
    
    $stmt = $pdo->prepare("
        INSERT INTO om_order_chat (order_id, sender_type, sender_id, sender_name, message, chat_type) 
        VALUES (?, \"system\", 0, \"Sistema\", ?, \"delivery\")
    ");
    $stmt->execute([$order_id, $auto_msg]);
    
    // Atualizar delivery_id no pedido
    $pdo->prepare("UPDATE om_market_orders SET delivery_id = ? WHERE order_id = ?")->execute([$delivery_id, $order_id]);
    
    echo json_encode(["success" => true]);
    exit;
}

echo json_encode(["success" => false, "error" => "Ação inválida"]);
