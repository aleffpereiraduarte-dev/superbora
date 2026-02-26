<?php
/**
 * GET /api/mercado/partner/products.php
 * Products list with partner pricing
 * Params: search, category_id, page (default 1), limit (default 20)
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";
require_once __DIR__ . "/../helpers/availability.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requirePartner();
    $partner_id = (int)$payload['uid'];

    $search = trim($_GET['search'] ?? '');
    $category_id = (int)($_GET['category_id'] ?? 0);
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    // Check if partner has products in om_market_products_price (catalog model)
    $stmtCheck = $db->prepare("SELECT COUNT(*) FROM om_market_products_price WHERE partner_id = ?");
    $stmtCheck->execute([$partner_id]);
    $hasPriceTable = (int)$stmtCheck->fetchColumn() > 0;

    if ($hasPriceTable) {
        // Catalog model: om_market_products_price + om_market_products_base
        $where = ["pp.partner_id = ?"];
        $params = [$partner_id];

        if ($search !== '') {
            $searchEsc = str_replace(['%', '_'], ['\\%', '\\_'], $search);
            $where[] = "(pb.name ILIKE ? OR pb.barcode ILIKE ? OR pb.brand ILIKE ?)";
            $searchParam = "%{$searchEsc}%";
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
        }
        if ($category_id > 0) {
            $where[] = "pb.category_id = ?";
            $params[] = $category_id;
        }

        $whereSQL = implode(" AND ", $where);

        $stmtCount = $db->prepare("SELECT COUNT(*) FROM om_market_products_price pp INNER JOIN om_market_products_base pb ON pb.product_id = pp.product_id WHERE {$whereSQL}");
        $stmtCount->execute($params);
        $total = (int)$stmtCount->fetchColumn();

        $stmt = $db->prepare("
            SELECT pb.product_id, pb.name, pb.description, pb.barcode, pb.category_id, pb.image, pb.brand, pb.unit,
                   pp.id as price_id, pp.price, pp.price_promo as promotional_price, pp.stock, pp.status,
                   pp.date_added as created_at, pp.date_modified as updated_at,
                   pp.availability_schedule,
                   cat.name as category_name
            FROM om_market_products_price pp
            INNER JOIN om_market_products_base pb ON pb.product_id = pp.product_id
            LEFT JOIN om_market_categories cat ON cat.category_id = pb.category_id
            WHERE {$whereSQL} ORDER BY pb.name ASC LIMIT ? OFFSET ?
        ");
        $stmt->execute(array_merge($params, [$limit, $offset]));
        $items = $stmt->fetchAll();

        $formatted = [];
        foreach ($items as $item) {
            $scheduleRaw = $item['availability_schedule'] ?? null;
            $scheduleDecoded = $scheduleRaw ? json_decode($scheduleRaw, true) : null;
            $formatted[] = [
                "product_id" => (int)$item['product_id'],
                "price_id" => (int)$item['price_id'],
                "name" => $item['name'],
                "description" => $item['description'],
                "barcode" => $item['barcode'],
                "category_id" => (int)$item['category_id'],
                "category_name" => $item['category_name'],
                "image" => $item['image'],
                "brand" => $item['brand'],
                "unit" => $item['unit'],
                "price" => (float)$item['price'],
                "promotional_price" => $item['promotional_price'] ? (float)$item['promotional_price'] : null,
                "stock" => (int)$item['stock'],
                "status" => (int)$item['status'],
                "created_at" => $item['created_at'],
                "updated_at" => $item['updated_at'],
                "availability_schedule" => $scheduleDecoded,
                "is_available_now" => isProductAvailable($scheduleRaw)
            ];
        }
    } else {
        // Simple model: om_market_products directly
        $where = ["p.partner_id = ?"];
        $params = [$partner_id];

        if ($search !== '') {
            $searchEsc = str_replace(['%', '_'], ['\\%', '\\_'], $search);
            $where[] = "(p.name ILIKE ? OR p.barcode ILIKE ? OR p.brand ILIKE ?)";
            $searchParam = "%{$searchEsc}%";
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
        }
        if ($category_id > 0) {
            $where[] = "p.category_id = ?";
            $params[] = $category_id;
        }

        $whereSQL = implode(" AND ", $where);

        $stmtCount = $db->prepare("SELECT COUNT(*) FROM om_market_products p WHERE {$whereSQL}");
        $stmtCount->execute($params);
        $total = (int)$stmtCount->fetchColumn();

        $stmt = $db->prepare("
            SELECT p.product_id, p.name, p.description, p.barcode, p.category_id, p.image, p.brand, p.unit,
                   0 as price_id, p.price, p.special_price as promotional_price,
                   COALESCE(p.quantity, p.stock, 0) as stock,
                   COALESCE(p.status, 1) as status,
                   p.date_added as created_at, p.date_modified as updated_at,
                   p.category as category_name, p.dietary_tags,
                   p.availability_schedule
            FROM om_market_products p
            WHERE {$whereSQL} ORDER BY p.name ASC LIMIT ? OFFSET ?
        ");
        $stmt->execute(array_merge($params, [$limit, $offset]));
        $items = $stmt->fetchAll();

        $formatted = [];
        foreach ($items as $item) {
            $dietaryTags = null;
            if (!empty($item['dietary_tags'])) {
                $decoded = json_decode($item['dietary_tags'], true);
                $dietaryTags = is_array($decoded) ? $decoded : null;
            }
            $scheduleRaw = $item['availability_schedule'] ?? null;
            $scheduleDecoded = $scheduleRaw ? json_decode($scheduleRaw, true) : null;
            $formatted[] = [
                "product_id" => (int)$item['product_id'],
                "price_id" => (int)$item['price_id'],
                "name" => $item['name'],
                "description" => $item['description'],
                "barcode" => $item['barcode'],
                "category_id" => (int)$item['category_id'],
                "category_name" => $item['category_name'],
                "image" => $item['image'],
                "brand" => $item['brand'],
                "unit" => $item['unit'],
                "price" => (float)$item['price'],
                "promotional_price" => $item['promotional_price'] ? (float)$item['promotional_price'] : null,
                "stock" => (int)$item['stock'],
                "status" => (int)$item['status'],
                "created_at" => $item['created_at'],
                "updated_at" => $item['updated_at'],
                "dietary_tags" => $dietaryTags,
                "availability_schedule" => $scheduleDecoded,
                "is_available_now" => isProductAvailable($scheduleRaw)
            ];
        }
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
    ], "Produtos listados");

} catch (Exception $e) {
    error_log("[partner/products] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
