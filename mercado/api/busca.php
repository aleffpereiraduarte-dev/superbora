<?php
require_once dirname(__DIR__) . '/config/database.php';
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

session_name("OCSESSID");
session_start();

try {
    $pdo = getPDO();
} catch (Exception $e) {
    echo json_encode(["success" => false, "error" => "Erro DB"]);
    exit;
}

$q = trim($_GET["q"] ?? "");
$partnerId = (int)($_GET["partner_id"] ?? $_SESSION["market_partner_id"] ?? 1);

if (strlen($q) < 2) {
    echo json_encode(["success" => true, "products" => []]);
    exit;
}

$limit = (int)($_GET["limit"] ?? 50);
if ($limit < 1 || $limit > 100) $limit = 50;

try {
    $stmt = $pdo->prepare("
        SELECT pb.product_id as id, pb.product_id, pb.name, pb.brand, pb.image, pb.description,
               pp.price, pp.price_promo
        FROM om_market_products_base pb
        JOIN om_market_products_price pp ON pb.product_id = pp.product_id
        WHERE pp.partner_id = ?
        AND (pb.name LIKE ? OR pb.description LIKE ? OR pb.brand LIKE ?)
        AND pp.price > 0
        ORDER BY pb.name
        LIMIT $limit
    ");

    $busca = "%$q%";
    $stmt->execute([$partnerId, $busca, $busca, $busca]);
    $products = $stmt->fetchAll();

    echo json_encode([
        "success" => true,
        "products" => $products,
        "total" => count($products)
    ]);
} catch (Exception $e) {
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
