<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { exit; }

require_once __DIR__ . "/../config/database.php";

$action = $_GET["action"] ?? $_POST["action"] ?? "scan";
$response = ["success" => false];

try {
    switch ($action) {
        case "scan":
            $barcode = $_GET["barcode"] ?? $_POST["barcode"] ?? "";
            $order_id = $_GET["order_id"] ?? $_POST["order_id"] ?? 0;
            
            if (empty($barcode)) {
                throw new Exception("Código de barras obrigatório");
            }
            
            // Buscar produto pelo código de barras
            $stmt = $pdo->prepare("
                SELECT p.*, oi.quantity as order_qty, oi.collected_qty, oi.id as item_id
                FROM om_market_products_base p
                LEFT JOIN om_market_order_items oi ON oi.product_id = p.id AND oi.order_id = ?
                WHERE p.barcode = ? OR p.ean = ?
                LIMIT 1
            ");
            $stmt->execute([$order_id, $barcode, $barcode]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$product) {
                // Tentar buscar em oc_product
                $stmt = $pdo->prepare("SELECT * FROM oc_product WHERE ean = ? OR upc = ? LIMIT 1");
                $stmt->execute([$barcode, $barcode]);
                $product = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            
            if ($product) {
                $response = [
                    "success" => true,
                    "product" => [
                        "id" => $product["id"] ?? $product["product_id"],
                        "name" => $product["name"] ?? $product["nome"],
                        "barcode" => $barcode,
                        "price" => $product["price"] ?? $product["preco"] ?? 0,
                        "image" => $product["image"] ?? $product["imagem"] ?? "",
                        "order_qty" => $product["order_qty"] ?? 1,
                        "collected_qty" => $product["collected_qty"] ?? 0,
                        "item_id" => $product["item_id"] ?? null,
                        "in_order" => !empty($product["item_id"])
                    ]
                ];
            } else {
                $response = [
                    "success" => false,
                    "message" => "Produto não encontrado",
                    "barcode" => $barcode
                ];
            }
            break;
            
        case "collect":
            $input = json_decode(file_get_contents("php://input"), true);
            $item_id = $input["item_id"] ?? 0;
            $qty = $input["qty"] ?? 1;
            
            if ($item_id) {
                $stmt = $pdo->prepare("UPDATE om_market_order_items SET collected_qty = collected_qty + ?, collected_at = NOW() WHERE id = ?");
                $stmt->execute([$qty, $item_id]);
                $response = ["success" => true, "message" => "Produto coletado"];
            } else {
                throw new Exception("Item não especificado");
            }
            break;
            
        case "validate":
            $order_id = $_GET["order_id"] ?? 0;
            $stmt = $pdo->prepare("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN collected_qty >= quantity THEN 1 ELSE 0 END) as collected
                FROM om_market_order_items 
                WHERE order_id = ?
            ");
            $stmt->execute([$order_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $response = [
                "success" => true,
                "total_items" => (int)$result["total"],
                "collected_items" => (int)$result["collected"],
                "complete" => $result["total"] == $result["collected"]
            ];
            break;
            
        default:
            throw new Exception("Ação inválida");
    }
} catch (Exception $e) {
    $response = ["success" => false, "message" => $e->getMessage()];
}

echo json_encode($response);