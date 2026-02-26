<?php
/**
 * API: Delivery Stats - Estatísticas do entregador
 * GET: /api/delivery-stats.php?delivery_id=X
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

$delivery_id = (int)($_GET["delivery_id"] ?? 0);

if (!$delivery_id) {
    echo json_encode(["success" => false, "error" => "delivery_id required"]); exit;
}

$stmt = $pdo->prepare("SELECT * FROM om_market_deliveries WHERE delivery_id = ?");
$stmt->execute([$delivery_id]);
$delivery = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$delivery) {
    echo json_encode(["success" => false, "error" => "Delivery não encontrado"]); exit;
}

// Entregas hoje
$stmt = $pdo->prepare("
    SELECT COUNT(*) as count
    FROM om_market_orders 
    WHERE delivery_id = ? AND status = 'delivered' AND DATE(delivered_at) = CURRENT_DATE
");
$stmt->execute([$delivery_id]);
$today = $stmt->fetch(PDO::FETCH_ASSOC);

// Ganhos hoje
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(delivery_fee), 0) as earnings
    FROM om_market_orders 
    WHERE delivery_id = ? AND status = 'delivered' AND DATE(delivered_at) = CURRENT_DATE
");
$stmt->execute([$delivery_id]);
$earnings = $stmt->fetch(PDO::FETCH_ASSOC);

echo json_encode([
    "success" => true,
    "delivery" => [
        "id" => (int)$delivery['delivery_id'],
        "name" => $delivery['name'],
        "is_online" => (bool)($delivery['is_online'] ?? false),
        "vehicle" => $delivery['vehicle_type'] ?? 'moto',
        "rating" => round((float)($delivery['rating'] ?? 5), 1)
    ],
    "today" => [
        "deliveries" => (int)($today['count'] ?? 0),
        "earnings" => (float)($earnings['earnings'] ?? 0)
    ],
    "all_time" => [
        "total_deliveries" => (int)($delivery['total_deliveries'] ?? 0),
        "total_earnings" => (float)($delivery['total_earnings'] ?? 0)
    ]
]);
