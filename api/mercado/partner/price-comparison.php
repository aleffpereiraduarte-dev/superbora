<?php
/**
 * GET /api/mercado/partner/price-comparison.php
 * Price comparison with competitors in the same area
 * Returns: [{product_name, your_price, avg_market_price, min_price, max_price, position}]
 * LIMIT 20 products
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $payload = om_auth()->requirePartner();
    $partnerId = (int)$payload['uid'];

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        response(false, null, "Metodo nao permitido", 405);
    }

    // Get partner's category and city
    $stmtPartner = dbQuery($db, "
        SELECT categoria, cidade FROM om_market_partners WHERE partner_id = ?
    ", [$partnerId]);
    $partnerInfo = $stmtPartner->fetch();

    if (!$partnerInfo) {
        response(false, null, "Parceiro nao encontrado", 404);
    }

    $category = $partnerInfo['categoria'] ?? '';
    $city = $partnerInfo['cidade'] ?? '';

    // Get partner's products (try both product models)
    $myProducts = [];

    // Model 1: om_market_products
    $stmtMy1 = dbQuery($db, "
        SELECT product_id, name, price
        FROM om_market_products
        WHERE partner_id = ? AND status::text = '1' AND price > 0
        ORDER BY name ASC
        LIMIT 20
    ", [$partnerId]);
    $myProducts = $stmtMy1->fetchAll();

    // Model 2: om_market_products_price + om_market_products_base
    if (empty($myProducts)) {
        $stmtMy2 = dbQuery($db, "
            SELECT pb.product_id, pb.name, pp.price
            FROM om_market_products_price pp
            INNER JOIN om_market_products_base pb ON pb.product_id = pp.product_id
            WHERE pp.partner_id = ? AND pp.status = '1' AND pp.price > 0
            ORDER BY pb.name ASC
            LIMIT 20
        ", [$partnerId]);
        $myProducts = $stmtMy2->fetchAll();
    }

    if (empty($myProducts)) {
        response(true, [
            'comparisons' => [],
            'total' => 0,
            'competitors_found' => 0,
        ], "Nenhum produto para comparar");
    }

    // Get competitor partner IDs in same category and city
    $stmtCompetitors = dbQuery($db, "
        SELECT partner_id FROM om_market_partners
        WHERE categoria = ? AND cidade = ? AND partner_id != ? AND status = '1'
    ", [$category, $city, $partnerId]);
    $competitorIds = array_column($stmtCompetitors->fetchAll(), 'partner_id');

    if (empty($competitorIds)) {
        // No competitors — return own products without comparison
        $comparisons = [];
        foreach ($myProducts as $p) {
            $comparisons[] = [
                'product_id' => (int)$p['product_id'],
                'product_name' => $p['name'],
                'your_price' => round((float)$p['price'], 2),
                'avg_market_price' => null,
                'min_price' => null,
                'max_price' => null,
                'competitors_count' => 0,
                'position' => 'no_data',
            ];
        }

        response(true, [
            'comparisons' => $comparisons,
            'total' => count($comparisons),
            'competitors_found' => 0,
            'category' => $category,
            'city' => $city,
        ], "Nenhum concorrente encontrado na mesma area");
    }

    // Build comparison for each of partner's products using fuzzy name matching
    $comparisons = [];

    // Build competitor ID placeholder
    $compPlaceholders = implode(',', array_fill(0, count($competitorIds), '?'));

    foreach ($myProducts as $myProduct) {
        $productName = $myProduct['name'];
        $myPrice = (float)$myProduct['price'];

        // Fuzzy match: search for similar product names in competitor catalogs
        // Use the first significant words from product name for matching
        $searchName = '%' . str_replace(['%', '_'], ['\\%', '\\_'], trim($productName)) . '%';

        // Try exact name match first, then fuzzy
        $competitorPrices = [];

        // Model 1: om_market_products
        $stmtComp1 = dbQuery($db, "
            SELECT price FROM om_market_products
            WHERE partner_id IN ({$compPlaceholders})
              AND name ILIKE ?
              AND status::text = '1'
              AND price > 0
        ", array_merge($competitorIds, [$searchName]));
        $comp1 = $stmtComp1->fetchAll();
        foreach ($comp1 as $c) {
            $competitorPrices[] = (float)$c['price'];
        }

        // Model 2: om_market_products_price + base
        $stmtComp2 = dbQuery($db, "
            SELECT pp.price FROM om_market_products_price pp
            INNER JOIN om_market_products_base pb ON pb.product_id = pp.product_id
            WHERE pp.partner_id IN ({$compPlaceholders})
              AND pb.name ILIKE ?
              AND pp.status = '1'
              AND pp.price > 0
        ", array_merge($competitorIds, [$searchName]));
        $comp2 = $stmtComp2->fetchAll();
        foreach ($comp2 as $c) {
            $competitorPrices[] = (float)$c['price'];
        }

        $avgPrice = null;
        $minPrice = null;
        $maxPrice = null;
        $position = 'no_data';

        if (!empty($competitorPrices)) {
            $avgPrice = round(array_sum($competitorPrices) / count($competitorPrices), 2);
            $minPrice = round(min($competitorPrices), 2);
            $maxPrice = round(max($competitorPrices), 2);

            // Determine position
            $tolerance = $avgPrice * 0.05; // 5% tolerance for "average"
            if ($myPrice < $avgPrice - $tolerance) {
                $position = 'below';
            } elseif ($myPrice > $avgPrice + $tolerance) {
                $position = 'above';
            } else {
                $position = 'average';
            }
        }

        $comparisons[] = [
            'product_id' => (int)$myProduct['product_id'],
            'product_name' => $productName,
            'your_price' => round($myPrice, 2),
            'avg_market_price' => $avgPrice,
            'min_price' => $minPrice,
            'max_price' => $maxPrice,
            'competitors_count' => count($competitorPrices),
            'position' => $position,
        ];
    }

    // Sort by number of competitors found (most comparable first)
    usort($comparisons, fn($a, $b) => $b['competitors_count'] <=> $a['competitors_count']);

    // Summary stats
    $withData = array_filter($comparisons, fn($c) => $c['position'] !== 'no_data');
    $belowCount = count(array_filter($withData, fn($c) => $c['position'] === 'below'));
    $aboveCount = count(array_filter($withData, fn($c) => $c['position'] === 'above'));
    $avgCount = count(array_filter($withData, fn($c) => $c['position'] === 'average'));

    response(true, [
        'comparisons' => $comparisons,
        'total' => count($comparisons),
        'competitors_found' => count($competitorIds),
        'category' => $category,
        'city' => $city,
        'summary' => [
            'products_compared' => count($withData),
            'below_market' => $belowCount,
            'above_market' => $aboveCount,
            'at_market' => $avgCount,
            'no_data' => count($comparisons) - count($withData),
        ],
    ], "Comparacao de precos");

} catch (Exception $e) {
    error_log("[partner/price-comparison] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
