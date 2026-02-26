<?php
/**
 * API Product Modal - OneMundo Mercado
 * Retorna dados do produto para exibicao em modal
 */

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Iniciar sessao
if (session_status() === PHP_SESSION_NONE) {
    session_name("OCSESSID");
    session_start();
}

// Conexao com banco
require_once __DIR__ . '/../config/database.php';

try {
    $pdo = getPDO();
} catch (Exception $e) {
    echo json_encode(["success" => false, "error" => "Erro de conexao"]);
    exit;
}

// Validar ID
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "ID do produto invalido"]);
    exit;
}

$partner = (int)($_SESSION["market_partner_id"] ?? 1);

try {
    $product = null;

    // Tentar tabela om_market_products primeiro
    try {
        $stmt = $pdo->prepare("
            SELECT
                p.product_id,
                p.name,
                p.description,
                p.image,
                p.price,
                p.special_price as price_promo,
                p.quantity as stock,
                p.unit,
                p.category,
                p.category_id,
                c.name as category_name
            FROM om_market_products p
            LEFT JOIN om_market_categories c ON p.category_id = c.category_id
            WHERE p.product_id = ? AND p.partner_id = ? AND p.status = '1'
            LIMIT 1
        ");
        $stmt->execute([$id, $partner]);
        $product = $stmt->fetch();

        // Tentar sem partner_id
        if (!$product) {
            $stmt = $pdo->prepare("
                SELECT
                    p.product_id,
                    p.name,
                    p.description,
                    p.image,
                    p.price,
                    p.special_price as price_promo,
                    p.quantity as stock,
                    p.unit,
                    p.category,
                    p.category_id,
                    c.name as category_name
                FROM om_market_products p
                LEFT JOIN om_market_categories c ON p.category_id = c.category_id
                WHERE p.product_id = ? AND p.status = '1'
                LIMIT 1
            ");
            $stmt->execute([$id]);
            $product = $stmt->fetch();
        }
    } catch (PDOException $e) {
        // Tabela om_market_products nao existe, tentar om_market_products_base
    }

    // Tentar tabela om_market_products_base se nao encontrou
    if (!$product) {
        try {
            $stmt = $pdo->prepare("
                SELECT pb.*, pp.price, pp.price_promo, pp.stock, c.name as category_name
                FROM om_market_products_base pb
                JOIN om_market_products_price pp ON pb.product_id = pp.product_id
                LEFT JOIN om_market_categories c ON pb.category_id = c.category_id
                WHERE pb.product_id = ? AND pp.partner_id = ? AND pp.status = '1'
                LIMIT 1
            ");
            $stmt->execute([$id, $partner]);
            $product = $stmt->fetch();

            // Tentar sem partner
            if (!$product) {
                $stmt = $pdo->prepare("
                    SELECT pb.*, pp.price, pp.price_promo, pp.stock, c.name as category_name
                    FROM om_market_products_base pb
                    JOIN om_market_products_price pp ON pb.product_id = pp.product_id
                    LEFT JOIN om_market_categories c ON pb.category_id = c.category_id
                    WHERE pb.product_id = ? AND pp.status = '1'
                    LIMIT 1
                ");
                $stmt->execute([$id]);
                $product = $stmt->fetch();
            }
        } catch (PDOException $e) {
            // Tabela nao existe
        }
    }

    if (!$product) {
        http_response_code(404);
        echo json_encode(["success" => false, "error" => "Produto nao encontrado"]);
        exit;
    }

    // Buscar produtos relacionados
    $related = [];
    if (!empty($product["category_id"])) {
        try {
            // Tentar om_market_products primeiro
            $stmt = $pdo->prepare("
                SELECT product_id, name, image, price, special_price as price_promo
                FROM om_market_products
                WHERE category_id = ? AND product_id != ? AND status = '1'
                ORDER BY RANDOM()
                LIMIT 6
            ");
            $stmt->execute([$product["category_id"], $id]);
            $related = $stmt->fetchAll();
        } catch (PDOException $e) {
            // Tentar om_market_products_base
            try {
                $stmt = $pdo->prepare("
                    SELECT pb.product_id, pb.name, pb.image, pp.price, pp.price_promo
                    FROM om_market_products_base pb
                    JOIN om_market_products_price pp ON pb.product_id = pp.product_id
                    WHERE pb.category_id = ? AND pb.product_id != ? AND pp.status = '1'
                    ORDER BY RANDOM()
                    LIMIT 6
                ");
                $stmt->execute([$product["category_id"], $id]);
                $related = $stmt->fetchAll();
            } catch (PDOException $e) {
                // Ignorar
            }
        }
    }

    // Formatar resposta
    $price = (float)$product["price"];
    $pricePromo = (float)($product["price_promo"] ?? 0);

    $response = [
        "success" => true,
        "product" => [
            "product_id" => (int)$product["product_id"],
            "name" => $product["name"],
            "description" => $product["description"] ?? "",
            "image" => $product["image"] ?? "",
            "price" => $price,
            "price_promo" => $pricePromo > 0 && $pricePromo < $price ? $pricePromo : null,
            "price_final" => $pricePromo > 0 && $pricePromo < $price ? $pricePromo : $price,
            "has_discount" => $pricePromo > 0 && $pricePromo < $price,
            "discount_percent" => $pricePromo > 0 && $pricePromo < $price
                ? round((1 - $pricePromo / $price) * 100)
                : 0,
            "stock" => (int)($product["stock"] ?? 0),
            "in_stock" => (int)($product["stock"] ?? 0) > 0,
            "unit" => $product["unit"] ?? "1 un",
            "brand" => $product["brand"] ?? "",
            "category" => $product["category_name"] ?? $product["category"] ?? ""
        ],
        "related" => array_map(function($r) {
            $p = (float)$r["price"];
            $pp = (float)($r["price_promo"] ?? 0);
            return [
                "product_id" => (int)$r["product_id"],
                "name" => $r["name"],
                "image" => $r["image"] ?? "",
                "price" => $p,
                "price_promo" => $pp > 0 && $pp < $p ? $pp : null,
                "price_final" => $pp > 0 && $pp < $p ? $pp : $p
            ];
        }, $related)
    ];

    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("Product modal error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "Erro interno"]);
}
