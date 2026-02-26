<?php
/**
 * ONEMUNDO MERCADO - BUSCA INTELIGENTE
 * Busca produtos na tabela om_market_products
 */

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");

require_once __DIR__ . '/../config.php';

// Conexao
try {
    $pdo = getDB();
} catch (PDOException $e) {
    echo json_encode(["error" => "Database error"]);
    exit;
}

// Parametros
$query = trim($_GET["q"] ?? "");
$partner_id = intval($_GET["partner_id"] ?? 1);
$limit = min(intval($_GET["limit"] ?? 40), 40);

if (empty($query)) {
    echo json_encode(["error" => "Query required", "products" => []]);
    exit;
}

// Buscar produtos
$terms = preg_split("/\s+/", $query);
$where_parts = [];
$params = [];

foreach ($terms as $i => $term) {
    if (strlen($term) < 2) continue;
    $where_parts[] = "(p.name LIKE :term{$i} OR p.description LIKE :desc{$i} OR p.category LIKE :cat{$i})";
    $params[":term{$i}"] = "%{$term}%";
    $params[":desc{$i}"] = "%{$term}%";
    $params[":cat{$i}"] = "%{$term}%";
}

if (empty($where_parts)) {
    echo json_encode(["error" => "Invalid query", "products" => []]);
    exit;
}

$where_sql = "(" . implode(" OR ", $where_parts) . ")";

// Query usando tabela om_market_products
$sql = "
    SELECT
        p.product_id,
        p.partner_id,
        p.name,
        p.description,
        p.price,
        p.special_price,
        p.quantity,
        p.image,
        p.category,
        p.unit,
        p.barcode,
        p.sku
    FROM om_market_products p
    WHERE p.partner_id = :partner_id
        AND p.status = '1'
        AND p.quantity > 0
        AND {$where_sql}
    ORDER BY
        CASE WHEN p.name LIKE :exact THEN 0 ELSE 1 END,
        p.special_price IS NOT NULL DESC,
        p.name ASC
    LIMIT {$limit}
";

$params[":partner_id"] = $partner_id;
$params[":exact"] = "%{$terms[0]}%";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Formatar
    foreach ($products as &$p) {
        $p["price"] = floatval($p["price"]);
        $p["special_price"] = $p["special_price"] ? floatval($p["special_price"]) : null;

        if ($p["image"]) {
            $p["image_url"] = (preg_match("/^https?:\/\//", $p["image"])) ? $p["image"] : "/mercado/uploads/products/" . $p["image"];
        } else {
            $p["image_url"] = "/mercado/assets/img/no-image.png";
        }
    }

    echo json_encode([
        "query" => $query,
        "partner_id" => $partner_id,
        "products" => $products,
        "alternatives" => [],
        "total_found" => count($products)
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(["error" => $e->getMessage(), "products" => []]);
}
