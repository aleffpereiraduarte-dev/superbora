<?php
/**
 * GET /api/mercado/customer/recommendations.php?limit=12
 * Personalized product recommendations for authenticated customers.
 *
 * Returns products with reason labels ("Baseado nos seus pedidos",
 * "Popular perto de voce", "Combina com seu horario") so the mobile
 * app can display contextual subtitles on each card.
 *
 * Delegates heavy scoring to intelligence/recomendacoes.php logic,
 * then enriches the response with per-item reason strings.
 */
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../helpers/cache.php";
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    response(false, null, "Metodo nao permitido", 405);
}

try {
    $customerId = requireCustomerAuth();
    $limit = min(30, max(1, (int)($_GET["limit"] ?? 12)));
    $currentHour = (int)date('G');

    // Cache per customer + hour bracket (time-of-day affects scoring), 5 min TTL
    $cacheKey = "recommendations:{$customerId}:h{$currentHour}:l{$limit}";
    $result = cachedQuery($cacheKey, 300, function() use ($customerId, $limit, $currentHour) {
        $db = getDB();

        // ---- Check if the customer has delivered order history ----
        $stmtHist = $db->prepare("
            SELECT 1 FROM om_market_orders
            WHERE customer_id = ? AND status = 'entregue'
            LIMIT 1
        ");
        $stmtHist->execute([$customerId]);
        $hasHistory = (bool)$stmtHist->fetch();

        // ---- Get favourite categories from order history ----
        $favCategories = [];
        if ($hasHistory) {
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
            $favCategories = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        }

        // ---- Collaborative filtering (CTE-based, avoids 5-table join explosion) ----
        $coProducts = [];
        if ($hasHistory) {
            $stmt = $db->prepare("
                WITH my_products AS (
                    SELECT DISTINCT oi.product_id
                    FROM om_market_order_items oi
                    JOIN om_market_orders o ON o.order_id = oi.order_id
                    WHERE o.customer_id = ? AND o.status = 'entregue'
                      AND o.created_at > NOW() - INTERVAL '60 days'
                ),
                similar_customers AS (
                    SELECT DISTINCT o.customer_id
                    FROM om_market_orders o
                    JOIN om_market_order_items oi ON oi.order_id = o.order_id
                    JOIN my_products mp ON mp.product_id = oi.product_id
                    WHERE o.customer_id != ? AND o.status = 'entregue'
                      AND o.created_at > NOW() - INTERVAL '60 days'
                    LIMIT 200
                )
                SELECT oi.product_id, COUNT(DISTINCT o.customer_id) as co_buyers
                FROM om_market_orders o
                JOIN om_market_order_items oi ON oi.order_id = o.order_id
                JOIN similar_customers sc ON sc.customer_id = o.customer_id
                WHERE oi.product_id NOT IN (SELECT product_id FROM my_products)
                  AND o.status = 'entregue'
                GROUP BY oi.product_id
                ORDER BY co_buyers DESC
                LIMIT 30
            ");
            $stmt->execute([$customerId, $customerId]);
            $coProducts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        }

        // ---- Products already bought recently ----
        $recentlyBought = [];
        if ($hasHistory) {
            $stmt = $db->prepare("
                SELECT DISTINCT oi.product_id
                FROM om_market_order_items oi
                JOIN om_market_orders o ON o.order_id = oi.order_id
                WHERE o.customer_id = ? AND o.date_added > NOW() - INTERVAL '14 days'
            ");
            $stmt->execute([$customerId]);
            $recentlyBought = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }

        // ---- Time-of-day category preferences ----
        $timeCategories = getTimePrefs($currentHour);

        // ---- Fetch candidate products (bulk: products + partners + sales counts) ----
        $stmt = $db->prepare("
            SELECT p.product_id, p.name, p.price, p.special_price as sale_price,
                   p.image, p.unit, p.partner_id,
                   pa.name as partner_name, pa.logo as partner_logo,
                   p.category_id, COALESCE(oc.cnt, 0) as sales_count,
                   p.date_added
            FROM om_market_products p
            LEFT JOIN om_market_partners pa ON p.partner_id = pa.partner_id
            LEFT JOIN (
                SELECT oi.product_id, COUNT(*) as cnt
                FROM om_market_order_items oi
                JOIN om_market_orders o ON o.order_id = oi.order_id
                WHERE o.date_added > NOW() - INTERVAL '90 days'
                GROUP BY oi.product_id
            ) oc ON oc.product_id = p.product_id
            WHERE p.status = 1 AND (pa.status::text = '1' OR pa.status IS NULL)
              AND p.name IS NOT NULL AND TRIM(p.name) != ''
              AND p.price > 0
              AND p.image IS NOT NULL AND TRIM(p.image) != ''
            ORDER BY oc.cnt DESC NULLS LAST
            LIMIT 200
        ");
        $stmt->execute();
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($candidates)) {
            return [
                "recommendations" => [],
                "reason" => "Nenhum produto disponivel",
                "source" => "empty",
            ];
        }

        // ---- Score each candidate ----
        $maxSales = max(1, max(array_column($candidates, 'sales_count') ?: [1]));
        $maxCoBuyers = max(1, max(array_values($coProducts) ?: [1]));
        $maxFreq = max(1, max(array_values($favCategories) ?: [1]));

        $scored = [];
        foreach ($candidates as $p) {
            $pid = $p['product_id'];
            $catId = $p['category_id'] ?? 0;

            $histScore = isset($favCategories[(string)$catId])
                ? ($favCategories[(string)$catId] / $maxFreq) : 0;
            $coScore = isset($coProducts[$pid])
                ? ($coProducts[$pid] / $maxCoBuyers) : 0;

            $timeScore = 0;
            $prodName = strtolower($p['name'] ?? '');
            foreach ($timeCategories as $keyword => $weight) {
                if (stripos($prodName, $keyword) !== false) {
                    $timeScore = max($timeScore, $weight);
                }
            }

            $popScore = $p['sales_count'] / $maxSales;
            $daysOld = max(1, (time() - strtotime($p['date_added'] ?? 'now')) / 86400);
            $noveltyScore = max(0, 1 - ($daysOld / 30));
            $recentPenalty = in_array($pid, $recentlyBought) ? 0.7 : 1.0;
            $saleBonus = ($p['sale_price'] && $p['sale_price'] > 0 && $p['sale_price'] < $p['price']) ? 0.1 : 0;

            $totalScore = (
                ($histScore * 0.40) +
                ($coScore * 0.25) +
                ($timeScore * 0.15) +
                ($popScore * 0.10) +
                ($noveltyScore * 0.10) +
                $saleBonus
            ) * $recentPenalty;

            $reason = 'Popular perto de voce';
            $reasonType = 'popular';
            $bestSignal = $popScore * 0.10;

            if ($histScore * 0.40 > $bestSignal) {
                $reason = 'Baseado nos seus pedidos';
                $reasonType = 'history';
                $bestSignal = $histScore * 0.40;
            }
            if ($coScore * 0.25 > $bestSignal) {
                $reason = 'Quem comprou isso tambem gostou';
                $reasonType = 'collaborative';
                $bestSignal = $coScore * 0.25;
            }
            if ($timeScore * 0.15 > $bestSignal) {
                $reason = 'Combina com seu horario';
                $reasonType = 'time';
                $bestSignal = $timeScore * 0.15;
            }
            if ($noveltyScore * 0.10 > $bestSignal && $noveltyScore > 0.5) {
                $reason = 'Novidade no SuperBora';
                $reasonType = 'novelty';
            }
            if ($saleBonus > 0 && $saleBonus > $bestSignal) {
                $reason = 'Em promocao';
                $reasonType = 'sale';
            }

            $p['_score'] = round($totalScore, 4);
            $p['reason'] = $reason;
            $p['reason_type'] = $reasonType;
            $scored[] = $p;
        }

        usort($scored, fn($a, $b) => $b['_score'] <=> $a['_score']);

        $recommendations = array_map(function($p) {
            return [
                'product_id'   => (int)$p['product_id'],
                'name'         => $p['name'],
                'price'        => round((float)$p['price'], 2),
                'sale_price'   => $p['sale_price'] ? round((float)$p['sale_price'], 2) : null,
                'image'        => $p['image'],
                'unit'         => $p['unit'] ?? null,
                'partner_id'   => (int)$p['partner_id'],
                'partner_name' => $p['partner_name'] ?? null,
                'partner_logo' => $p['partner_logo'] ?? null,
                'reason'       => $p['reason'],
                'reason_type'  => $p['reason_type'],
            ];
        }, array_slice($scored, 0, $limit));

        $globalReason = $hasHistory
            ? 'Baseado nos seus pedidos'
            : 'Popular perto de voce';

        return [
            "recommendations" => $recommendations,
            "reason" => $globalReason,
            "source" => $hasHistory ? "personalized" : "popular",
        ];
    });

    response(true, $result);

} catch (Exception $e) {
    error_log("[customer/recommendations] Erro: " . $e->getMessage());
    response(true, [
        "recommendations" => [],
        "reason" => "",
        "source" => "error",
    ]);
}

/**
 * Time-based category keyword preferences.
 */
function getTimePrefs(int $hour): array {
    if ($hour >= 6 && $hour < 10) {
        return ['cafe' => 0.8, 'pao' => 0.7, 'leite' => 0.6, 'cereal' => 0.5, 'iogurte' => 0.5, 'suco' => 0.4];
    } elseif ($hour >= 11 && $hour < 14) {
        return ['almoco' => 0.8, 'prato' => 0.7, 'arroz' => 0.5, 'feijao' => 0.5, 'salada' => 0.4, 'executivo' => 0.6];
    } elseif ($hour >= 14 && $hour < 17) {
        return ['lanche' => 0.7, 'cafe' => 0.5, 'bolo' => 0.6, 'salgado' => 0.5, 'doce' => 0.4];
    } elseif ($hour >= 18 && $hour < 22) {
        return ['jantar' => 0.8, 'pizza' => 0.7, 'hamburguer' => 0.7, 'sushi' => 0.5, 'massa' => 0.5, 'cerveja' => 0.3];
    } else {
        return ['lanche' => 0.5, 'pizza' => 0.4, 'hamburguer' => 0.4];
    }
}
