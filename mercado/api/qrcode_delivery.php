<?php
require_once __DIR__ . '/../config/database.php';
/**
 * 📱 API DE QR CODE - CONFIRMAÇÃO DE ENTREGA
 */
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

session_start();

try {
    $pdo = getPDO();
} catch (PDOException $e) {
    die(json_encode(["success" => false, "error" => "DB Error"]));
}

$input = json_decode(file_get_contents("php://input"), true) ?? $_POST;
$action = $input["action"] ?? $_GET["action"] ?? "";

// ══════════════════════════════════════════════════════════════════════════════
// GERAR QR CODE PARA CLIENTE
// ══════════════════════════════════════════════════════════════════════════════
if ($action === "generate") {
    $order_id = intval($input["order_id"] ?? $_GET["order_id"] ?? 0);
    $customer_id = intval($input["customer_id"] ?? $_SESSION["customer_id"] ?? 0);
    
    if (!$order_id) {
        echo json_encode(["success" => false, "error" => "Order ID obrigatório"]);
        exit;
    }
    
    // Verificar se pedido pertence ao cliente
    $stmt = $pdo->prepare("SELECT order_id, customer_qr_code, delivery_code FROM om_market_orders WHERE order_id = ?");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        echo json_encode(["success" => false, "error" => "Pedido não encontrado"]);
        exit;
    }
    
    // Se já tem QR, retornar
    if ($order["customer_qr_code"]) {
        echo json_encode([
            "success" => true, 
            "qr_code" => $order["customer_qr_code"],
            "delivery_code" => $order["delivery_code"]
        ]);
        exit;
    }
    
    // Gerar novo QR code único
    $qr_code = "OMD-" . strtoupper(bin2hex(random_bytes(6)));
    
    $stmt = $pdo->prepare("UPDATE om_market_orders SET customer_qr_code = ? WHERE order_id = ?");
    $stmt->execute([$qr_code, $order_id]);
    
    echo json_encode([
        "success" => true, 
        "qr_code" => $qr_code,
        "delivery_code" => $order["delivery_code"]
    ]);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// VALIDAR QR CODE (delivery escaneia)
// ══════════════════════════════════════════════════════════════════════════════
if ($action === "validate") {
    $qr_code = trim($input["qr_code"] ?? "");
    $delivery_id = intval($input["delivery_id"] ?? $_SESSION["delivery_id"] ?? 0);
    
    if (!$qr_code) {
        echo json_encode(["success" => false, "error" => "QR Code obrigatório"]);
        exit;
    }
    
    // Buscar pedido pelo QR
    $stmt = $pdo->prepare("
        SELECT o.*, 
               a.chat_expires_at
        FROM om_market_orders o
        LEFT JOIN om_order_assignments a ON o.order_id = a.order_id
        WHERE o.customer_qr_code = ? OR o.delivery_code = ?
    ");
    $stmt->execute([$qr_code, $qr_code]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        echo json_encode(["success" => false, "error" => "QR Code inválido"]);
        exit;
    }
    
    // Verificar se já foi entregue
    if ($order["status"] === "delivered") {
        echo json_encode(["success" => false, "error" => "Pedido já foi entregue"]);
        exit;
    }
    
    echo json_encode([
        "success" => true,
        "order_id" => $order["order_id"],
        "customer_name" => $order["customer_name"],
        "total" => $order["total"],
        "status" => $order["status"]
    ]);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// CONFIRMAR ENTREGA
// ══════════════════════════════════════════════════════════════════════════════
if ($action === "confirm_delivery") {
    $qr_code = trim($input["qr_code"] ?? "");
    $delivery_id = intval($input["delivery_id"] ?? $_SESSION["delivery_id"] ?? 0);
    
    if (!$qr_code || !$delivery_id) {
        echo json_encode(["success" => false, "error" => "Dados inválidos"]);
        exit;
    }
    
    // Buscar pedido
    $stmt = $pdo->prepare("SELECT * FROM om_market_orders WHERE customer_qr_code = ? OR delivery_code = ?");
    $stmt->execute([$qr_code, $qr_code]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        echo json_encode(["success" => false, "error" => "Pedido não encontrado"]);
        exit;
    }
    
    if ($order["status"] === "delivered") {
        echo json_encode(["success" => false, "error" => "Pedido já entregue"]);
        exit;
    }
    
    try {
        // Atualizar status para entregue
        $stmt = $pdo->prepare("UPDATE om_market_orders SET status = \"delivered\", delivered_at = NOW() WHERE order_id = ?");
        $stmt->execute([$order["order_id"]]);
        
        // Definir expiração do chat (60 minutos)
        $expires = date("Y-m-d H:i:s", strtotime("+60 minutes"));
        $pdo->prepare("UPDATE om_order_assignments SET chat_expires_at = ? WHERE order_id = ?")
            ->execute([$expires, $order["order_id"]]);
        
        // Mensagem de confirmação no chat do cliente
        $msg = "🎉 Seu pedido foi entregue! Obrigado por comprar na OneMundo Mercado. O chat ficará disponível por 60 minutos caso precise de ajuda.";
        $pdo->prepare("INSERT INTO om_order_chat (order_id, sender_type, sender_id, message) VALUES (?, \"system\", 0, ?)")
            ->execute([$order["order_id"], $msg]);
        
        // Mensagem no chat shopper-delivery
        $pdo->prepare("INSERT INTO om_order_chat (order_id, sender_type, sender_id, message, chat_type) VALUES (?, \"system\", 0, \"✅ Entrega confirmada pelo cliente!\", \"delivery\")")
            ->execute([$order["order_id"]]);
        
        echo json_encode([
            "success" => true,
            "message" => "Entrega confirmada com sucesso!",
            "order_id" => $order["order_id"]
        ]);
    } catch (Exception $e) {
        echo json_encode(["success" => false, "error" => $e->getMessage()]);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// VERIFICAR PALAVRA DE SEGURANÇA
// ══════════════════════════════════════════════════════════════════════════════
if ($action === "verify_code") {
    $order_id = intval($input["order_id"] ?? 0);
    $code = strtoupper(trim($input["code"] ?? ""));
    
    if (!$order_id || !$code) {
        echo json_encode(["success" => false, "error" => "Dados inválidos"]);
        exit;
    }
    
    $stmt = $pdo->prepare("SELECT delivery_code FROM om_market_orders WHERE order_id = ?");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        echo json_encode(["success" => false, "error" => "Pedido não encontrado"]);
        exit;
    }
    
    if (strtoupper($order["delivery_code"]) === $code) {
        echo json_encode(["success" => true, "valid" => true]);
    } else {
        echo json_encode(["success" => true, "valid" => false, "error" => "Código incorreto"]);
    }
    exit;
}

echo json_encode(["success" => false, "error" => "Ação inválida"]);
