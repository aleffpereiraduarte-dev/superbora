<?php
/**
 * GET /api/mercado/partner/menu-engineering.php
 * Menu Engineering Analysis (BCG Matrix for restaurant/store menus)
 *
 * Params:
 *   periodo: 30d|60d|90d (default 30d)
 *   action: (empty)|price_suggestions
 *
 * Returns:
 *   - produtos: array with sales, mix%, margin, classification
 *   - resumo: count per classification
 *   - sugestoes: actionable suggestions
 *   - tendencias: trending up/down products
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $payload = om_auth()->requirePartner();
    $partner_id = (int)$payload['uid'];

    $action = $_GET['action'] ?? '';
    $periodo = $_GET['periodo'] ?? '30d';

    // Validate period
    $validPeriods = ['30d' => 30, '60d' => 60, '90d' => 90];
    $days = $validPeriods[$periodo] ?? 30;

    $startDate = date('Y-m-d', strtotime("-{$days} days"));
    $endDate = date('Y-m-d');

    // Previous period for trends
    $prevStartDate = date('Y-m-d', strtotime("-" . ($days * 2) . " days"));
    $prevEndDate = date('Y-m-d', strtotime("-{$days} days -1 day"));

    if ($action === 'price_suggestions') {
        handlePriceSuggestions($db, $partner_id, $startDate, $endDate, $days);
        exit;
    }

    // ──────────────────────────────────────────────
    // MAIN: Menu Engineering Analysis
    // ──────────────────────────────────────────────

    // Get all products sold in period with quantities and revenue
    $stmtProducts = $db->prepare("
        SELECT
            oi.product_id,
            oi.name as product_name,
            SUM(oi.quantity) as vendas_qtd,
            SUM(oi.price * oi.quantity) as vendas_valor,
            AVG(oi.price) as preco_medio,
            COUNT(DISTINCT oi.order_id) as pedidos_count
        FROM om_market_order_items oi
        INNER JOIN om_market_orders o ON o.order_id = oi.order_id
        WHERE o.partner_id = ?
          AND DATE(o.date_added) BETWEEN ? AND ?
          AND o.status NOT IN ('cancelado', 'cancelled')
        GROUP BY oi.product_id, oi.name
        ORDER BY vendas_qtd DESC
    ");
    $stmtProducts->execute([$partner_id, $startDate, $endDate]);
    $products = $stmtProducts->fetchAll();

    if (empty($products)) {
        response(true, [
            'produtos' => [],
            'resumo' => [
                'estrela' => 0,
                'cavalo_de_batalha' => 0,
                'quebra_cabeca' => 0,
                'abacaxi' => 0,
                'total' => 0
            ],
            'sugestoes' => [],
            'tendencias' => [],
            'periodo' => ['dias' => $days, 'inicio' => $startDate, 'fim' => $endDate]
        ], "Sem dados suficientes para analise");
        exit;
    }

    // Check if cost data exists
    $hasCostTable = false;
    try {
        $db->query("SELECT 1 FROM om_market_product_costs LIMIT 1");
        $hasCostTable = true;
    } catch (PDOException $e) {
        // Table doesn't exist — use estimated margins
    }

    // Get cost data if available
    $costs = [];
    if ($hasCostTable) {
        $stmtCosts = $db->prepare("
            SELECT product_id, cost
            FROM om_market_product_costs
            WHERE partner_id = ?
        ");
        $stmtCosts->execute([$partner_id]);
        foreach ($stmtCosts->fetchAll() as $row) {
            $costs[(int)$row['product_id']] = (float)$row['cost'];
        }
    }

    // Calculate totals for mix percentage
    $totalQuantity = 0;
    $totalRevenue = 0;
    foreach ($products as $p) {
        $totalQuantity += (int)$p['vendas_qtd'];
        $totalRevenue += (float)$p['vendas_valor'];
    }

    // Build product data with metrics
    $productData = [];
    $marginSum = 0;
    $mixSum = 0;
    $productCount = count($products);

    foreach ($products as $p) {
        $pid = (int)$p['product_id'];
        $qty = (int)$p['vendas_qtd'];
        $revenue = (float)$p['vendas_valor'];
        $avgPrice = (float)$p['preco_medio'];

        // Calculate margin
        if (isset($costs[$pid]) && $costs[$pid] > 0) {
            $cost = $costs[$pid];
            $margin = round((($avgPrice - $cost) / $avgPrice) * 100, 1);
        } else {
            // Estimate margin based on typical food/retail markup (30-40%)
            // Use revenue per unit as proxy — higher price items tend to have higher margins
            $margin = 35.0; // Default estimated margin
        }

        $mix = $totalQuantity > 0 ? round(($qty / $totalQuantity) * 100, 2) : 0;

        $productData[] = [
            'product_id' => $pid,
            'nome' => $p['product_name'],
            'vendas_qtd' => $qty,
            'vendas_valor' => round($revenue, 2),
            'preco_medio' => round($avgPrice, 2),
            'participacao_mix' => $mix,
            'margem' => $margin,
            'pedidos' => (int)$p['pedidos_count'],
            'has_cost_data' => isset($costs[$pid]),
        ];

        $marginSum += $margin;
        $mixSum += $mix;
    }

    // Calculate averages for classification thresholds
    $avgMargin = $productCount > 0 ? $marginSum / $productCount : 0;
    $avgMix = $productCount > 0 ? 100.0 / $productCount : 0; // Equal share threshold

    // Classify products
    $resumo = ['estrela' => 0, 'cavalo_de_batalha' => 0, 'quebra_cabeca' => 0, 'abacaxi' => 0];

    foreach ($productData as &$item) {
        $highPopularity = $item['participacao_mix'] >= $avgMix;
        $highMargin = $item['margem'] >= $avgMargin;

        if ($highPopularity && $highMargin) {
            $item['classificacao'] = 'estrela';
            $resumo['estrela']++;
        } elseif ($highPopularity && !$highMargin) {
            $item['classificacao'] = 'cavalo_de_batalha';
            $resumo['cavalo_de_batalha']++;
        } elseif (!$highPopularity && $highMargin) {
            $item['classificacao'] = 'quebra_cabeca';
            $resumo['quebra_cabeca']++;
        } else {
            $item['classificacao'] = 'abacaxi';
            $resumo['abacaxi']++;
        }
    }
    unset($item);

    $resumo['total'] = $productCount;

    // ──────────────────────────────────────────────
    // Generate Suggestions
    // ──────────────────────────────────────────────
    $sugestoes = [];

    // Stars: maintain prominence
    $stars = array_filter($productData, fn($p) => $p['classificacao'] === 'estrela');
    foreach (array_slice($stars, 0, 3) as $s) {
        $sugestoes[] = [
            'tipo' => 'manter',
            'classificacao' => 'estrela',
            'produto' => $s['nome'],
            'product_id' => $s['product_id'],
            'mensagem' => "Mantenha \"{$s['nome']}\" em destaque no cardapio. E seu produto estrela com alta demanda e boa margem.",
            'prioridade' => 1
        ];
    }

    // Plow Horses: increase price
    $horses = array_filter($productData, fn($p) => $p['classificacao'] === 'cavalo_de_batalha');
    foreach (array_slice($horses, 0, 3) as $h) {
        $suggestedIncrease = round($h['preco_medio'] * 0.12, 2);
        $sugestoes[] = [
            'tipo' => 'aumentar_preco',
            'classificacao' => 'cavalo_de_batalha',
            'produto' => $h['nome'],
            'product_id' => $h['product_id'],
            'mensagem' => "Aumente o preco de \"{$h['nome']}\" em 10-15% (+ R$ " . number_format($suggestedIncrease, 2, ',', '.') . "). Tem alta demanda mas margem baixa.",
            'prioridade' => 2
        ];
    }

    // Puzzles: promote more
    $puzzles = array_filter($productData, fn($p) => $p['classificacao'] === 'quebra_cabeca');
    foreach (array_slice($puzzles, 0, 3) as $q) {
        $sugestoes[] = [
            'tipo' => 'promover',
            'classificacao' => 'quebra_cabeca',
            'produto' => $q['nome'],
            'product_id' => $q['product_id'],
            'mensagem' => "Destaque \"{$q['nome']}\" com promocao ou combo para aumentar vendas. Tem boa margem mas baixa visibilidade.",
            'prioridade' => 2
        ];
    }

    // Dogs: consider removing
    $dogs = array_filter($productData, fn($p) => $p['classificacao'] === 'abacaxi');
    foreach (array_slice($dogs, 0, 3) as $d) {
        $sugestoes[] = [
            'tipo' => 'remover',
            'classificacao' => 'abacaxi',
            'produto' => $d['nome'],
            'product_id' => $d['product_id'],
            'mensagem' => "Considere remover \"{$d['nome']}\" do cardapio ou reformular. Baixa demanda e baixa margem.",
            'prioridade' => 3
        ];
    }

    // Sort suggestions by priority
    usort($sugestoes, fn($a, $b) => $a['prioridade'] - $b['prioridade']);

    // ──────────────────────────────────────────────
    // Trends: Compare current vs previous period
    // ──────────────────────────────────────────────
    $stmtPrev = $db->prepare("
        SELECT
            oi.product_id,
            oi.name as product_name,
            SUM(oi.quantity) as vendas_qtd,
            SUM(oi.price * oi.quantity) as vendas_valor
        FROM om_market_order_items oi
        INNER JOIN om_market_orders o ON o.order_id = oi.order_id
        WHERE o.partner_id = ?
          AND DATE(o.date_added) BETWEEN ? AND ?
          AND o.status NOT IN ('cancelado', 'cancelled')
        GROUP BY oi.product_id, oi.name
    ");
    $stmtPrev->execute([$partner_id, $prevStartDate, $prevEndDate]);
    $prevProducts = $stmtPrev->fetchAll();

    $prevMap = [];
    foreach ($prevProducts as $pp) {
        $prevMap[(int)$pp['product_id']] = [
            'vendas_qtd' => (int)$pp['vendas_qtd'],
            'vendas_valor' => (float)$pp['vendas_valor']
        ];
    }

    $tendencias = [];
    foreach ($productData as $pd) {
        $pid = $pd['product_id'];
        $prevQty = $prevMap[$pid]['vendas_qtd'] ?? 0;
        $currentQty = $pd['vendas_qtd'];

        if ($prevQty === 0 && $currentQty > 0) {
            $variacao = 100;
        } elseif ($prevQty > 0) {
            $variacao = round((($currentQty - $prevQty) / $prevQty) * 100, 1);
        } else {
            continue;
        }

        // Only include significant trends (>15% change)
        if (abs($variacao) >= 15) {
            $tendencias[] = [
                'product_id' => $pid,
                'nome' => $pd['nome'],
                'vendas_atual' => $currentQty,
                'vendas_anterior' => $prevQty,
                'variacao_percent' => $variacao,
                'direcao' => $variacao > 0 ? 'subindo' : 'descendo'
            ];
        }
    }

    // Sort by absolute change magnitude
    usort($tendencias, fn($a, $b) => abs($b['variacao_percent']) - abs($a['variacao_percent']));
    $tendencias = array_slice($tendencias, 0, 10);

    response(true, [
        'produtos' => $productData,
        'resumo' => $resumo,
        'sugestoes' => $sugestoes,
        'tendencias' => $tendencias,
        'metricas' => [
            'total_produtos_vendidos' => $productCount,
            'total_quantidade' => $totalQuantity,
            'total_receita' => round($totalRevenue, 2),
            'margem_media' => round($avgMargin, 1),
            'mix_medio' => round($avgMix, 2),
            'has_cost_data' => $hasCostTable && !empty($costs)
        ],
        'periodo' => [
            'dias' => $days,
            'inicio' => $startDate,
            'fim' => $endDate
        ]
    ], "Analise de engenharia de cardapio");

} catch (Exception $e) {
    error_log("[partner/menu-engineering] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar analise de cardapio", 500);
}

/**
 * Price Suggestions: Analyze elasticity and suggest optimal prices
 */
function handlePriceSuggestions($db, $partner_id, $startDate, $endDate, $days) {
    // Get products with their sales data at different price points
    // Group by product and price to see volume at each price level
    $stmtElasticity = $db->prepare("
        SELECT
            oi.product_id,
            oi.name as product_name,
            oi.price,
            SUM(oi.quantity) as quantity_at_price,
            COUNT(DISTINCT oi.order_id) as orders_at_price,
            MIN(DATE(o.date_added)) as first_sold,
            MAX(DATE(o.date_added)) as last_sold
        FROM om_market_order_items oi
        INNER JOIN om_market_orders o ON o.order_id = oi.order_id
        WHERE o.partner_id = ?
          AND DATE(o.date_added) BETWEEN ? AND ?
          AND o.status NOT IN ('cancelado', 'cancelled')
        GROUP BY oi.product_id, oi.name, oi.price
        ORDER BY oi.product_id, oi.price
    ");
    $stmtElasticity->execute([$partner_id, $startDate, $endDate]);
    $rows = $stmtElasticity->fetchAll();

    // Group by product
    $productPrices = [];
    foreach ($rows as $row) {
        $pid = (int)$row['product_id'];
        if (!isset($productPrices[$pid])) {
            $productPrices[$pid] = [
                'product_id' => $pid,
                'nome' => $row['product_name'],
                'price_points' => []
            ];
        }
        $productPrices[$pid]['price_points'][] = [
            'preco' => (float)$row['price'],
            'quantidade' => (int)$row['quantity_at_price'],
            'pedidos' => (int)$row['orders_at_price'],
            'receita' => round((float)$row['price'] * (int)$row['quantity_at_price'], 2),
            'primeiro_venda' => $row['first_sold'],
            'ultima_venda' => $row['last_sold']
        ];
    }

    // Get current prices from catalog
    $stmtCurrent = $db->prepare("
        SELECT pp.product_id, pp.price as preco_atual, pp.price_promo as preco_promo
        FROM om_market_products_price pp
        WHERE pp.partner_id = ? AND pp.status = '1'
    ");
    $stmtCurrent->execute([$partner_id]);
    $currentPrices = [];
    foreach ($stmtCurrent->fetchAll() as $row) {
        $currentPrices[(int)$row['product_id']] = [
            'preco_atual' => (float)$row['preco_atual'],
            'preco_promo' => $row['preco_promo'] ? (float)$row['preco_promo'] : null
        ];
    }

    $suggestions = [];
    foreach ($productPrices as $pid => $product) {
        $points = $product['price_points'];
        $currentPrice = $currentPrices[$pid] ?? null;

        // Find optimal price point (max revenue)
        $bestRevenue = 0;
        $bestPrice = 0;
        $totalQty = 0;
        $totalRevenue = 0;
        $minPrice = PHP_FLOAT_MAX;
        $maxPrice = 0;

        foreach ($points as $pt) {
            $totalQty += $pt['quantidade'];
            $totalRevenue += $pt['receita'];
            if ($pt['receita'] > $bestRevenue) {
                $bestRevenue = $pt['receita'];
                $bestPrice = $pt['preco'];
            }
            $minPrice = min($minPrice, $pt['preco']);
            $maxPrice = max($maxPrice, $pt['preco']);
        }

        $avgPrice = $totalQty > 0 ? round($totalRevenue / $totalQty, 2) : 0;
        $hasMultiplePrices = count($points) > 1;

        // Determine suggestion
        $sugestao = '';
        $tipo = 'manter';
        $precoSugerido = $currentPrice['preco_atual'] ?? $avgPrice;

        if ($hasMultiplePrices) {
            // Product was sold at different prices — analyze elasticity
            if ($bestPrice > $avgPrice) {
                $sugestao = "Produto teve melhor receita ao preco de R$ " . number_format($bestPrice, 2, ',', '.') . ". Considere ajustar.";
                $tipo = 'ajustar';
                $precoSugerido = $bestPrice;
            } else {
                $sugestao = "Preco atual parece otimizado. Demanda se manteve estavel.";
            }
        } else {
            // Single price — suggest based on volume
            if ($totalQty > ($days * 0.5)) {
                // High volume: can increase 5-10%
                $precoSugerido = round($avgPrice * 1.08, 2);
                $sugestao = "Alta demanda permite aumento de 5-10% no preco sem perda significativa.";
                $tipo = 'aumentar';
            } else {
                $sugestao = "Volume moderado. Mantenha preco atual e monitore.";
            }
        }

        $suggestions[] = [
            'product_id' => $pid,
            'nome' => $product['nome'],
            'preco_atual' => $currentPrice['preco_atual'] ?? $avgPrice,
            'preco_medio_vendido' => $avgPrice,
            'preco_sugerido' => $precoSugerido,
            'faixa_preco' => [
                'min' => round($minPrice, 2),
                'max' => round($maxPrice, 2)
            ],
            'total_vendas' => $totalQty,
            'total_receita' => round($totalRevenue, 2),
            'price_points' => $points,
            'sugestao' => $sugestao,
            'tipo' => $tipo,
            'tem_multiplos_precos' => $hasMultiplePrices
        ];
    }

    // Sort by revenue descending
    usort($suggestions, fn($a, $b) => $b['total_receita'] - $a['total_receita']);

    response(true, [
        'sugestoes_preco' => $suggestions,
        'total_produtos' => count($suggestions),
        'periodo' => [
            'dias' => $days,
            'inicio' => $startDate,
            'fim' => $endDate
        ]
    ], "Sugestoes de preco");
}
