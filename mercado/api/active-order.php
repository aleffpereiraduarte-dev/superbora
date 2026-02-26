<?php
/**
 * API: Active Order - Verifica se cliente tem pedido ativo
 * GET: /api/active-order.php?customer_id=X
 */
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }

require_once dirname(__DIR__) . "/config/database.php";

try {
    $pdo = getPDO();
} catch (PDOException $e) {
    echo json_encode(["success" => false]); exit;
}

$customer_id = (int)($_GET["customer_id"] ?? 0);

$stmt = $pdo->prepare("
    SELECT order_id, status, delivery_code, total
    FROM om_market_orders 
    WHERE customer_id = ? 
    AND status NOT IN ('cancelled', 'delivered')
    ORDER BY order_id DESC LIMIT 1
");
$stmt->execute([$customer_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

echo json_encode([
    "success" => true,
    "has_active" => (bool)$order,
    "order" => $order ?: null
]);
