<?php
require_once dirname(__DIR__) . '/config/database.php';
/**
 * ⭐ API DE AVALIAÇÃO
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
    
    // ══════════════════════════════════════════════════════════════════════════
    // ENVIAR AVALIAÇÃO
    // ══════════════════════════════════════════════════════════════════════════
    case "submit":
        $order_id = isset($input["order_id"]) ? intval($input["order_id"]) : 0;
        $customer_id = isset($_SESSION["customer_id"]) ? $_SESSION["customer_id"] : (isset($input["customer_id"]) ? intval($input["customer_id"]) : 0);
        
        if (!$order_id) {
            echo json_encode(array("success" => false, "error" => "order_id required"));
            exit;
        }
        
        // Buscar pedido
        $stmt = $pdo->prepare("SELECT * FROM om_market_orders WHERE order_id = ?");
        $stmt->execute(array($order_id));
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            echo json_encode(array("success" => false, "error" => "Pedido não encontrado"));
            exit;
        }
        
        // Verificar se já foi avaliado
        $stmt = $pdo->prepare("SELECT rating_id FROM om_order_ratings WHERE order_id = ?");
        $stmt->execute(array($order_id));
        if ($stmt->fetch()) {
            echo json_encode(array("success" => false, "error" => "Pedido já foi avaliado"));
            exit;
        }
        
        // Dados da avaliação
        $shopper_rating = isset($input["shopper_rating"]) ? intval($input["shopper_rating"]) : null;
        $shopper_comment = isset($input["shopper_comment"]) ? trim($input["shopper_comment"]) : null;
        $shopper_tags = isset($input["shopper_tags"]) ? implode(",", $input["shopper_tags"]) : null;
        
        $delivery_rating = isset($input["delivery_rating"]) ? intval($input["delivery_rating"]) : null;
        $delivery_comment = isset($input["delivery_comment"]) ? trim($input["delivery_comment"]) : null;
        $delivery_tags = isset($input["delivery_tags"]) ? implode(",", $input["delivery_tags"]) : null;
        
        $overall_rating = isset($input["overall_rating"]) ? intval($input["overall_rating"]) : null;
        $overall_comment = isset($input["overall_comment"]) ? trim($input["overall_comment"]) : null;
        
        // Inserir avaliação
        $stmt = $pdo->prepare("
            INSERT INTO om_order_ratings 
            (order_id, customer_id, shopper_id, shopper_rating, shopper_comment, shopper_tags,
             delivery_id, delivery_rating, delivery_comment, delivery_tags, overall_rating, overall_comment)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute(array(
            $order_id, $customer_id,
            $order["shopper_id"], $shopper_rating, $shopper_comment, $shopper_tags,
            $order["delivery_id"], $delivery_rating, $delivery_comment, $delivery_tags,
            $overall_rating, $overall_comment
        ));
        
        // Atualizar média do shopper
        if ($order["shopper_id"] && $shopper_rating) {
            $pdo->prepare("
                UPDATE om_market_shoppers 
                SET total_ratings = total_ratings + 1,
                    sum_ratings = sum_ratings + ?,
                    avg_rating = (sum_ratings + ?) / (total_ratings + 1)
                WHERE shopper_id = ?
            ")->execute(array($shopper_rating, $shopper_rating, $order["shopper_id"]));
        }
        
        // Atualizar média do delivery
        if ($order["delivery_id"] && $delivery_rating) {
            $pdo->prepare("
                UPDATE om_market_deliveries 
                SET total_ratings = total_ratings + 1,
                    sum_ratings = sum_ratings + ?,
                    avg_rating = (sum_ratings + ?) / (total_ratings + 1)
                WHERE delivery_id = ?
            ")->execute(array($delivery_rating, $delivery_rating, $order["delivery_id"]));
        }
        
        // Marcar pedido como avaliado
        $pdo->prepare("UPDATE om_market_orders SET is_rated = 1 WHERE order_id = ?")->execute(array($order_id));
        
        echo json_encode(array("success" => true, "message" => "Avaliação enviada com sucesso!"));
        break;
    
    // ══════════════════════════════════════════════════════════════════════════
    // VERIFICAR SE PODE AVALIAR
    // ══════════════════════════════════════════════════════════════════════════
    case "check":
        $order_id = isset($_GET["order_id"]) ? intval($_GET["order_id"]) : 0;
        
        $stmt = $pdo->prepare("
            SELECT o.*, r.rating_id,
                   s.name as shopper_name,
                   d.name as delivery_name
            FROM om_market_orders o
            LEFT JOIN om_order_ratings r ON o.order_id = r.order_id
            LEFT JOIN om_market_shoppers s ON o.shopper_id = s.shopper_id
            LEFT JOIN om_market_deliveries d ON o.delivery_id = d.delivery_id
            WHERE o.order_id = ?
        ");
        $stmt->execute(array($order_id));
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            echo json_encode(array("success" => false, "can_rate" => false, "reason" => "not_found"));
            exit;
        }
        
        $can_rate = ($order["status"] == "delivered" || $order["status"] == "completed") && !$order["rating_id"];
        
        echo json_encode(array(
            "success" => true,
            "can_rate" => $can_rate,
            "is_rated" => (bool)$order["rating_id"],
            "status" => $order["status"],
            "shopper_name" => $order["shopper_name"],
            "delivery_name" => $order["delivery_name"],
            "has_shopper" => (bool)$order["shopper_id"],
            "has_delivery" => (bool)$order["delivery_id"]
        ));
        break;
    
    // ══════════════════════════════════════════════════════════════════════════
    // BUSCAR AVALIAÇÃO EXISTENTE
    // ══════════════════════════════════════════════════════════════════════════
    case "get":
        $order_id = isset($_GET["order_id"]) ? intval($_GET["order_id"]) : 0;
        
        $stmt = $pdo->prepare("SELECT * FROM om_order_ratings WHERE order_id = ?");
        $stmt->execute(array($order_id));
        $rating = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode(array("success" => true, "rating" => $rating));
        break;
    
    default:
        echo json_encode(array("success" => false, "error" => "Invalid action"));
}
