<?php
/**
 * GET /api/mercado/intelligence/recomendacoes.php?limit=12
 * Personalized product recommendations with multi-signal scoring.
 *
 * Scoring weights:
 *   - Purchase history (40%): categories the customer bought before
 *   - Collaborative filtering (25%): "customers who bought X also bought Y"
 *   - Time-of-day (15%): breakfast in morning, lunch at noon, etc.
 *   - Popularity (10%): sales_count + views
 *   - Novelty (10%): recently added products
 *
 * Falls back to popularity-only for anonymous/new users.
 * Uses Redis cache (5 min TTL) when available.
 */
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../helpers/claude-client.php";
setCorsHeaders();

try {
    $limit = min(30, max(1, (int)($_GET["limit"] ?? 12)));
    $db = getDB();

    // Try to get customer from auth
    $customerId = null;
    try {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(\S+)/', $authHeader, $m)) {
            require_once dirname(__DIR__, 3) . '/includes/classes/OmAuth.php';
            $auth = OmAuth::getInstance();
            $auth->setDb($db);
            $payload = $auth->validateToken($m[1]);
            if ($payload && ($payload['type'] ?? '') === 'customer') {
                $customerId = intval($payload['sub'] ?? $payload['uid'] ?? 0);
            }
        }
    } catch (Exception $e) { /* anonymous */ }

    // Try Redis cache
    $cacheKey = $customerId ? "recs:{$customerId}" : "recs:anon";
    $cached = null;
    try {
        $redis = new Redis();
        $redis->connect('127.0.0.1', 6379);
        $cached = $redis->get($cacheKey);
    } catch (Exception $e) { /* no redis */ }

    if ($cached) {
        $products = json_decode($cached, true);
        if ($products) {
            response(true, ["products" => array_slice($products, 0, $limit), "source" => "cache"]);
        }
    }

    $currentHour = (int)date('G');

    if ($customerId) {
        // ═══ PERSONALIZED RECOMMENDATIONS ═══

        // 1. Purchase history — get categories this customer bought (40%)
        $stmt = $db->prepare("
            SELECT pb.category_id, COUNT(*) as freq
            FROM om_market_order_items oi
            JOIN om_market_products_base pb ON pb.product_id = oi.product_id
            JOIN om_market_orders o ON o.order_id = oi.order_id
            WHERE o.customer_id = ? AND o.status = 'entregue'
            AND o.date_added > NOW() - INTERVAL '90 days'
            GROUP BY pb.category_id
            ORDER BY freq DESC
            LIMIT 10
        ");
        $stmt->execute([$customerId]);
        $favCategories = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // category_id => freq

        // 2. Collaborative filtering — products bought by similar customers (25%)
        $stmt = $db->prepare("
            SELECT oi2.product_id, COUNT(DISTINCT o2.customer_id) as co_buyers
            FROM om_market_orders o1
            JOIN om_market_order_items oi1 ON oi1.order_id = o1.order_id
            JOIN om_market_order_items oi2 ON oi2.product_id != oi1.product_id
            JOIN om_market_orders o2 ON o2.order_id = oi2.order_id AND o2.customer_id != o1.customer_id
            JOIN om_market_order_items oi3 ON oi3.order_id = o2.order_id AND oi3.product_id = oi1.product_id
            WHERE o1.customer_id = ? AND o1.status = 'entregue'
            AND o1.created_at > NOW() - INTERVAL '60 days'
            GROUP BY oi2.product_id
            ORDER BY co_buyers DESC
            LIMIT 30
        ");
        $stmt->execute([$customerId]);
        $coProducts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // product_id => co_buyers

        // 3. Products already bought (to deprioritize exact repeats but not exclude)
        $stmt = $db->prepare("
            SELECT DISTINCT oi.product_id
            FROM om_market_order_items oi
            JOIN om_market_orders o ON o.order_id = oi.order_id
            WHERE o.customer_id = ? AND o.date_added > NOW() - INTERVAL '14 days'
        ");
        $stmt->execute([$customerId]);
        $recentlyBought = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Fetch candidate products with scoring — ONLY products with image, name, and valid price
        $stmt = $db->prepare("
            SELECT p.product_id, p.name, p.price, p.special_price as sale_price, p.image, p.unit,
                   p.partner_id, pa.name as partner_name, pa.logo as partner_logo,
                   p.category_id, COALESCE(order_counts.cnt, 0) as sales_count,
                   p.date_added
            FROM om_market_products p
            LEFT JOIN om_market_partners pa ON p.partner_id = pa.partner_id
            LEFT JOIN (
                SELECT oi.product_id, COUNT(*) as cnt
                FROM om_market_order_items oi
                JOIN om_market_orders o ON o.order_id = oi.order_id
                WHERE o.date_added > NOW() - INTERVAL '90 days'
                GROUP BY oi.product_id
            ) order_counts ON order_counts.product_id = p.product_id
            WHERE p.status = 1 AND (pa.status::text = '1' OR pa.status IS NULL)
              AND p.name IS NOT NULL AND TRIM(p.name) != ''
              AND p.price > 0
              AND p.image IS NOT NULL AND TRIM(p.image) != ''
            ORDER BY order_counts.cnt DESC NULLS LAST
            LIMIT 200
        ");
        $stmt->execute();
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Score each candidate
        $maxSales = max(1, max(array_column($candidates, 'sales_count') ?: [1]));
        $maxCoBuyers = max(1, max(array_values($coProducts) ?: [1]));
        $maxFreq = max(1, max(array_values($favCategories) ?: [1]));

        // Time-of-day category preferences
        $timeCategories = getTimeCategoryPrefs($currentHour);

        $scored = [];
        foreach ($candidates as $p) {
            $pid = $p['product_id'];
            $catId = $p['category_id'] ?? 0;

            // Purchase history score (0-1) — 40%
            $histScore = isset($favCategories[(string)$catId]) ? ($favCategories[(string)$catId] / $maxFreq) : 0;

            // Collaborative filtering score (0-1) — 25%
            $coScore = isset($coProducts[$pid]) ? ($coProducts[$pid] / $maxCoBuyers) : 0;

            // Time-of-day score (0-1) — 15%
            $timeScore = 0;
            $catName = strtolower($p['name'] ?? '');
            foreach ($timeCategories as $keyword => $weight) {
                if (stripos($catName, $keyword) !== false) {
                    $timeScore = max($timeScore, $weight);
                }
            }

            // Popularity score (0-1) — 10%
            $popScore = $p['sales_count'] / $maxSales;

            // Novelty score (0-1) — 10%
            $daysOld = max(1, (time() - strtotime($p['date_added'] ?? 'now')) / 86400);
            $noveltyScore = max(0, 1 - ($daysOld / 30)); // Full score for <1 day, 0 for >30 days

            // Deprioritize recently bought (but don't exclude — they might want to reorder)
            $recentPenalty = in_array($pid, $recentlyBought) ? 0.7 : 1.0;

            // Bonus for items on sale
            $saleBonus = ($p['sale_price'] && $p['sale_price'] > 0 && $p['sale_price'] < $p['price']) ? 0.1 : 0;

            $totalScore = (
                ($histScore * 0.40) +
                ($coScore * 0.25) +
                ($timeScore * 0.15) +
                ($popScore * 0.10) +
                ($noveltyScore * 0.10) +
                $saleBonus
            ) * $recentPenalty;

            $p['_score'] = round($totalScore, 4);
            $scored[] = $p;
        }

        // Sort by score descending
        usort($scored, fn($a, $b) => $b['_score'] <=> $a['_score']);

        // Remove internal score field
        $products = array_map(function($p) {
            unset($p['_score'], $p['category_id'], $p['date_added']);
            return $p;
        }, array_slice($scored, 0, $limit));

    } else {
        // ═══ ANONYMOUS / NEW USER — popularity + time-of-day ═══
        $stmt = $db->prepare("
            SELECT p.product_id, p.name, p.price, p.special_price as sale_price, p.image, p.unit,
                   p.partner_id, pa.name as partner_name, pa.logo as partner_logo
            FROM om_market_products p
            LEFT JOIN om_market_partners pa ON p.partner_id = pa.partner_id
            LEFT JOIN (
                SELECT oi.product_id, COUNT(*) as cnt
                FROM om_market_order_items oi
                JOIN om_market_orders o ON o.order_id = oi.order_id
                WHERE o.date_added > NOW() - INTERVAL '90 days'
                GROUP BY oi.product_id
            ) order_counts ON order_counts.product_id = p.product_id
            WHERE p.status = 1 AND (pa.status::text = '1' OR pa.status IS NULL)
              AND p.name IS NOT NULL AND TRIM(p.name) != ''
              AND p.price > 0
              AND p.image IS NOT NULL AND TRIM(p.image) != ''
            ORDER BY order_counts.cnt DESC NULLS LAST, p.date_modified DESC NULLS LAST
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Claude AI reranking — use Haiku for speed, curate display quality
    $products = claudeRerank($products, $currentHour, $customerId);

    // Cache for 5 minutes
    try {
        if (isset($redis)) {
            $redis->setex($cacheKey, 300, json_encode($products));
        }
    } catch (Exception $e) { /* skip */ }

    response(true, ["products" => $products, "source" => $customerId ? "personalized" : "popular"]);

} catch (Exception $e) {
    error_log("[intelligence/recomendacoes] Erro: " . $e->getMessage());
    response(true, ["products" => []]);
}

/**
 * Use Claude (Haiku) to curate and rerank recommended products.
 * Filters out bad data and picks the best products to display.
 */
function claudeRerank(array $products, int $hour, ?int $customerId): array {
    if (count($products) < 3) return $products;

    try {
        $claude = new ClaudeClient('claude-haiku-4-5-20251001', 15, 0);

        // Build compact product list for Claude
        $items = [];
        foreach (array_slice($products, 0, 30) as $i => $p) {
            $items[] = [
                'i' => $i,
                'n' => $p['name'] ?? '',
                'p' => floatval($p['price'] ?? 0),
                'sp' => floatval($p['sale_price'] ?? 0),
                'img' => !empty($p['image']) ? 1 : 0,
                'loja' => $p['partner_name'] ?? '',
            ];
        }

        $timeLabel = '';
        if ($hour >= 6 && $hour < 10) $timeLabel = 'manha (cafe da manha)';
        elseif ($hour >= 11 && $hour < 14) $timeLabel = 'almoco';
        elseif ($hour >= 14 && $hour < 17) $timeLabel = 'tarde (lanche)';
        elseif ($hour >= 18 && $hour < 22) $timeLabel = 'noite (jantar)';
        else $timeLabel = 'madrugada';

        $system = "Voce curadoria de produtos para app de delivery (SuperBora Mercado). Responda APENAS com JSON array de indices.";
        $userMsg = "Hora: {$timeLabel}. Selecione os 12 melhores produtos para mostrar na home do app como 'Recomendado pra voce'. "
            . "Criterios: 1) Produto com nome claro e preco razoavel 2) Boa variedade (nao repetir tipo) 3) Relevante pro horario 4) Preferir com desconto. "
            . "Retorne JSON array com os indices (campo 'i') dos 12 melhores, em ordem de relevancia. Ex: [0,5,2,8,...]\n\n"
            . "Produtos: " . json_encode($items, JSON_UNESCAPED_UNICODE);

        $result = $claude->send($system, [['role' => 'user', 'content' => $userMsg]], 256);

        if ($result['success'] && !empty($result['text'])) {
            $indices = ClaudeClient::parseJson($result['text']);
            if (is_array($indices) && count($indices) >= 3) {
                $reranked = [];
                foreach ($indices as $idx) {
                    $idx = intval($idx);
                    if (isset($products[$idx])) {
                        $reranked[] = $products[$idx];
                    }
                }
                if (count($reranked) >= 3) {
                    return $reranked;
                }
            }
        }
    } catch (Exception $e) {
        error_log("[intelligence/recomendacoes] Claude rerank error: " . $e->getMessage());
    }

    // Fallback: return original products
    return $products;
}

/**
 * Get time-based category keyword preferences.
 * Returns keyword => weight (0-1) based on current hour.
 */
function getTimeCategoryPrefs(int $hour): array {
    if ($hour >= 6 && $hour < 10) {
        // Breakfast
        return ['cafe' => 0.8, 'pao' => 0.7, 'leite' => 0.6, 'cereal' => 0.5, 'iogurte' => 0.5, 'suco' => 0.4];
    } elseif ($hour >= 11 && $hour < 14) {
        // Lunch
        return ['almoco' => 0.8, 'prato' => 0.7, 'arroz' => 0.5, 'feijao' => 0.5, 'salada' => 0.4, 'executivo' => 0.6];
    } elseif ($hour >= 14 && $hour < 17) {
        // Afternoon snack
        return ['lanche' => 0.7, 'cafe' => 0.5, 'bolo' => 0.6, 'salgado' => 0.5, 'doce' => 0.4];
    } elseif ($hour >= 18 && $hour < 22) {
        // Dinner
        return ['jantar' => 0.8, 'pizza' => 0.7, 'hamburguer' => 0.7, 'sushi' => 0.5, 'massa' => 0.5, 'cerveja' => 0.3];
    } else {
        // Late night
        return ['lanche' => 0.5, 'pizza' => 0.4, 'hamburguer' => 0.4];
    }
}
