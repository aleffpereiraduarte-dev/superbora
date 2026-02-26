<?php
/**
 * API: Order Items - Itens do pedido com status de coleta
 * GET: /api/order-items.php?order_id=X
 */
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }

require_once __DIR__ . '/../config/database.php';

try {
    $pdo = getPDO();
} catch (PDOException $e) {
    echo json_encode(["success" => false]); exit;
}

$order_id = (int)($_GET["order_id"] ?? 0);

if (!$order_id) {
    echo json_encode(["success" => false, "error" => "order_id required"]); exit;
}

$stmt = $pdo->prepare("
    SELECT 
        oi.*,
        p.name as product_name,
        p.image as product_image
    FROM om_market_order_items oi
    LEFT JOIN om_market_products p ON oi.product_id = p.product_id
    WHERE oi.order_id = ?
    ORDER BY oi.item_id
");
$stmt->execute([$order_id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_items = 0;
$picked_items = 0;
$substituted = 0;
$unavailable = 0;

foreach ($items as &$item) {
    $total_items += (int)($item['quantity'] ?? 1);
    if (!empty($item['picked'])) $picked_items += (int)($item['quantity'] ?? 1);
    if (!empty($item['substituted'])) $substituted++;
    if (!empty($item['unavailable'])) $unavailable++;
}

echo json_encode([
    "success" => true,
    "items" => $items,
    "summary" => [
        "total_items" => $total_items,
        "picked_items" => $picked_items,
        "substituted" => $substituted,
        "unavailable" => $unavailable,
        "progress" => $total_items > 0 ? round(($picked_items / $total_items) * 100) : 0
    ]
]);
