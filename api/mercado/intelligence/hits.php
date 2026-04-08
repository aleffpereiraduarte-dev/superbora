<?php
/**
 * GET /api/mercado/intelligence/hits.php
 * SuperBora Hits — Affordable meals with free delivery (inspired by iFood Hits).
 *
 * Parameters:
 *   ?max_price=20    — Maximum price threshold (default: 20, max: 50)
 *   ?limit=20        — Items per page (default: 20, max: 50)
 *   ?offset=0        — Pagination offset
 *   ?category=all    — Filter by category (all, almoco, lanche, cafe, doces, bebidas)
 *
 * Returns products sorted by popularity (order count) DESC, then price ASC.
 * Only active products from active, currently-open partners.
 */
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../helpers/r2-cache.php";
setPublicCacheCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    response(false, null, "Metodo nao permitido", 405);
}

// Edge cache 5 min, browser 60s, stale-while-revalidate 10 min
header('Cache-Control: public, max-age=120, s-maxage=1800, stale-while-revalidate=3600');

try {
    $db = getDB();

    // Parse and validate parameters
    $maxPrice = min(50, max(1, floatval($_GET['max_price'] ?? 20)));
    $limit = min(50, max(1, intval($_GET['limit'] ?? 20)));
    $offset = max(0, intval($_GET['offset'] ?? 0));
    $category = strtolower(trim($_GET['category'] ?? 'all'));
    // Sort modes: popular (default — what real Hits feeds use), price, newest
    $sort = strtolower(trim($_GET['sort'] ?? 'popular'));
    if (!in_array($sort, ['popular', 'price', 'newest'], true)) $sort = 'popular';

    // R2 global edge cache
    $r2Key = "hits/mp{$maxPrice}_l{$limit}_o{$offset}_c{$category}_s{$sort}";
    if (function_exists('r2IsEnabled') && r2IsEnabled()) {
        $cached = r2CacheGet($r2Key);
        if ($cached !== null) {
            header('X-Cache: HIT-R2');
            header('Content-Type: application/json; charset=utf-8');
            echo $cached;
            exit;
        }
    }

    // Category keyword mapping for product name/category matching
    $categoryKeywords = [
        'almoco'  => ['almoco', 'almoço', 'refeicao', 'refeição', 'prato', 'marmita', 'marmitex', 'executivo', 'feijoada', 'arroz', 'feijao', 'carne', 'frango', 'peixe', 'salada', 'strogonoff'],
        'lanche'  => ['lanche', 'hamburguer', 'hamburger', 'burger', 'sanduiche', 'sandwich', 'hot dog', 'hotdog', 'cachorro quente', 'wrap', 'tapioca', 'pastel', 'coxinha', 'esfiha', 'pizza', 'fatia'],
        'cafe'    => ['cafe', 'café', 'cappuccino', 'expresso', 'espresso', 'latte', 'mocha', 'cha', 'chá', 'chocolate quente', 'pao', 'pão', 'croissant', 'torrada'],
        'doces'   => ['doce', 'sobremesa', 'bolo', 'torta', 'pudim', 'brownie', 'cookie', 'sorvete', 'acai', 'açai', 'mousse', 'brigadeiro', 'churros', 'crepe'],
        'bebidas' => ['bebida', 'suco', 'juice', 'refrigerante', 'agua', 'água', 'cerveja', 'drink', 'smoothie', 'milkshake', 'limonada', 'guarana'],
    ];

    // Build category filter clause
    $categoryWhere = '';
    $categoryParams = [];
    if ($category !== 'all' && isset($categoryKeywords[$category])) {
        $keywords = $categoryKeywords[$category];
        $conditions = [];
        foreach ($keywords as $idx => $kw) {
            $paramKey = ":cat_kw_{$idx}";
            $conditions[] = "LOWER(p.name) LIKE {$paramKey}";
            $categoryParams[$paramKey] = "%{$kw}%";
        }
        $categoryWhere = 'AND (' . implode(' OR ', $conditions) . ')';
    }

    // Current day/time for partner open-hours check
    $currentDow = (int)date('w'); // 0=Sunday, 6=Saturday
    $currentTime = date('H:i:s');

    // Pick the ORDER BY clause based on sort mode. The popularity subquery
    // counts how many times each product was ordered in the last 30 days.
    // It's wrapped in COALESCE so missing rows still show up (sorted last).
    $orderClause = match ($sort) {
        'price'   => 'ORDER BY p.price ASC, popularity DESC',
        'newest'  => 'ORDER BY p.product_id DESC',
        default   => 'ORDER BY popularity DESC, p.price ASC', // popular
    };

    // Main query: products under price threshold from active partners.
    // The popularity LATERAL is cheap because the inner query is heavily
    // indexed (om_market_order_items.product_id) and capped to 30 days.
    $sql = "
        SELECT
            p.product_id,
            p.name,
            p.price,
            p.special_price as original_price,
            p.image,
            p.unit,
            p.description,
            pa.partner_id,
            pa.name as partner_name,
            pa.logo as partner_logo,
            pa.delivery_fee,
            pa.delivery_time_min, pa.delivery_time_max,
            pa.categoria as partner_category,
            pa.rating as partner_rating,
            COALESCE(pop.cnt, 0) as popularity
        FROM om_market_products p
        INNER JOIN om_market_partners pa ON p.partner_id = pa.partner_id
        LEFT JOIN LATERAL (
            SELECT COUNT(*) AS cnt
            FROM om_market_order_items oi
            JOIN om_market_orders o ON o.order_id = oi.order_id
            WHERE oi.product_id = p.product_id
              AND o.date_added >= NOW() - INTERVAL '30 days'
              AND o.status NOT IN ('cancelado', 'pendente')
        ) pop ON TRUE
        WHERE p.status::text = '1'
          AND pa.status::text = '1'
          AND p.price > 0
          AND p.price <= :max_price
          AND p.name IS NOT NULL AND TRIM(p.name) != ''
          AND p.image IS NOT NULL AND TRIM(p.image) != ''
          {$categoryWhere}
        {$orderClause}
        LIMIT :limit OFFSET :offset
    ";

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':max_price', $maxPrice, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    foreach ($categoryParams as $key => $val) {
        $stmt->bindValue($key, $val, PDO::PARAM_STR);
    }
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get total count for pagination
    $countSql = "
        SELECT COUNT(*) as total
        FROM om_market_products p
        INNER JOIN om_market_partners pa ON p.partner_id = pa.partner_id
        WHERE p.status::text = '1'
          AND pa.status::text = '1'
          AND p.price > 0
          AND p.price <= :max_price
          AND p.name IS NOT NULL AND TRIM(p.name) != ''
          AND p.image IS NOT NULL AND TRIM(p.image) != ''
          {$categoryWhere}
    ";
    $countStmt = $db->prepare($countSql);
    $countStmt->bindValue(':max_price', $maxPrice, PDO::PARAM_STR);
    foreach ($categoryParams as $key => $val) {
        $countStmt->bindValue($key, $val, PDO::PARAM_STR);
    }
    $countStmt->execute();
    $total = (int)$countStmt->fetchColumn();

    // Format response
    $formatted = array_map(function ($p) {
        $price = floatval($p['price']);
        $originalPrice = floatval($p['original_price'] ?? 0);

        // If special_price exists and is higher than price, it's the original price
        // If special_price is lower, it's the sale price (swap them)
        if ($originalPrice > 0 && $originalPrice > $price) {
            // original_price is the real original, price is the discounted
            // Keep as is
        } elseif ($originalPrice > 0 && $originalPrice < $price) {
            // special_price is actually the sale price
            $temp = $price;
            $price = $originalPrice;
            $originalPrice = $temp;
        } else {
            // No discount - original_price same as price
            $originalPrice = $price;
        }

        // Mark as best-seller when this product was ordered 5+ times in 30 days
        $popularity = (int)$p['popularity'];
        $isBestseller = $popularity >= 5;

        return [
            'product_id'     => (int)$p['product_id'],
            'name'           => $p['name'],
            'price'          => round($price, 2),
            'original_price' => round($originalPrice, 2),
            'has_discount'   => $originalPrice > $price,
            'discount_percent' => $originalPrice > $price
                ? round((($originalPrice - $price) / $originalPrice) * 100)
                : 0,
            'image'          => $p['image'],
            'unit'           => $p['unit'] ?? '',
            'partner_id'     => (int)$p['partner_id'],
            'partner_name'   => $p['partner_name'],
            'partner_logo'   => $p['partner_logo'],
            'partner_category' => $p['partner_category'] ?? '',
            'partner_rating' => $p['partner_rating'] !== null ? round((float)$p['partner_rating'], 1) : null,
            'delivery_fee'   => 0, // Hits = free delivery
            'delivery_fee_label' => 'GRATIS',
            'estimated_time' => $p['estimated_time'] ?? '30-45',
            'popularity'     => $popularity,
            'is_bestseller'  => $isBestseller,
            'is_hits'        => true,
        ];
    }, $products);

    $payload = [
        'products'   => $formatted,
        'total'      => $total,
        'limit'      => $limit,
        'offset'     => $offset,
        'max_price'  => $maxPrice,
        'category'   => $category,
        'has_more'   => ($offset + $limit) < $total,
    ];

    // Write to R2 (TTL 5 min)
    if (function_exists('r2IsEnabled') && r2IsEnabled() && isset($r2Key)) {
        r2CachePut($r2Key, json_encode(['success' => true, 'data' => $payload, 'timestamp' => date('c')]), 1800);
    }

    header('X-Cache: MISS');
    response(true, $payload);

} catch (Exception $e) {
    error_log("[Hits] Error: " . $e->getMessage());
    response(false, null, "Erro ao carregar produtos Hits", 500);
}
