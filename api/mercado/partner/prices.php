<?php
/**
 * GET /api/mercado/partner/prices.php
 * Partner's priced products list
 * Params: search, page, limit, sort (price_asc, price_desc, name)
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

    $search = trim($_GET['search'] ?? '');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    $sort = trim($_GET['sort'] ?? 'name');

    // Build WHERE
    $where = ["pp.partner_id = ?"];
    $params = [$partner_id];

    if ($search !== '') {
        $searchEsc = str_replace(['%', '_'], ['\\%', '\\_'], $search);
        $where[] = "(pb.name ILIKE ? OR pb.barcode ILIKE ?)";
        $searchParam = "%{$searchEsc}%";
        $params[] = $searchParam;
        $params[] = $searchParam;
    }

    $whereSQL = implode(" AND ", $where);

    // Sort
    $orderBy = match($sort) {
        'price_asc' => 'pp.price ASC',
        'price_desc' => 'pp.price DESC',
        'name' => 'pb.name ASC',
        'stock' => 'pp.stock DESC',
        'updated' => 'pp.date_modified DESC',
        default => 'pb.name ASC'
    };

    // Count
    $stmtCount = $db->prepare("
        SELECT COUNT(*)
        FROM om_market_products_price pp
        INNER JOIN om_market_products_base pb ON pb.product_id = pp.product_id
        WHERE {$whereSQL}
    ");
    $stmtCount->execute($params);
    $total = (int)$stmtCount->fetchColumn();

    // Fetch
    
    $stmt = $db->prepare("
        SELECT
            pp.id as price_id,
            pp.product_id,
            pp.price,
            pp.price_promo as promotional_price,
            pp.stock,
            pp.status,
            pp.date_modified as updated_at,
            pb.name,
            pb.barcode,
            pb.image,
            pb.brand,
            pb.unit,
            cat.name as category_name
        FROM om_market_products_price pp
        INNER JOIN om_market_products_base pb ON pb.product_id = pp.product_id
        LEFT JOIN om_market_categories cat ON cat.category_id = pb.category_id
        WHERE {$whereSQL}
        ORDER BY {$orderBy}
        LIMIT ? OFFSET ?
    ");
    $stmt->execute(array_merge($params, [$limit, $offset]));
    $items = $stmt->fetchAll();

    $formatted = [];
    foreach ($items as $item) {
        $formatted[] = [
            "price_id" => (int)$item['price_id'],
            "product_id" => (int)$item['product_id'],
            "name" => $item['name'],
            "barcode" => $item['barcode'],
            "image" => $item['image'],
            "brand" => $item['brand'],
            "unit" => $item['unit'],
            "category_name" => $item['category_name'],
            "price" => (float)$item['price'],
            "promotional_price" => $item['promotional_price'] ? (float)$item['promotional_price'] : null,
            "stock" => (int)$item['stock'],
            "status" => (int)$item['status'],
            "updated_at" => $item['updated_at']
        ];
    }

    $pages = $total > 0 ? ceil($total / $limit) : 1;

    response(true, [
        "items" => $formatted,
        "pagination" => [
            "total" => $total,
            "page" => $page,
            "pages" => (int)$pages,
            "limit" => $limit
        ]
    ], "Precos listados");

} catch (Exception $e) {
    error_log("[partner/prices] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
