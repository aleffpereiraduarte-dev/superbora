<?php
/**
 * 🔄 WEBHOOK PARA ATUALIZAR STATUS
 * Uso: Admin/Shopper atualizam status do pedido
 */

header("Content-Type: application/json");

require_once "../config.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["success" => false, "error" => "Método inválido"]);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);
$action = $input["action"] ?? "";
$order_id = intval($input["order_id"] ?? 0);

if (!$order_id) {
    echo json_encode(["success" => false, "error" => "Order ID obrigatório"]);
    exit;
}

// Incluir helpers
require_once "../components/order-helpers.php";

// Aceitar pedido (atribui shopper)
if ($action === "accept") {
    $partner_id = intval($input["partner_id"] ?? 1);
    
    $result = atribuirShopperPedido($pdo, $order_id, $partner_id);
    
    if ($result) {
        // Atualizar status
        $pdo->exec("UPDATE om_market_orders SET status = \"confirmed\" WHERE order_id = $order_id");
        
        // Registrar tracking
        $sql = "INSERT INTO om_order_tracking (order_id, status, description) VALUES (:order_id, \"confirmed\", \"Pedido aceito\")";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([":order_id" => $order_id]);
        
        echo json_encode(["success" => true, "data" => $result]);
    } else {
        echo json_encode(["success" => false, "error" => "Erro ao atribuir shopper"]);
    }
    exit;
}

// Atualizar status
if ($action === "update_status") {
    $new_status = $input["status"] ?? "";
    $description = $input["description"] ?? "";
    
    $valid_statuses = ["pending", "confirmed", "preparing", "ready", "delivering", "delivered", "cancelled"];
    
    if (!in_array($new_status, $valid_statuses)) {
        echo json_encode(["success" => false, "error" => "Status inválido"]);
        exit;
    }
    
    // Atualizar pedido
    $sql = "UPDATE om_market_orders SET status = :status WHERE order_id = :order_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([":status" => $new_status, ":order_id" => $order_id]);
    
    // Registrar tracking
    $sql = "INSERT INTO om_order_tracking (order_id, status, description) VALUES (:order_id, :status, :desc)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([":order_id" => $order_id, ":status" => $new_status, ":desc" => $description]);
    
    // Se entregue, marcar chat_expires
    if ($new_status === "delivered") {
        marcarPedidoEntregue($pdo, $order_id);
    }
    
    // Buscar customer_id para notificação
    $sql = "SELECT customer_id FROM om_market_orders WHERE order_id = :order_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([":order_id" => $order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Criar notificação
    $status_labels = [
        "confirmed" => "Pedido confirmado! ✅",
        "preparing" => "Estamos preparando sua compra 👨‍🍳",
        "ready" => "Pedido pronto! Aguardando entregador 📦",
        "delivering" => "Seu pedido saiu para entrega! 🚚",
        "delivered" => "Entrega realizada! 🎉",
        "cancelled" => "Pedido cancelado ❌"
    ];
    
    if (isset($status_labels[$new_status]) && $order) {
        $sql = "INSERT INTO om_order_notifications (order_id, customer_id, title, message, type) 
                VALUES (:order_id, :customer_id, :title, :message, \"status\")";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ":order_id" => $order_id,
            ":customer_id" => $order["customer_id"],
            ":title" => $status_labels[$new_status],
            ":message" => $description ?: $status_labels[$new_status]
        ]);
    }
    
    echo json_encode(["success" => true]);
    exit;
}

echo json_encode(["success" => false, "error" => "Ação inválida"]);
