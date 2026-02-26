<?php
/**
 * API: Shopper Stats - Estatísticas do shopper
 * GET: /api/shopper-stats.php?shopper_id=X
 */
require_once __DIR__ . '/../config/database.php';

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }

try {
    $pdo = getPDO();
} catch (PDOException $e) {
    echo json_encode(["success" => false]); exit;
}

$shopper_id = (int)($_GET["shopper_id"] ?? 0);

if (!$shopper_id) {
    echo json_encode(["success" => false, "error" => "shopper_id required"]); exit;
}

// Dados do shopper
$stmt = $pdo->prepare("SELECT * FROM om_market_shoppers WHERE shopper_id = ?");
$stmt->execute([$shopper_id]);
$shopper = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$shopper) {
    echo json_encode(["success" => false, "error" => "Shopper não encontrado"]); exit;
}

// Estatísticas do dia
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_today,
        SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered_today
    FROM om_market_orders 
    WHERE shopper_id = ? AND DATE(created_at) = CURRENT_DATE
");
$stmt->execute([$shopper_id]);
$today = $stmt->fetch(PDO::FETCH_ASSOC);

// Estatísticas gerais
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_orders,
        AVG(rating) as avg_rating,
        SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as completed
    FROM om_market_orders 
    WHERE shopper_id = ?
");
$stmt->execute([$shopper_id]);
$all = $stmt->fetch(PDO::FETCH_ASSOC);

echo json_encode([
    "success" => true,
    "shopper" => [
        "id" => (int)$shopper['shopper_id'],
        "name" => $shopper['name'],
        "is_online" => (bool)($shopper['is_online'] ?? false),
        "rating" => round((float)($shopper['rating'] ?? 5), 1)
    ],
    "today" => [
        "total" => (int)($today['total_today'] ?? 0),
        "delivered" => (int)($today['delivered_today'] ?? 0)
    ],
    "all_time" => [
        "total" => (int)($all['total_orders'] ?? 0),
        "completed" => (int)($all['completed'] ?? 0),
        "rating" => round((float)($all['avg_rating'] ?? 5), 1)
    ]
]);
