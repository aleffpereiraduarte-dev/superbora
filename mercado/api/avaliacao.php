<?php
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }

require_once dirname(__DIR__) . "/config/database.php";

$pdo = getPDO();

if ($_SERVER["REQUEST_METHOD"] === "GET") {
    $order_id = (int)($_GET["order_id"] ?? 0);
    if (!$order_id) { echo json_encode(["success" => false, "error" => "order_id obrigatório"]); exit; }
    
    $stmt = $pdo->prepare("SELECT * FROM om_market_orders WHERE order_id = ?");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->prepare("SELECT * FROM om_market_ratings WHERE order_id = ?");
    $stmt->execute([$order_id]);
    $rating = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode(["success" => true, "can_rate" => ($order && $order["status"] === "delivered" && !$rating), "rating" => $rating]);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $input = json_decode(file_get_contents("php://input"), true) ?: $_POST;
    $order_id = (int)($input["order_id"] ?? 0);
    
    if (!$order_id) { echo json_encode(["success" => false, "error" => "order_id obrigatório"]); exit; }
    
    $stmt = $pdo->prepare("SELECT * FROM om_market_orders WHERE order_id = ?");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order || $order["status"] !== "delivered") {
        echo json_encode(["success" => false, "error" => "Pedido não pode ser avaliado"]); exit;
    }
    
    $stmt = $pdo->prepare("SELECT rating_id FROM om_market_ratings WHERE order_id = ?");
    $stmt->execute([$order_id]);
    if ($stmt->fetch()) { echo json_encode(["success" => false, "error" => "Já avaliado"]); exit; }
    
    $shopper_rating = max(1, min(5, (int)($input["shopper_rating"] ?? 5)));
    $delivery_rating = max(1, min(5, (int)($input["delivery_rating"] ?? 5)));
    $comment = trim($input["comment"] ?? "");
    $tip = max(0, (float)($input["tip_amount"] ?? 0));
    
    $pdo->beginTransaction();
    
    $stmt = $pdo->prepare("INSERT INTO om_market_ratings (order_id, customer_id, shopper_id, delivery_id, shopper_rating, delivery_rating, comment, tip_amount, tip_delivery) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$order_id, $order["customer_id"], $order["shopper_id"], $order["delivery_id"], $shopper_rating, $delivery_rating, $comment, $tip, $tip]);
    
    if ($order["shopper_id"]) {
        $pdo->prepare("UPDATE om_market_shoppers SET total_ratings = total_ratings + 1, sum_ratings = sum_ratings + ?, rating = (sum_ratings + ?) / (total_ratings + 1) WHERE shopper_id = ?")->execute([$shopper_rating, $shopper_rating, $order["shopper_id"]]);
    }
    if ($order["delivery_id"]) {
        $pdo->prepare("UPDATE om_market_deliveries SET total_ratings = total_ratings + 1, sum_ratings = sum_ratings + ?, avg_rating = (sum_ratings + ?) / (total_ratings + 1) WHERE delivery_id = ?")->execute([$delivery_rating, $delivery_rating, $order["delivery_id"]]);
    }
    
    $pdo->prepare("UPDATE om_market_orders SET rated = 1, rated_at = NOW() WHERE order_id = ?")->execute([$order_id]);
    
    $pdo->commit();
    echo json_encode(["success" => true, "message" => "Avaliação enviada!"]);
    exit;
}