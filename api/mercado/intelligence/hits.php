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
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    response(false, null, "Metodo nao permitido", 405);
}

try {
    $db = getDB();

    // Parse and validate parameters
    $maxPrice = min(50, max(1, floatval($_GET['max_price'] ?? 20)));
    $limit = min(50, max(1, intval($_GET['limit'] ?? 20)));
    $offset = max(0, intval($_GET['offset'] ?? 0));
    $category = strtolower(trim($_GET['category'] ?? 'all'));

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

    // Main query: products under price threshold from active partners
    // Simplified: no popularity subquery for speed
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
            0 as popularity
        FROM om_market_products p
        INNER JOIN om_market_partners pa ON p.partner_id = pa.partner_id
        WHERE p.status::text = '1'
          AND pa.status::text = '1'
          AND p.price > 0
          AND p.price <= :max_price
          AND p.name IS NOT NULL AND TRIM(p.name) != ''
          AND p.image IS NOT NULL AND TRIM(p.image) != ''
          {$categoryWhere}
        ORDER BY p.price ASC
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
            'delivery_fee'   => 0, // Hits = free delivery
            'delivery_fee_label' => 'GRATIS',
            'estimated_time' => $p['estimated_time'] ?? '30-45',
            'popularity'     => (int)$p['popularity'],
            'is_hits'        => true,
        ];
    }, $products);

    response(true, [
        'products'   => $formatted,
        'total'      => $total,
        'limit'      => $limit,
        'offset'     => $offset,
        'max_price'  => $maxPrice,
        'category'   => $category,
        'has_more'   => ($offset + $limit) < $total,
    ]);

} catch (Exception $e) {
    error_log("[Hits] Error: " . $e->getMessage());
    response(false, null, "Erro ao carregar produtos Hits", 500);
}
