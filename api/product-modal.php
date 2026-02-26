<?php
header("Content-Type: application/json");

if(session_status()===PHP_SESSION_NONE){
    session_name("OCSESSID");
    session_start();
}

require_once dirname(__DIR__) . "/config.php";

$pdo = new PDO(
    "mysql:host=" . DB_HOSTNAME . ";dbname=" . DB_DATABASE . ";charset=utf8mb4",
    DB_USERNAME,
    DB_PASSWORD,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$id = (int)($_GET["id"] ?? 0);
$partner = (int)($_SESSION["market_partner_id"] ?? 4);

if(!$id){
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "ID nao informado"]);
    exit;
}

try {
    $sql = "SELECT pb.*, pp.price, pp.price_promo, pp.stock, c.name as category_name 
            FROM om_market_products_base pb 
            JOIN om_market_products_price pp ON pb.product_id = pp.product_id 
            LEFT JOIN om_market_categories c ON pb.category_id = c.category_id 
            WHERE pb.product_id = ? AND pp.partner_id = ? 
            LIMIT 1";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id, $partner]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if(!$p){
        $sql2 = "SELECT pb.*, pp.price, pp.price_promo, pp.stock, c.name as category_name 
                 FROM om_market_products_base pb 
                 JOIN om_market_products_price pp ON pb.product_id = pp.product_id 
                 LEFT JOIN om_market_categories c ON pb.category_id = c.category_id 
                 WHERE pb.product_id = ? 
                 LIMIT 1";
        $stmt = $pdo->prepare($sql2);
        $stmt->execute([$id]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if(!$p){
        http_response_code(404);
        echo json_encode(["success" => false, "error" => "Produto nao encontrado"]);
        exit;
    }
    
    $related = [];
    if($p["category_id"]){
        $sql3 = "SELECT pb.product_id, pb.name, pb.image, pp.price, pp.price_promo 
                 FROM om_market_products_base pb 
                 JOIN om_market_products_price pp ON pb.product_id = pp.product_id 
                 WHERE pb.category_id = ? AND pb.product_id != ? 
                 LIMIT 6";
        $stmt = $pdo->prepare($sql3);
        $stmt->execute([$p["category_id"], $id]);
        $related = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    echo json_encode([
        "success" => true,
        "product" => [
            "product_id" => $p["product_id"],
            "name" => $p["name"],
            "description" => $p["description"] ?? "",
            "image" => $p["image"],
            "price" => (float)$p["price"],
            "price_promo" => (float)($p["price_promo"] ?? 0),
            "unit" => $p["unit"] ?? "1 un",
            "brand" => $p["brand"] ?? ""
        ],
        "related" => $related
    ]);
    
} catch(Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
