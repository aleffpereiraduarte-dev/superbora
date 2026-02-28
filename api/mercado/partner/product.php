<?php
/**
 * GET /api/mercado/partner/product.php
 * Single product detail
 * Params: id (product_id) OR barcode
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requirePartner();
    $partner_id = (int)$payload['uid'];

    $product_id = (int)($_GET['id'] ?? 0);
    $barcode = trim($_GET['barcode'] ?? '');

    if (!$product_id && empty($barcode)) {
        response(false, null, "Informe id ou barcode do produto", 400);
    }

    // Build query based on search param
    $where = "pp.partner_id = ?";
    $params = [$partner_id];

    if ($product_id > 0) {
        $where .= " AND pb.product_id = ?";
        $params[] = $product_id;
    } elseif (!empty($barcode)) {
        $where .= " AND pb.barcode = ?";
        $params[] = $barcode;
    }

    $stmt = $db->prepare("
        SELECT
            pb.product_id,
            pb.name,
            pb.description,
            pb.barcode,
            pb.category_id,
            pb.image,
            pb.brand,
            pb.unit,
            pp.id as price_id,
            pp.price,
            pp.price_promo,
            pp.stock,
            pp.status,
            pp.created_at,
            pp.updated_at,
            cat.name as category_name
        FROM om_market_products_base pb
        LEFT JOIN om_market_products_price pp ON pp.product_id = pb.product_id AND pp.partner_id = ?
        LEFT JOIN om_market_categories cat ON cat.category_id = pb.category_id
        WHERE pb.product_id = ? OR pb.barcode = ?
        LIMIT 1
    ");

    // For this query we need to pass partner_id for the JOIN, plus the search criteria
    if ($product_id > 0) {
        $stmt2 = $db->prepare("
            SELECT
                pb.product_id,
                pb.name,
                pb.description,
                pb.barcode,
                pb.category_id,
                pb.image,
                pb.brand,
                pb.unit,
                pp.id as price_id,
                pp.price,
                pp.price_promo,
                pp.stock,
                pp.status as price_status,
                pp.created_at,
                pp.updated_at,
                cat.name as category_name
            FROM om_market_products_base pb
            LEFT JOIN om_market_products_price pp ON pp.product_id = pb.product_id AND pp.partner_id = ?
            LEFT JOIN om_market_categories cat ON cat.category_id = pb.category_id
            WHERE pb.product_id = ?
            LIMIT 1
        ");
        $stmt2->execute([$partner_id, $product_id]);
    } else {
        $stmt2 = $db->prepare("
            SELECT
                pb.product_id,
                pb.name,
                pb.description,
                pb.barcode,
                pb.category_id,
                pb.image,
                pb.brand,
                pb.unit,
                pp.id as price_id,
                pp.price,
                pp.price_promo,
                pp.stock,
                pp.status as price_status,
                pp.created_at,
                pp.updated_at,
                cat.name as category_name
            FROM om_market_products_base pb
            LEFT JOIN om_market_products_price pp ON pp.product_id = pb.product_id AND pp.partner_id = ?
            LEFT JOIN om_market_categories cat ON cat.category_id = pb.category_id
            WHERE pb.barcode = ?
            LIMIT 1
        ");
        $stmt2->execute([$partner_id, $barcode]);
    }

    $product = $stmt2->fetch();

    if (!$product) {
        response(false, null, "Produto nao encontrado", 404);
    }

    $data = [
        "product_id" => (int)$product['product_id'],
        "price_id" => $product['price_id'] ? (int)$product['price_id'] : null,
        "name" => $product['name'],
        "description" => $product['description'],
        "barcode" => $product['barcode'],
        "category_id" => (int)$product['category_id'],
        "category_name" => $product['category_name'],
        "image" => $product['image'],
        "brand" => $product['brand'],
        "unit" => $product['unit'],
        "price" => $product['price'] ? (float)$product['price'] : null,
        "promotional_price" => $product['price_promo'] ? (float)$product['price_promo'] : null,
        "stock" => $product['stock'] !== null ? (int)$product['stock'] : null,
        "status" => $product['price_status'] !== null ? (int)$product['price_status'] : null,
        "has_pricing" => $product['price_id'] !== null,
        "created_at" => $product['created_at'],
        "updated_at" => $product['updated_at']
    ];

    response(true, $data, "Produto encontrado");

} catch (Exception $e) {
    error_log("[partner/product] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
