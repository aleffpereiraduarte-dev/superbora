<?php
/**
 * GET /api/mercado/busca/search.php
 * Smart product search powered by Meilisearch
 *
 * Params:
 *   q          - Search query (required)
 *   limit      - Max results (default 20, max 100)
 *   offset     - Pagination offset
 *   partner_id - Filter by partner
 *   category   - Filter by category name
 *   min_price  - Min price filter
 *   max_price  - Max price filter
 *   in_stock   - Only in-stock (1/0)
 *   sort       - Sort: price_asc, price_desc, name_asc
 */

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../config/search.php";

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    response(false, null, "Metodo nao permitido", 405);
}

try {
    $q = trim($_GET['q'] ?? '');
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));

    if (empty($q) && empty($_GET['partner_id']) && empty($_GET['category'])) {
        response(false, null, "Parametro 'q', 'partner_id' ou 'category' e obrigatorio", 400);
    }

    // Build filters
    $filters = [];

    if (!empty($_GET['partner_id'])) {
        $filters[] = 'partner_id = ' . (int)$_GET['partner_id'];
    }

    if (!empty($_GET['category'])) {
        $cat = $_GET['category'];
        // Whitelist validation: only allow alphanumeric, spaces, accented chars, hyphens, ampersand
        if (!preg_match('/^[\p{L}\p{N} &\-\.]{1,100}$/u', $cat)) {
            response(false, null, "Categoria invalida", 400);
        }
        $cat = str_replace('"', '', $cat);
        $filters[] = 'category_name = "' . $cat . '"';
    }

    if (isset($_GET['min_price'])) {
        $filters[] = 'price >= ' . (float)$_GET['min_price'];
    }

    if (isset($_GET['max_price'])) {
        $filters[] = 'price <= ' . (float)$_GET['max_price'];
    }

    if (isset($_GET['in_stock']) && $_GET['in_stock'] === '1') {
        $filters[] = 'in_stock = true';
    }

    // Build sort
    $sort = [];
    $sortParam = $_GET['sort'] ?? '';
    switch ($sortParam) {
        case 'price_asc':
            $sort = ['price:asc'];
            break;
        case 'price_desc':
            $sort = ['price:desc'];
            break;
        case 'name_asc':
            $sort = ['name:asc'];
            break;
    }

    $search = SearchService::getInstance();
    $result = $search->searchProducts($q, [
        'limit' => $limit,
        'offset' => $offset,
        'filter' => !empty($filters) ? implode(' AND ', $filters) : null,
        'sort' => !empty($sort) ? $sort : null,
        'facets' => ['category_name', 'partner_name'],
    ]);

    if (isset($result['error'])) {
        // Meilisearch unavailable — fallback to SQL LIKE search
        $db = getDB();
        $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $q);
        $sqlQ = '%' . $escaped . '%';
        $stmt = $db->prepare("
            SELECT pp.id, p.name, p.image, pp.price, pp.price_promo,
                   p.brand, p.unit, pp.partner_id, pa.name as partner_name,
                   c.name as category_name
            FROM om_market_partner_products pp
            JOIN om_market_products_base p ON p.product_id = pp.product_id
            LEFT JOIN om_market_categories c ON c.category_id = p.category_id
            LEFT JOIN om_market_partners pa ON pa.partner_id = pp.partner_id
            WHERE pp.active = 1 AND p.status = 1 AND p.name ILIKE ?
            ORDER BY p.name
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$sqlQ, $limit, $offset]);
        $hits = $stmt->fetchAll(PDO::FETCH_ASSOC);

        response(true, [
            'hits' => $hits,
            'total' => count($hits),
            'query' => $q,
            'engine' => 'sql_fallback',
            'processing_time_ms' => 0,
        ], "Busca realizada (fallback)");
    }

    $hits = $result['hits'] ?? [];
    $total = $result['estimatedTotalHits'] ?? 0;
    $processingTime = $result['processingTimeMs'] ?? 0;
    $facets = $result['facetDistribution'] ?? [];

    response(true, [
        'hits' => $hits,
        'total' => $total,
        'query' => $q,
        'engine' => 'meilisearch',
        'processing_time_ms' => $processingTime,
        'pagination' => [
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => ($offset + $limit) < $total,
        ],
        'facets' => [
            'categories' => $facets['category_name'] ?? [],
            'partners' => $facets['partner_name'] ?? [],
        ],
    ], "Busca realizada");

} catch (Exception $e) {
    error_log("[busca/search] Erro: " . $e->getMessage());
    response(false, null, "Erro na busca", 500);
}
