<?php
/**
 * GET /api/mercado/partner/product-funnel.php
 * Product Funnel Analytics (iFood Cris-style)
 *
 * For each product, tracks: views -> cart adds -> orders (conversion funnel).
 * Returns top 20 products by views, low-conversion products, high performers,
 * and AI-generated suggestions for improving low-converting products.
 *
 * Auth: Bearer token (partner type)
 * Cache: Redis 30 minutes
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
        response(false, null, "Metodo nao permitido. Use GET.", 405);
    }

    // ── Redis cache check (30 min) ──
    $redis = RedisService::getInstance();
    $cacheKey = "product_funnel:{$partnerId}";
    if ($redis->isAvailable()) {
        $cached = $redis->get($cacheKey);
        if ($cached && is_array($cached)) {
            response(true, $cached, "Funil de produtos (cache).");
        }
    }

    // ── Ensure om_user_events table exists ──
    try {
        $db->query("SELECT 1 FROM om_user_events LIMIT 0");
    } catch (Exception $e) {
        $db->exec("
            CREATE TABLE IF NOT EXISTS om_user_events (
                id BIGSERIAL PRIMARY KEY,
                customer_id INT,
                session_id VARCHAR(128),
                event_type VARCHAR(50) NOT NULL,
                entity_type VARCHAR(50),
                entity_id INT,
                metadata JSONB DEFAULT '{}',
                created_at TIMESTAMP DEFAULT NOW()
            )
        ");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_user_events_type_entity ON om_user_events (event_type, entity_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_user_events_created ON om_user_events (created_at)");
    }

    // ── Step 1: Get all active products for this partner ──
    $stmtProducts = $db->prepare("
        SELECT p.product_id, p.name, p.price, p.image, c.name as category_name
        FROM om_market_products p
        LEFT JOIN om_market_categories c ON p.category_id = c.category_id
        WHERE p.partner_id = ? AND p.status::text = '1'
        ORDER BY p.name
    ");
    $stmtProducts->execute([$partnerId]);
    $products = $stmtProducts->fetchAll(PDO::FETCH_ASSOC);

    if (empty($products)) {
        response(true, [
            'products' => [],
            'low_conversion' => [],
            'high_performers' => [],
            'suggestions' => [],
            'summary' => ['total_products' => 0, 'total_views' => 0, 'avg_conversion' => 0],
        ], "Nenhum produto ativo encontrado.");
    }

    $productIds = array_column($products, 'product_id');
    $productMap = [];
    foreach ($products as $p) {
        $productMap[(int)$p['product_id']] = $p;
    }

    // ── Step 2: Count views per product (last 30 days) ──
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $stmtViews = $db->prepare("
        SELECT entity_id, COUNT(*) as cnt
        FROM om_user_events
        WHERE event_type = 'view_product'
          AND entity_id IN ({$placeholders})
          AND created_at >= NOW() - INTERVAL '30 days'
        GROUP BY entity_id
    ");
    $stmtViews->execute($productIds);
    $viewCounts = [];
    while ($row = $stmtViews->fetch(PDO::FETCH_ASSOC)) {
        $viewCounts[(int)$row['entity_id']] = (int)$row['cnt'];
    }

    // ── Step 3: Count cart adds per product (last 30 days) ──
    $stmtCarts = $db->prepare("
        SELECT entity_id, COUNT(*) as cnt
        FROM om_user_events
        WHERE event_type = 'add_to_cart'
          AND entity_id IN ({$placeholders})
          AND created_at >= NOW() - INTERVAL '30 days'
        GROUP BY entity_id
    ");
    $stmtCarts->execute($productIds);
    $cartCounts = [];
    while ($row = $stmtCarts->fetch(PDO::FETCH_ASSOC)) {
        $cartCounts[(int)$row['entity_id']] = (int)$row['cnt'];
    }

    // ── Step 4: Count completed orders per product (last 30 days) ──
    $stmtOrders = $db->prepare("
        SELECT oi.product_id, COUNT(DISTINCT oi.order_id) as cnt
        FROM om_market_order_items oi
        JOIN om_market_orders o ON o.order_id = oi.order_id
        WHERE oi.product_id IN ({$placeholders})
          AND o.partner_id = ?
          AND o.status IN ('entregue', 'finalizado')
          AND o.date_added >= NOW() - INTERVAL '30 days'
        GROUP BY oi.product_id
    ");
    $orderParams = array_merge($productIds, [$partnerId]);
    $stmtOrders->execute($orderParams);
    $orderCounts = [];
    while ($row = $stmtOrders->fetch(PDO::FETCH_ASSOC)) {
        $orderCounts[(int)$row['product_id']] = (int)$row['cnt'];
    }

    // ── Step 5: Build funnel data ──
    $funnelData = [];
    $totalViews = 0;
    $totalOrders = 0;

    foreach ($productIds as $pid) {
        $views = $viewCounts[$pid] ?? 0;
        $cartAdds = $cartCounts[$pid] ?? 0;
        $orders = $orderCounts[$pid] ?? 0;

        $conversionRate = $views > 0 ? round(($orders / $views) * 100, 2) : 0;
        $cartRate = $views > 0 ? round(($cartAdds / $views) * 100, 2) : 0;
        $dropOffRate = $cartAdds > 0 ? round((($cartAdds - $orders) / $cartAdds) * 100, 2) : 0;

        $totalViews += $views;
        $totalOrders += $orders;

        $prod = $productMap[$pid];
        $funnelData[] = [
            'product_id' => (int)$pid,
            'name' => $prod['name'],
            'price' => (float)$prod['price'],
            'image' => $prod['image'] ?? null,
            'category' => $prod['category_name'] ?? '',
            'views' => $views,
            'cart_adds' => $cartAdds,
            'orders' => $orders,
            'conversion_rate' => $conversionRate,
            'cart_rate' => $cartRate,
            'drop_off_rate' => $dropOffRate,
        ];
    }

    // Sort by views DESC, take top 20
    usort($funnelData, function ($a, $b) { return $b['views'] - $a['views']; });
    $top20 = array_slice($funnelData, 0, 20);

    // ── Step 6: Identify low conversion and high performers ──
    $lowConversion = array_values(array_filter($funnelData, function ($p) {
        return $p['views'] > 10 && $p['conversion_rate'] < 5;
    }));
    usort($lowConversion, function ($a, $b) { return $b['views'] - $a['views']; });
    $lowConversion = array_slice($lowConversion, 0, 10);

    $highPerformers = array_values(array_filter($funnelData, function ($p) {
        return $p['conversion_rate'] > 20 && $p['views'] > 5;
    }));
    usort($highPerformers, function ($a, $b) { return $b['conversion_rate'] <=> $a['conversion_rate']; });
    $highPerformers = array_slice($highPerformers, 0, 10);

    // ── Step 7: AI suggestions for low-converting products ──
    $suggestions = [];
    if (!empty($lowConversion)) {
        $aiAvailable = false;
        try {
            require_once __DIR__ . "/../helpers/claude-client.php";
            $claude = new ClaudeClient(ClaudeClient::DEFAULT_MODEL, 30, 0);
            if (!empty($_ENV['CLAUDE_API_KEY'])) {
                $aiAvailable = true;
            }
        } catch (Exception $e) {
            // Claude not available
        }

        if ($aiAvailable) {
            $productList = [];
            foreach (array_slice($lowConversion, 0, 5) as $lp) {
                $productList[] = "- {$lp['name']} (R$ " . number_format($lp['price'], 2, ',', '.') . "): {$lp['views']} views, {$lp['cart_adds']} carrinhos, {$lp['orders']} pedidos, conversao {$lp['conversion_rate']}%";
            }
            $productListStr = implode("\n", $productList);

            $aiResult = $claude->send(
                "Voce e um consultor de vendas de e-commerce de mercado/restaurante. Responda em JSON array.",
                [['role' => 'user', 'content' => "Estes produtos tem muitas visualizacoes mas poucas vendas. Para cada um, de 1 sugestao curta e pratica (max 100 chars) para melhorar a conversao. Responda em JSON: [{\"product\": \"nome\", \"tip\": \"dica\"}]\n\n{$productListStr}"]],
                1024
            );

            if ($aiResult['success']) {
                $parsed = ClaudeClient::parseJson($aiResult['text']);
                if ($parsed) {
                    $suggestions = $parsed;
                }
            }
        }

        // Fallback static tips if AI not available or failed
        if (empty($suggestions)) {
            $staticTips = [
                "Adicione fotos de melhor qualidade para atrair mais clientes",
                "Reduza o preco ou crie uma promocao temporaria",
                "Melhore a descricao do produto com detalhes e ingredientes",
                "Crie um combo incluindo este produto para aumentar o valor percebido",
                "Destaque este produto como 'mais vendido' ou 'recomendado pelo chef'",
            ];
            foreach (array_slice($lowConversion, 0, 5) as $i => $lp) {
                $suggestions[] = [
                    'product' => $lp['name'],
                    'tip' => $staticTips[$i % count($staticTips)],
                ];
            }
        }
    }

    $avgConversion = $totalViews > 0 ? round(($totalOrders / $totalViews) * 100, 2) : 0;

    $result = [
        'products' => $top20,
        'low_conversion' => $lowConversion,
        'high_performers' => $highPerformers,
        'suggestions' => $suggestions,
        'summary' => [
            'total_products' => count($productIds),
            'total_views' => $totalViews,
            'total_cart_adds' => array_sum($cartCounts),
            'total_orders' => $totalOrders,
            'avg_conversion' => $avgConversion,
        ],
    ];

    // ── Cache result in Redis for 30 minutes ──
    if ($redis->isAvailable()) {
        $redis->set($cacheKey, $result, 1800);
    }

    response(true, $result, "Funil de produtos calculado com sucesso.");

} catch (Exception $e) {
    error_log("[partner/product-funnel] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
