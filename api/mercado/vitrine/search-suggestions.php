<?php
/**
 * Smart Search Suggestions API
 * GET /api/mercado/vitrine/search-suggestions.php
 *
 * Endpoints:
 *   ?q=xxx           - Return autocomplete suggestions (products + stores matching query)
 *   ?type=trending   - Return top 10 trending searches
 *   ?type=popular    - Return popular products/stores
 *   ?log=xxx         - Log a search term (POST or GET)
 *
 * Cache: 60s for suggestions, 300s for trending/popular
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 2) . "/cache/CacheHelper.php";

header('Cache-Control: public, max-age=60');

try {
    $db = getDB();

    // Ensure search_logs table exists
    ensureSearchLogsTable($db);

    $type = $_GET["type"] ?? null;
    $q = trim($_GET["q"] ?? "");
    $logTerm = trim($_GET["log"] ?? "");

    // Log search term if provided
    if ($logTerm && strlen($logTerm) >= 2) {
        logSearchTerm($db, $logTerm);
        response(true, ["logged" => true]);
    }

    // Return trending searches
    if ($type === "trending") {
        $data = getTrendingSearches($db);
        response(true, $data);
    }

    // Return popular products/stores
    if ($type === "popular") {
        $data = getPopularItems($db);
        response(true, $data);
    }

    // Return autocomplete suggestions for query
    if (strlen($q) >= 2) {
        $data = getSuggestions($db, $q);
        response(true, $data);
    }

    // Invalid request
    response(false, null, "Parametro invalido. Use ?q=termo, ?type=trending ou ?type=popular", 400);

} catch (Exception $e) {
    error_log("[API Search Suggestions] Erro: " . $e->getMessage());
    response(false, null, "Erro ao buscar sugestoes", 500);
}

/**
 * Ensure the search_logs table exists for tracking search terms
 */
function ensureSearchLogsTable(PDO $db): void {
    // Table om_search_logs created via migration
    return;
}

/**
 * Log a search term (increment count or create new entry)
 */
function logSearchTerm(PDO $db, string $term): void {
    $term = mb_strtolower(trim($term));
    if (strlen($term) < 2 || strlen($term) > 100) return;

    // Sanitize - remove special chars but keep spaces and accents
    $term = preg_replace('/[^\p{L}\p{N}\s]/u', '', $term);
    $term = preg_replace('/\s+/', ' ', $term);
    $term = trim($term);

    if (strlen($term) < 2) return;

    // Use INSERT ... ON CONFLICT for atomicity (PostgreSQL)
    $stmt = $db->prepare("
        INSERT INTO om_search_logs (term, search_count, last_searched_at)
        VALUES (?, 1, NOW())
        ON CONFLICT (term) DO UPDATE SET
            search_count = om_search_logs.search_count + 1,
            last_searched_at = NOW()
    ");

    try {
        $stmt->execute([$term]);
    } catch (Exception $e) {
        // If duplicate key fails (no unique index), try update approach
        $stmt = $db->prepare("SELECT id FROM om_search_logs WHERE term = ? LIMIT 1");
        $stmt->execute([$term]);
        $existing = $stmt->fetch();

        if ($existing) {
            $db->prepare("UPDATE om_search_logs SET search_count = search_count + 1, last_searched_at = NOW() WHERE id = ?")
               ->execute([$existing['id']]);
        } else {
            $db->prepare("INSERT INTO om_search_logs (term, search_count, last_searched_at) VALUES (?, 1, NOW())")
               ->execute([$term]);
        }
    }
}

/**
 * Get trending searches (top 10 most searched in last 7 days)
 */
function getTrendingSearches(PDO $db): array {
    $cacheKey = "search_trending_v1";

    return CacheHelper::remember($cacheKey, 300, function() use ($db) {
        $stmt = $db->prepare("
            SELECT term, search_count
            FROM om_search_logs
            WHERE last_searched_at >= NOW() - INTERVAL '7 days'
              AND search_count >= 2
            ORDER BY search_count DESC, last_searched_at DESC
            LIMIT 10
        ");
        $stmt->execute();
        $results = $stmt->fetchAll();

        // If not enough trending, get all-time popular
        if (count($results) < 5) {
            $stmt = $db->prepare("
                SELECT term, search_count
                FROM om_search_logs
                ORDER BY search_count DESC
                LIMIT 10
            ");
            $stmt->execute();
            $allTime = $stmt->fetchAll();

            // Merge, avoiding duplicates
            $seen = array_column($results, 'term');
            foreach ($allTime as $item) {
                if (!in_array($item['term'], $seen)) {
                    $results[] = $item;
                    $seen[] = $item['term'];
                }
                if (count($results) >= 10) break;
            }
        }

        return [
            "trending" => array_map(function($r) {
                return [
                    "termo" => $r['term'],
                    "buscas" => (int)$r['search_count']
                ];
            }, $results)
        ];
    });
}

/**
 * Get popular products and stores
 */
function getPopularItems(PDO $db): array {
    $cacheKey = "search_popular_v1";

    return CacheHelper::remember($cacheKey, 300, function() use ($db) {
        // Popular products (most ordered in last 30 days)
        $stmt = $db->prepare("
            SELECT p.product_id, p.name, p.price, p.special_price, p.image, p.unit,
                   mp.partner_id, mp.name as partner_name, mp.logo as partner_logo,
                   COUNT(oi.id) as vezes_pedido
            FROM om_market_order_items oi
            INNER JOIN om_market_orders o ON oi.order_id = o.order_id
            INNER JOIN om_market_products p ON oi.product_id = p.product_id
            INNER JOIN om_market_partners mp ON p.partner_id = mp.partner_id
            WHERE o.date_added >= NOW() - INTERVAL '30 days'
              AND o.status NOT IN ('cancelado')
              AND p.status = '1'
              AND mp.status = '1'
            GROUP BY p.product_id, p.name, p.price, p.special_price, p.image, p.unit,
                     mp.partner_id, mp.name, mp.logo
            ORDER BY vezes_pedido DESC
            LIMIT 6
        ");
        $stmt->execute();
        $produtos = $stmt->fetchAll();

        // Popular stores (most orders in last 30 days)
        $stmt = $db->prepare("
            SELECT mp.partner_id, mp.name, mp.logo, mp.rating, mp.categoria,
                   mp.delivery_fee, mp.delivery_time_min, mp.is_open,
                   COUNT(DISTINCT o.order_id) as total_pedidos
            FROM om_market_orders o
            INNER JOIN om_market_partners mp ON o.partner_id = mp.partner_id
            WHERE o.date_added >= NOW() - INTERVAL '30 days'
              AND o.status NOT IN ('cancelado')
              AND mp.status = '1'
            GROUP BY mp.partner_id, mp.name, mp.logo, mp.rating, mp.categoria,
                     mp.delivery_fee, mp.delivery_time_min, mp.is_open
            ORDER BY total_pedidos DESC
            LIMIT 6
        ");
        $stmt->execute();
        $lojas = $stmt->fetchAll();

        return [
            "produtos" => array_map(function($p) {
                $preco = (float)$p['price'];
                $promoPreco = $p['special_price'] ? (float)$p['special_price'] : null;
                $emPromocao = $promoPreco && $promoPreco > 0 && $promoPreco < $preco;

                return [
                    "id" => (int)$p['product_id'],
                    "tipo" => "produto",
                    "nome" => $p['name'],
                    "preco" => $preco,
                    "preco_promo" => $emPromocao ? $promoPreco : null,
                    "imagem" => $p['image'],
                    "unidade" => $p['unit'] ?? "un",
                    "parceiro_id" => (int)$p['partner_id'],
                    "parceiro_nome" => $p['partner_name'],
                    "parceiro_logo" => $p['partner_logo']
                ];
            }, $produtos),
            "lojas" => array_map(function($s) {
                return [
                    "id" => (int)$s['partner_id'],
                    "tipo" => "loja",
                    "nome" => $s['name'],
                    "logo" => $s['logo'],
                    "categoria" => $s['categoria'] ?? "mercado",
                    "avaliacao" => (float)($s['rating'] ?? 5.0),
                    "taxa_entrega" => (float)($s['delivery_fee'] ?? 0),
                    "tempo_estimado" => (int)($s['delivery_time_min'] ?? 60),
                    "aberto" => (int)($s['is_open'] ?? 0) === 1
                ];
            }, $lojas)
        ];
    });
}

/**
 * Get autocomplete suggestions for a query
 */
function getSuggestions(PDO $db, string $q): array {
    $cacheKey = "search_suggestions_" . md5($q);

    return CacheHelper::remember($cacheKey, 60, function() use ($db, $q) {
        $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $q);
        $termo = "%" . $escaped . "%";
        $termoInicio = $escaped . "%";

        // Search products (prioritize name starts with, then contains)
        $stmt = $db->prepare("
            SELECT p.product_id, p.name, p.price, p.special_price, p.image, p.unit,
                   mp.partner_id, mp.name as partner_name, mp.logo as partner_logo,
                   CASE
                       WHEN p.name LIKE ? THEN 1
                       ELSE 2
                   END as relevancia
            FROM om_market_products p
            INNER JOIN om_market_partners mp ON p.partner_id = mp.partner_id
            WHERE p.status = '1'
              AND mp.status = '1'
              AND (p.name LIKE ? OR p.description LIKE ?)
            ORDER BY relevancia ASC, p.name ASC
            LIMIT 8
        ");
        $stmt->execute([$termoInicio, $termo, $termo]);
        $produtos = $stmt->fetchAll();

        // Search stores (name or category)
        $stmt = $db->prepare("
            SELECT mp.partner_id, mp.name, mp.logo, mp.rating, mp.categoria,
                   mp.delivery_fee, mp.delivery_time_min, mp.is_open,
                   CASE
                       WHEN mp.name LIKE ? THEN 1
                       ELSE 2
                   END as relevancia
            FROM om_market_partners mp
            WHERE mp.status = '1'
              AND (mp.name LIKE ? OR mp.trade_name LIKE ? OR mp.categoria LIKE ?)
            ORDER BY relevancia ASC, mp.rating DESC, mp.name ASC
            LIMIT 5
        ");
        $stmt->execute([$termoInicio, $termo, $termo, $termo]);
        $lojas = $stmt->fetchAll();

        // Search term suggestions from logs
        $stmt = $db->prepare("
            SELECT term, search_count
            FROM om_search_logs
            WHERE term LIKE ?
              AND search_count >= 2
            ORDER BY
                CASE WHEN term LIKE ? THEN 1 ELSE 2 END ASC,
                search_count DESC
            LIMIT 5
        ");
        $stmt->execute([$termo, $termoInicio]);
        $termos = $stmt->fetchAll();

        return [
            "query" => $q,
            "sugestoes_termos" => array_map(function($t) {
                return [
                    "termo" => $t['term'],
                    "buscas" => (int)$t['search_count']
                ];
            }, $termos),
            "produtos" => array_map(function($p) {
                $preco = (float)$p['price'];
                $promoPreco = $p['special_price'] ? (float)$p['special_price'] : null;
                $emPromocao = $promoPreco && $promoPreco > 0 && $promoPreco < $preco;

                return [
                    "id" => (int)$p['product_id'],
                    "tipo" => "produto",
                    "nome" => $p['name'],
                    "preco" => $preco,
                    "preco_promo" => $emPromocao ? $promoPreco : null,
                    "imagem" => $p['image'],
                    "unidade" => $p['unit'] ?? "un",
                    "parceiro_id" => (int)$p['partner_id'],
                    "parceiro_nome" => $p['partner_name'],
                    "parceiro_logo" => $p['partner_logo']
                ];
            }, $produtos),
            "lojas" => array_map(function($s) {
                return [
                    "id" => (int)$s['partner_id'],
                    "tipo" => "loja",
                    "nome" => $s['name'],
                    "logo" => $s['logo'],
                    "categoria" => $s['categoria'] ?? "mercado",
                    "avaliacao" => (float)($s['rating'] ?? 5.0),
                    "taxa_entrega" => (float)($s['delivery_fee'] ?? 0),
                    "tempo_estimado" => (int)($s['delivery_time_min'] ?? 60),
                    "aberto" => (int)($s['is_open'] ?? 0) === 1
                ];
            }, $lojas)
        ];
    });
}
