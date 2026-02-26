<?php
require_once dirname(__DIR__) . '/config/database.php';
/**
 * 🔄 API PARA REPETIR PEDIDO
 */
header("Content-Type: application/json");
session_start();

try {
    $pdo = getPDO();
} catch (PDOException $e) {
    die(json_encode(array("success" => false, "error" => "DB Error")));
}

$input = json_decode(file_get_contents("php://input"), true);
$action = isset($input["action"]) ? $input["action"] : (isset($_GET["action"]) ? $_GET["action"] : "");

switch ($action) {
    case "reorder":
        $order_id = isset($input["order_id"]) ? intval($input["order_id"]) : 0;
        
        if (!$order_id) {
            echo json_encode(array("success" => false, "error" => "order_id required"));
            exit;
        }
        
        // Buscar produtos do pedido original
        $stmt = $pdo->prepare("
            SELECT product_id, quantity
            FROM om_market_order_items
            WHERE order_id = ?
        ");
        $stmt->execute(array($order_id));
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($items)) {
            echo json_encode(array("success" => false, "error" => "Pedido sem produtos"));
            exit;
        }
        
        // Adicionar ao carrinho (session ou tabela)
        if (!isset($_SESSION["cart"])) {
            $_SESSION["cart"] = array();
        }
        
        foreach ($items as $item) {
            $_SESSION["cart"][$item["product_id"]] = array(
                "product_id" => $item["product_id"],
                "quantity" => $item["quantity"]
            );
        }
        
        echo json_encode(array(
            "success" => true,
            "message" => count($items) . " itens adicionados ao carrinho",
            "redirect" => "/mercado/carrinho.php"
        ));
        break;
        
    case "get_order_stats":
        $customer_id = isset($_SESSION["customer_id"]) ? $_SESSION["customer_id"] : 0;
        
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status IN (\"completed\", \"delivered\") THEN 1 ELSE 0 END) as completed,
                SUM(total) as total_spent,
                MAX(created_at) as last_order
            FROM om_market_orders
            WHERE customer_id = ?
        ");
        $stmt->execute(array($customer_id));
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode(array("success" => true, "stats" => $stats));
        break;
        
    default:
        echo json_encode(array("success" => false, "error" => "Invalid action"));
}
