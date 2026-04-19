<?php
/**
 * GET /api/mercado/produtos/listar.php?partner_id=1&category_id=2
 * Lista produtos do mercado
 * Otimizado com cache (TTL: 5 min) e prepared statements
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 2) . "/cache/CacheHelper.php";
require_once __DIR__ . "/../helpers/r2-cache.php";

// Public cacheable endpoint — no credentials so Cloudflare can cache.
setPublicCacheCorsHeaders();

// Aggressive edge cache: 30min edge, 2min browser, 1h stale-while-revalidate.
// Catalog caching: previously s-maxage=1800 caused 30-minute staleness at
// Cloudflare edge after partner price/stock updates. Reduced to 60s so partner
// edits propagate within a minute. Still keeps enough to absorb burst traffic.
header('Cache-Control: public, max-age=30, s-maxage=60, stale-while-revalidate=120');

try {
    $partner_id = (int)($_GET["partner_id"] ?? 0);
    $category_id = isset($_GET["category_id"]) && $_GET["category_id"] !== "" ? (int)$_GET["category_id"] : null;
    $busca = isset($_GET["q"]) ? trim($_GET["q"]) : null;
    $pagina = max(1, (int)($_GET["page"] ?? ($_GET["pagina"] ?? 1)));
    $limite = min(100, max(1, (int)($_GET["limit"] ?? ($_GET["limite"] ?? 50))));
    $offset = ($pagina - 1) * $limite;
    $ordenar = $_GET["ordenar"] ?? $_GET["sort"] ?? null;

    // ============ R2 GLOBAL CACHE (only for non-search, non-paginated queries) ============
    // Search and pagination create too many cache variants. Hot path is partner page-1 listing.
    if (function_exists('r2IsEnabled') && r2IsEnabled() && !$busca && $pagina === 1) {
        $r2Key = "listar/p{$partner_id}_c" . ($category_id ?: '0') . "_l{$limite}_o" . ($ordenar ?: 'def');
        $cached = r2CacheGet($r2Key);
        if ($cached !== null) {
            header('X-Cache: HIT-R2');
            header('Content-Type: application/json; charset=utf-8');
            echo $cached;
            exit;
        }
    }

    // Validar ordenacao
    $allowedSorts = [
        'preco_asc' => 'p.price ASC',
        'preco_desc' => 'p.price DESC',
        'nome_asc' => 'p.name ASC',
        'nome_desc' => 'p.name DESC',
        'recente' => 'p.product_id DESC',
    ];
    $orderBy = $allowedSorts[$ordenar] ?? 'p.name';

    // Cache key baseado nos parâmetros
    $cacheKey = "mercado_produtos_" . md5(json_encode([
        $partner_id, $category_id, $busca, $pagina, $limite, $ordenar
    ]));

    $data = CacheHelper::remember($cacheKey, 300, function() use ($partner_id, $category_id, $busca, $pagina, $limite, $offset, $orderBy) {
        $db = getDB();

        // Build conditions array — only literal SQL with ? placeholders
        $conditions = ["p.status::text = '1'", "(p.available::text = '1' OR p.available IS NULL)"];
        $params = [];

        if ($partner_id) {
            $conditions[] = "p.partner_id = ?";
            $params[] = $partner_id;
        }
        if ($category_id) {
            $conditions[] = "p.category_id = ?";
            $params[] = $category_id;
        }
        if ($busca) {
            $buscaEscaped = str_replace(['%', '_'], ['\\%', '\\_'], $busca);
            $conditions[] = "(p.name ILIKE ? OR p.description ILIKE ?)";
            $params[] = "%{$buscaEscaped}%";
            $params[] = "%{$buscaEscaped}%";
        }

        // $orderBy is from a whitelist — safe to interpolate
        // Build full SQL with parameterized WHERE and whitelisted ORDER BY
        $whereClause = implode(" AND ", $conditions);
        $sql = "SELECT p.id, p.product_id, p.name, p.description, p.price, p.special_price,
                       p.image, p.unit, p.quantity, p.category_id, p.available, p.partner_id,
                       p.date_added,
                       c.name as categoria_nome
                FROM om_market_products p
                LEFT JOIN om_market_categories c ON p.category_id = c.category_id
                WHERE " . $whereClause . "
                ORDER BY " . $orderBy . "
                LIMIT ? OFFSET ?";

        $stmt = $db->prepare($sql);
        $stmt->execute(array_merge($params, [$limite, $offset]));
        $produtos = $stmt->fetchAll();

        // Count total
        $countStmt = $db->prepare("SELECT COUNT(*) FROM om_market_products p WHERE " . $whereClause);
        $countStmt->execute($params);
        $total = $countStmt->fetchColumn();

        // Buscar opcoes para cada produto (IDs from DB, not user input)
        $productIds = array_map(function($p) {
            return $p["product_id"] ?? $p["id"];
        }, $produtos);

        $optionGroups = [];
        $smartTagsByProduct = [];
        if (!empty($productIds)) {
            $inPlaceholders = implode(',', array_fill(0, count($productIds), '?'));
            $stmtGroups = $db->prepare(
                "SELECT g.*, o.id as option_id, o.name as option_name, o.price_extra, o.available as option_available, o.sort_order as option_sort
                FROM om_product_option_groups g
                LEFT JOIN om_product_options o ON g.id = o.group_id
                WHERE g.product_id IN (" . $inPlaceholders . ") AND g.active = 1
                ORDER BY g.sort_order, g.id, o.sort_order, o.id"
            );
            $stmtGroups->execute(array_values($productIds));
            $rows = $stmtGroups->fetchAll();

            foreach ($rows as $row) {
                $pid = $row['product_id'];
                $gid = $row['id'];
                if (!isset($optionGroups[$pid][$gid])) {
                    $optionGroups[$pid][$gid] = [
                        'id' => $gid,
                        'name' => $row['name'],
                        'required' => (bool)$row['required'],
                        'min_select' => (int)$row['min_select'],
                        'max_select' => (int)$row['max_select'],
                        'options' => []
                    ];
                }
                if ($row['option_id'] && $row['option_available']) {
                    $optionGroups[$pid][$gid]['options'][] = [
                        'id' => (int)$row['option_id'],
                        'name' => $row['option_name'],
                        'price_extra' => floatval($row['price_extra'])
                    ];
                }
            }

            // Top sellers: IDs of the top 3 most-ordered products for this partner in last 30 days.
            // Cheap: uses order_items + orders joined. Cached with the outer response (5 min).
            $topSellerIds = [];
            if ($partner_id > 0) {
                try {
                    $stmtTop = $db->prepare("
                        SELECT oi.product_id
                        FROM om_market_order_items oi
                        INNER JOIN om_market_orders o ON o.order_id = oi.order_id
                        WHERE o.partner_id = ?
                          AND o.date_added > NOW() - INTERVAL '30 days'
                          AND o.status NOT IN ('cancelado', 'recusado', 'failed', 'canceled')
                        GROUP BY oi.product_id
                        ORDER BY SUM(oi.quantity) DESC
                        LIMIT 3
                    ");
                    $stmtTop->execute([$partner_id]);
                    $topSellerIds = array_map('intval', $stmtTop->fetchAll(PDO::FETCH_COLUMN));
                } catch (Exception $e) { /* non-critical */ }
            }

            // Fetch smart tags for all products in batch
            try {
                $stmtTags = $db->prepare(
                    "SELECT product_id, tag_slug, tag_label, tag_category, confidence
                     FROM om_product_smart_tags
                     WHERE product_id IN (" . $inPlaceholders . ")
                     ORDER BY product_id,
                         CASE tag_category WHEN 'dietary' THEN 1 WHEN 'attribute' THEN 2 WHEN 'allergen' THEN 3 END,
                         confidence DESC"
                );
                $stmtTags->execute(array_values($productIds));
                $tagRows = $stmtTags->fetchAll();
                foreach ($tagRows as $tr) {
                    $pid = $tr['product_id'];
                    unset($tr['product_id']);
                    $smartTagsByProduct[$pid][] = $tr;
                }
            } catch (Exception $e) {
                // Non-critical — table may not exist yet
            }
        }

        return [
            "total" => (int)$total,
            "pagina" => $pagina,
            "produtos" => array_map(function($p) use ($optionGroups, $smartTagsByProduct, $topSellerIds) {
                $pid = $p["product_id"] ?? $p["id"];
                $groups = isset($optionGroups[$pid]) ? array_values($optionGroups[$pid]) : [];
                $topRank = array_search((int)$pid, $topSellerIds, true);

                return [
                    "id" => $pid,
                    "nome" => $p["name"],
                    "descricao" => $p["description"],
                    "preco" => floatval($p["special_price"] ?: $p["price"]),
                    "preco_original" => floatval($p["price"]),
                    "imagem" => $p["image"],
                    "categoria" => $p["categoria_nome"],
                    "unidade" => $p["unit"] ?? "un",
                    "estoque" => $p["quantity"] ?? 999,
                    "disponivel" => ($p["quantity"] ?? 999) > 0,
                    "option_groups" => $groups,
                    "smart_tags" => $smartTagsByProduct[$pid] ?? [],
                    "is_top_seller" => $topRank !== false,
                    "top_seller_rank" => $topRank !== false ? ($topRank + 1) : null,
                    "is_new" => !empty($p['date_added']) && strtotime($p['date_added']) > (time() - 14 * 86400),
                ];
            }, $produtos)
        ];
    });

    // Write to R2 global cache (fire-and-forget for hot path)
    if (function_exists('r2IsEnabled') && r2IsEnabled() && !$busca && $pagina === 1 && isset($r2Key)) {
        r2CachePut($r2Key, json_encode(['success' => true, 'data' => $data, 'timestamp' => date('c')]), 1800);
    }

    header('X-Cache: MISS');
    response(true, $data);

} catch (Exception $e) {
    error_log("[API Mercado Produtos Listar] Erro: " . $e->getMessage());
    response(false, null, "Erro ao carregar produtos. Tente novamente.", 500);
}
