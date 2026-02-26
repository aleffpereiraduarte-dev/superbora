<?php
/**
 * API: Partner Products - Produtos de um mercado parceiro
 * GET: /api/partner-products.php?partner_id=X&category=Y&search=Z&page=1
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

$partner_id = (int)($_GET["partner_id"] ?? 0);
$category = $_GET["category"] ?? "";
$search = $_GET["search"] ?? "";
$page = max(1, (int)($_GET["page"] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

$where = ["partner_id = ?", "status = '1'"];
$params = [$partner_id];

if ($category) {
    $where[] = "category_id = ?";
    $params[] = $category;
}

if ($search) {
    $where[] = "(name LIKE ? OR description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$where_sql = implode(" AND ", $where);

// Total
$stmt = $pdo->prepare("SELECT COUNT(*) FROM om_market_products WHERE $where_sql");
$stmt->execute($params);
$total = $stmt->fetchColumn();

// Produtos
$params[] = $limit;
$params[] = $offset;
$stmt = $pdo->prepare("
    SELECT product_id, name, description, price, special_price, image, quantity, unit
    FROM om_market_products 
    WHERE $where_sql
    ORDER BY sort_order, name
    LIMIT ? OFFSET ?
");
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    "success" => true,
    "products" => $products,
    "pagination" => [
        "page" => $page,
        "limit" => $limit,
        "total" => (int)$total,
        "pages" => ceil($total / $limit)
    ]
]);