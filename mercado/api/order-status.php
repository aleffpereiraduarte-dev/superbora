<?php
/**
 * 📦 API DE STATUS DO PEDIDO
 * Endpoints: status, tracking, validate_code
 */

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

require_once "../config.php";

$customer = getOpenCartCustomer();
$customer_id = $customer["id"] ?? 0;

// GET: Buscar status
if ($_SERVER["REQUEST_METHOD"] === "GET") {
    $action = $_GET["action"] ?? "status";
    $order_id = intval($_GET["order_id"] ?? 0);
    
    if ($action === "status" && $order_id > 0) {
        $sql = "SELECT 
                    o.order_id,
                    o.status,
                    o.total,
                    o.created_at,
                    a.delivery_code,
                    a.delivered_at,
                    a.chat_expires_at,
                    s.name as shopper_name,
                    s.avatar as shopper_avatar
                FROM om_market_orders o
                LEFT JOIN om_order_assignments a ON o.order_id = a.order_id
                LEFT JOIN om_order_shoppers s ON a.shopper_id = s.shopper_id
                WHERE o.order_id = :order_id AND o.customer_id = :customer_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([":order_id" => $order_id, ":customer_id" => $customer_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($order) {
            echo json_encode(["success" => true, "order" => $order]);
        } else {
            echo json_encode(["success" => false, "error" => "Pedido não encontrado"]);
        }
        exit;
    }
    
    if ($action === "active") {
        // Buscar pedido ativo
        $sql = "SELECT 
                    o.order_id,
                    o.status,
                    o.total,
                    a.delivery_code,
                    a.chat_expires_at,
                    s.name as shopper_name,
                    s.avatar as shopper_avatar
                FROM om_market_orders o
                LEFT JOIN om_order_assignments a ON o.order_id = a.order_id
                LEFT JOIN om_order_shoppers s ON a.shopper_id = s.shopper_id
                WHERE o.customer_id = :customer_id
                AND (
                    o.status NOT IN (\"cancelled\", \"delivered\")
                    OR (o.status = \"delivered\" AND a.chat_expires_at > NOW())
                )
                ORDER BY o.created_at DESC
                LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([":customer_id" => $customer_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode(["success" => true, "order" => $order]);
        exit;
    }
    
    if ($action === "tracking" && $order_id > 0) {
        $sql = "SELECT * FROM om_order_tracking 
                WHERE order_id = :order_id 
                ORDER BY created_at ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([":order_id" => $order_id]);
        $tracking = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(["success" => true, "tracking" => $tracking]);
        exit;
    }
}

// POST: Ações
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $input = json_decode(file_get_contents("php://input"), true);
    $action = $input["action"] ?? "";
    
    // Validar código de entrega (usado pelo entregador)
    if ($action === "validate_code") {
        $order_id = intval($input["order_id"] ?? 0);
        $code = strtoupper(trim($input["code"] ?? ""));
        
        if (!$order_id || !$code) {
            echo json_encode(["success" => false, "error" => "Dados inválidos"]);
            exit;
        }
        
        require_once "../components/order-helpers.php";
        
        if (validarCodigoEntrega($pdo, $order_id, $code)) {
            echo json_encode(["success" => true, "message" => "Entrega confirmada!"]);
        } else {
            echo json_encode(["success" => false, "error" => "Código inválido"]);
        }
        exit;
    }
}

echo json_encode(["success" => false, "error" => "Requisição inválida"]);
