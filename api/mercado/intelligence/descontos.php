<?php
/**
 * GET /api/mercado/intelligence/descontos.php
 * Products/stores with active discounts (inspired by iFood "Desconto ate X% OFF").
 *
 * Parameters:
 *   ?min_discount=10  — Minimum discount percentage (default: 10, range: 1-90)
 *   ?limit=20         — Items per page (default: 20, max: 50)
 *   ?offset=0         — Pagination offset
 *   ?sort=discount    — Sort: discount (biggest first), price (lowest first), rating (best first)
 *   ?range=all        — Filter: all, 10-20, 20-30, 30+
 *
 * Returns products where special_price < price (active discount).
 * Calculates discount_percent = ((price - special_price) / price * 100).
 * Sorted by discount percentage DESC by default.
 */
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../helpers/r2-cache.php";
setPublicCacheCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    response(false, null, "Metodo nao permitido", 405);
}

header('Cache-Control: public, max-age=120, s-maxage=1800, stale-while-revalidate=3600');

try {
    $db = getDB();

    // Parse and validate parameters
    $minDiscount = min(90, max(1, intval($_GET['min_discount'] ?? 10)));
    $limit = min(50, max(1, intval($_GET['limit'] ?? 20)));
    $offset = max(0, intval($_GET['offset'] ?? 0));
    $sort = strtolower(trim($_GET['sort'] ?? 'discount'));
    $range = strtolower(trim($_GET['range'] ?? 'all'));

    $r2Key = "descontos/m{$minDiscount}_l{$limit}_o{$offset}_s{$sort}_r{$range}";
    if (function_exists('r2IsEnabled') && r2IsEnabled()) {
        $cached = r2CacheGet($r2Key);
        if ($cached !== null) {
            header('X-Cache: HIT-R2');
            header('Content-Type: application/json; charset=utf-8');
            echo $cached;
            exit;
        }
    }

    // Validate sort option
    $allowedSorts = ['discount', 'price', 'rating'];
    if (!in_array($sort, $allowedSorts, true)) {
        $sort = 'discount';
    }

    // Build range filter
    $rangeWhere = '';
    switch ($range) {
        case '10-20':
            $rangeWhere = 'AND disc.discount_percent >= 10 AND disc.discount_percent < 20';
            break;
        case '20-30':
            $rangeWhere = 'AND disc.discount_percent >= 20 AND disc.discount_percent < 30';
            break;
        case '30+':
            $rangeWhere = 'AND disc.discount_percent >= 30';
            break;
        default:
            // 'all' — just use min_discount
            break;
    }

    // Build sort clause
    $orderBy = match ($sort) {
        'price'  => 'disc.current_price ASC, disc.discount_percent DESC',
        'rating' => 'pa.rating DESC NULLS LAST, disc.discount_percent DESC',
        default  => 'disc.discount_percent DESC, disc.current_price ASC',
    };

    // Main query: products with active discounts from active partners
    $sql = "
        WITH disc AS (
            SELECT
                p.product_id,
                p.name,
                p.price AS original_price,
                p.special_price AS current_price,
                p.image,
                p.unit,
                p.description,
                p.partner_id,
                p.category,
                ROUND(((p.price - p.special_price) / p.price * 100)::numeric, 0) AS discount_percent
            FROM om_market_products p
            WHERE p.status = 1
              AND p.price > 0
              AND p.special_price IS NOT NULL
              AND p.special_price > 0
              AND p.special_price < p.price
              AND p.name IS NOT NULL AND TRIM(p.name) != ''
              AND p.image IS NOT NULL AND TRIM(p.image) != ''
        )
        SELECT
            disc.product_id,
            disc.name,
            disc.original_price,
            disc.current_price,
            disc.discount_percent,
            disc.image,
            disc.unit,
            disc.description,
            disc.category AS product_category,
            pa.partner_id,
            pa.name AS partner_name,
            pa.logo AS partner_logo,
            pa.delivery_fee,
            pa.delivery_time_min, pa.delivery_time_max,
            pa.categoria AS partner_category,
            pa.rating AS partner_rating
        FROM disc
        INNER JOIN om_market_partners pa ON disc.partner_id = pa.partner_id
        WHERE (pa.status::text = '1')
          AND disc.discount_percent >= :min_discount
          {$rangeWhere}
        ORDER BY {$orderBy}
        LIMIT :limit OFFSET :offset
    ";

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':min_discount', $minDiscount, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get total count for pagination
    $countSql = "
        WITH disc AS (
            SELECT
                p.product_id,
                p.partner_id,
                p.price,
                p.special_price,
                ROUND(((p.price - p.special_price) / p.price * 100)::numeric, 0) AS discount_percent
            FROM om_market_products p
            WHERE p.status = 1
              AND p.price > 0
              AND p.special_price IS NOT NULL
              AND p.special_price > 0
              AND p.special_price < p.price
              AND p.name IS NOT NULL AND TRIM(p.name) != ''
              AND p.image IS NOT NULL AND TRIM(p.image) != ''
        )
        SELECT COUNT(*) AS total
        FROM disc
        INNER JOIN om_market_partners pa ON disc.partner_id = pa.partner_id
        WHERE (pa.status::text = '1')
          AND disc.discount_percent >= :min_discount
          {$rangeWhere}
    ";
    $countStmt = $db->prepare($countSql);
    $countStmt->bindValue(':min_discount', $minDiscount, PDO::PARAM_INT);
    $countStmt->execute();
    $total = (int)$countStmt->fetchColumn();

    // Get max discount for the banner "Ate X% OFF"
    $maxDiscSql = "
        SELECT MAX(ROUND(((p.price - p.special_price) / p.price * 100)::numeric, 0)) AS max_discount
        FROM om_market_products p
        INNER JOIN om_market_partners pa ON p.partner_id = pa.partner_id
        WHERE p.status = 1
          AND (pa.status::text = '1')
          AND p.price > 0
          AND p.special_price IS NOT NULL
          AND p.special_price > 0
          AND p.special_price < p.price
    ";
    $maxDiscStmt = $db->query($maxDiscSql);
    $maxDiscount = (int)($maxDiscStmt->fetchColumn() ?: 0);

    // Format response
    $formatted = array_map(function ($p) {
        return [
            'product_id'       => (int)$p['product_id'],
            'name'             => $p['name'],
            'original_price'   => round(floatval($p['original_price']), 2),
            'current_price'    => round(floatval($p['current_price']), 2),
            'discount_percent' => (int)$p['discount_percent'],
            'image'            => $p['image'],
            'unit'             => $p['unit'] ?? '',
            'description'      => $p['description'] ?? '',
            'product_category' => $p['product_category'] ?? '',
            'partner_id'       => (int)$p['partner_id'],
            'partner_name'     => $p['partner_name'],
            'partner_logo'     => $p['partner_logo'],
            'partner_category' => $p['partner_category'] ?? '',
            'delivery_fee'     => round(floatval($p['delivery_fee'] ?? 0), 2),
            'estimated_time'   => $p['estimated_time'] ?? '30-45',
            'partner_rating'   => round(floatval($p['partner_rating'] ?? 0), 1),
        ];
    }, $products);

    $payload = [
        'products'     => $formatted,
        'total'        => $total,
        'max_discount' => $maxDiscount,
        'limit'        => $limit,
        'offset'       => $offset,
        'min_discount' => $minDiscount,
        'sort'         => $sort,
        'range'        => $range,
    ];

    if (function_exists('r2IsEnabled') && r2IsEnabled() && isset($r2Key)) {
        r2CachePut($r2Key, json_encode(['success' => true, 'data' => $payload, 'timestamp' => date('c')]), 1800);
    }
    header('X-Cache: MISS');
    response(true, $payload);

} catch (Exception $e) {
    error_log("[Descontos] Error: " . $e->getMessage());
    response(false, null, "Erro ao buscar descontos", 500);
}
