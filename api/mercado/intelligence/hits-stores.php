<?php
/**
 * GET /api/mercado/intelligence/hits-stores.php
 *
 * Curated carousel of stores that participate in SuperBora Hits, ranked by:
 *   1. Number of low-priced (≤ R$ max_price) products they have available
 *   2. Average rating
 *   3. Recent order volume (last 30 days)
 *
 * Returns the top N stores plus a "showcase" product for each (the cheapest
 * eligible item with a photo) so the mobile carousel can render a card with
 * logo + best deal preview, like the iFood Hits "lojas em destaque" section.
 *
 * Params:
 *   ?max_price=20  — price ceiling for what counts as a Hits product (1-50)
 *   ?limit=8       — number of stores to return (1-15)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/r2-cache.php';
setPublicCacheCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    response(false, null, 'Metodo nao permitido', 405);
}

// Edge cache 30 min, browser 2 min, stale-while-revalidate 1 hour
header('Cache-Control: public, max-age=120, s-maxage=1800, stale-while-revalidate=3600');

try {
    $db = getDB();

    $maxPrice = min(50, max(1, floatval($_GET['max_price'] ?? 20)));
    $limit = min(15, max(1, intval($_GET['limit'] ?? 8)));

    $r2Key = "hits-stores/mp{$maxPrice}_l{$limit}";
    if (function_exists('r2IsEnabled') && r2IsEnabled()) {
        $cached = r2CacheGet($r2Key);
        if ($cached !== null) {
            header('X-Cache: HIT-R2');
            header('Content-Type: application/json; charset=utf-8');
            echo $cached;
            exit;
        }
    }

    // 1. Find stores ranked by hits product count + rating + recent volume
    $sqlStores = "
        SELECT
            pa.partner_id,
            pa.name,
            pa.trade_name,
            pa.logo,
            pa.banner,
            pa.rating,
            pa.delivery_time_min,
            pa.delivery_time_max,
            pa.delivery_fee,
            pa.categoria,
            pa.city,
            (
              SELECT COUNT(*)
              FROM om_market_products p2
              WHERE p2.partner_id = pa.partner_id
                AND p2.status::text = '1'
                AND p2.price > 0
                AND p2.price <= :max_price
                AND p2.image IS NOT NULL AND TRIM(p2.image) != ''
            ) AS hits_count,
            (
              SELECT COUNT(*)
              FROM om_market_orders o
              WHERE o.partner_id = pa.partner_id
                AND o.date_added >= NOW() - INTERVAL '30 days'
                AND o.status NOT IN ('cancelado', 'pendente')
            ) AS recent_orders
        FROM om_market_partners pa
        WHERE pa.status::text = '1'
          AND EXISTS (
              SELECT 1
              FROM om_market_products p2
              WHERE p2.partner_id = pa.partner_id
                AND p2.status::text = '1'
                AND p2.price > 0
                AND p2.price <= :max_price2
                AND p2.image IS NOT NULL AND TRIM(p2.image) != ''
          )
        ORDER BY hits_count DESC, recent_orders DESC, rating DESC NULLS LAST, pa.partner_id ASC
        LIMIT :limit
    ";
    $stmt = $db->prepare($sqlStores);
    $stmt->bindValue(':max_price', $maxPrice, PDO::PARAM_STR);
    $stmt->bindValue(':max_price2', $maxPrice, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $stores = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($stores)) {
        $payload = ['stores' => [], 'max_price' => $maxPrice];
        if (function_exists('r2IsEnabled') && r2IsEnabled()) {
            r2CachePut($r2Key, json_encode(['success' => true, 'data' => $payload, 'timestamp' => date('c')]), 1800);
        }
        response(true, $payload);
    }

    // 2. For each store, fetch the showcase product (cheapest eligible w/ image)
    $partnerIds = array_map(fn($s) => (int)$s['partner_id'], $stores);
    $placeholders = implode(',', array_fill(0, count($partnerIds), '?'));
    $sqlShowcase = "
        SELECT DISTINCT ON (p.partner_id)
            p.partner_id, p.product_id, p.name, p.price, p.special_price, p.image
        FROM om_market_products p
        WHERE p.partner_id IN ($placeholders)
          AND p.status::text = '1'
          AND p.price > 0
          AND p.price <= ?
          AND p.image IS NOT NULL AND TRIM(p.image) != ''
        ORDER BY p.partner_id, p.price ASC
    ";
    $params = array_merge($partnerIds, [$maxPrice]);
    $showcaseStmt = $db->prepare($sqlShowcase);
    $showcaseStmt->execute($params);
    $showcaseRows = $showcaseStmt->fetchAll(PDO::FETCH_ASSOC);

    $showcaseByPartner = [];
    foreach ($showcaseRows as $row) {
        $showcaseByPartner[(int)$row['partner_id']] = $row;
    }

    // 3. Format response
    $formatted = array_map(function ($s) use ($showcaseByPartner) {
        $partnerId = (int)$s['partner_id'];
        $showcase = $showcaseByPartner[$partnerId] ?? null;

        $showcaseProduct = null;
        if ($showcase) {
            $price = (float)$showcase['price'];
            $special = (float)($showcase['special_price'] ?? 0);
            // If special_price < price, it's the discounted one
            if ($special > 0 && $special < $price) {
                $original = $price;
                $price = $special;
            } else {
                $original = $price;
            }
            $showcaseProduct = [
                'product_id'     => (int)$showcase['product_id'],
                'name'           => $showcase['name'],
                'price'          => round($price, 2),
                'original_price' => round($original, 2),
                'image'          => $showcase['image'],
                'has_discount'   => $original > $price,
            ];
        }

        return [
            'partner_id'        => $partnerId,
            'name'              => $s['trade_name'] ?: $s['name'],
            'logo'              => $s['logo'] ?? '',
            'banner'            => $s['banner'] ?? '',
            'rating'            => $s['rating'] !== null ? round((float)$s['rating'], 1) : null,
            'delivery_time_min' => $s['delivery_time_min'] !== null ? (int)$s['delivery_time_min'] : null,
            'delivery_time_max' => $s['delivery_time_max'] !== null ? (int)$s['delivery_time_max'] : null,
            'category'          => $s['categoria'] ?? '',
            'city'              => $s['city'] ?? '',
            'hits_count'        => (int)$s['hits_count'],
            'recent_orders'     => (int)$s['recent_orders'],
            'showcase_product'  => $showcaseProduct,
        ];
    }, $stores);

    $payload = [
        'stores'    => $formatted,
        'max_price' => $maxPrice,
        'count'     => count($formatted),
    ];

    if (function_exists('r2IsEnabled') && r2IsEnabled()) {
        r2CachePut($r2Key, json_encode(['success' => true, 'data' => $payload, 'timestamp' => date('c')]), 1800);
    }
    header('X-Cache: MISS');
    response(true, $payload);

} catch (Exception $e) {
    error_log('[hits-stores] ' . $e->getMessage());
    response(false, null, 'Erro ao carregar lojas Hits', 500);
}
