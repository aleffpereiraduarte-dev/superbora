<?php
/**
 * GET /api/mercado/partner/ai-price-suggestions.php
 * AI-generated price optimization suggestions using Claude
 * Returns: [{product_id, product_name, current_price, suggested_price, reason, impact_estimate}]
 * Cached in Redis for 1 hour
 */
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../config/redis.php";
require_once __DIR__ . "/../helpers/claude-client.php";
require_once __DIR__ . "/../helpers/rate-limit.php";
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

    $forceRefresh = ($_GET['refresh'] ?? '') === '1';

    // Rate limiting: 5 AI price calls per hour per partner
    if (!checkRateLimit("ai_price_suggestions_{$partnerId}", 5, 3600)) {
        response(false, null, "Muitas requisicoes de IA. Tente novamente em 1 hora.", 429);
    }

    // Check Redis cache first (1 hour TTL)
    $cacheKey = "partner_price_suggestions_{$partnerId}";
    if (!$forceRefresh) {
        $cached = om_redis()->get($cacheKey);
        if ($cached) {
            response(true, $cached, "Sugestoes de preco (cache)");
        }
    }

    // Get partner info
    $stmtPartner = dbQuery($db, "
        SELECT name, trade_name, categoria, cidade
        FROM om_market_partners WHERE partner_id = ?
    ", [$partnerId]);
    $partnerInfo = $stmtPartner->fetch();

    if (!$partnerInfo) {
        response(false, null, "Parceiro nao encontrado", 404);
    }

    // Get partner's products with prices, costs, and sales data (last 30 days)
    $products = fetchProductData($db, $partnerId);

    if (empty($products)) {
        response(true, [
            'suggestions' => [],
            'generated_at' => date('c'),
        ], "Nenhum produto para analisar");
    }

    // Get competitor pricing context
    $category = $partnerInfo['categoria'] ?? '';
    $city = $partnerInfo['cidade'] ?? '';
    $competitorContext = getCompetitorContext($db, $partnerId, $category, $city, $products);

    // Build Claude prompt
    $systemPrompt = buildPricingSystemPrompt($partnerInfo);
    $userMessage = buildPricingUserMessage($products, $competitorContext);

    // Call Claude AI
    $claude = new ClaudeClient();
    $result = $claude->send($systemPrompt, [
        ['role' => 'user', 'content' => $userMessage],
    ], 4096);

    if (!$result['success']) {
        error_log("[ai-price-suggestions] Claude API error: " . ($result['error'] ?? 'unknown'));
        response(false, null, "Erro ao gerar sugestoes de IA. Tente novamente.", 500);
    }

    // Parse Claude's response into structured suggestions
    $suggestions = parseAISuggestions($result['text'] ?? '', $products);

    $responseData = [
        'suggestions' => $suggestions,
        'total' => count($suggestions),
        'generated_at' => date('c'),
        'model' => $result['model'] ?? 'claude-sonnet-4-20250514',
        'partner_name' => $partnerInfo['trade_name'] ?? $partnerInfo['name'],
    ];

    // Cache for 1 hour
    om_redis()->set($cacheKey, $responseData, 3600);

    response(true, $responseData, "Sugestoes de preco geradas");

} catch (Exception $e) {
    error_log("[partner/ai-price-suggestions] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}

/**
 * Fetch products with prices, costs, and sales data
 */
function fetchProductData(PDO $db, int $partnerId): array {
    $products = [];

    // Try catalog model first (om_market_products_price)
    $stmt = dbQuery($db, "
        SELECT
            pb.product_id,
            pb.name,
            pp.price,
            pp.price_promo as promotional_price,
            COALESCE(pc.custo, 0) as cost,
            COALESCE(sales.total_sold, 0) as total_sold_30d,
            COALESCE(sales.revenue, 0) as revenue_30d,
            cat.name as category_name
        FROM om_market_products_price pp
        INNER JOIN om_market_products_base pb ON pb.product_id = pp.product_id
        LEFT JOIN om_market_product_costs pc ON pc.product_id = pb.product_id AND pc.partner_id = ?
        LEFT JOIN om_market_categories cat ON cat.category_id = pb.category_id
        LEFT JOIN (
            SELECT oi.product_id,
                   SUM(oi.quantity) as total_sold,
                   SUM(oi.price * oi.quantity) as revenue
            FROM om_market_order_items oi
            INNER JOIN om_market_orders o ON o.order_id = oi.order_id
            WHERE o.partner_id = ?
              AND o.date_added >= NOW() - INTERVAL '30 days'
              AND o.status NOT IN ('cancelado', 'recusado')
            GROUP BY oi.product_id
        ) sales ON sales.product_id = pb.product_id
        WHERE pp.partner_id = ? AND pp.status = '1' AND pp.price > 0
        ORDER BY COALESCE(sales.revenue, 0) DESC
        LIMIT 30
    ", [$partnerId, $partnerId, $partnerId]);
    $products = $stmt->fetchAll();

    // Fallback: om_market_products
    if (empty($products)) {
        $stmt = dbQuery($db, "
            SELECT
                p.product_id,
                p.name,
                p.price,
                p.special_price as promotional_price,
                COALESCE(pc.custo, 0) as cost,
                COALESCE(sales.total_sold, 0) as total_sold_30d,
                COALESCE(sales.revenue, 0) as revenue_30d,
                p.category as category_name
            FROM om_market_products p
            LEFT JOIN om_market_product_costs pc ON pc.product_id = p.product_id AND pc.partner_id = ?
            LEFT JOIN (
                SELECT oi.product_id,
                       SUM(oi.quantity) as total_sold,
                       SUM(oi.price * oi.quantity) as revenue
                FROM om_market_order_items oi
                INNER JOIN om_market_orders o ON o.order_id = oi.order_id
                WHERE o.partner_id = ?
                  AND o.date_added >= NOW() - INTERVAL '30 days'
                  AND o.status NOT IN ('cancelado', 'recusado')
                GROUP BY oi.product_id
            ) sales ON sales.product_id = p.product_id
            WHERE p.partner_id = ? AND p.status::text = '1' AND p.price > 0
            ORDER BY COALESCE(sales.revenue, 0) DESC
            LIMIT 30
        ", [$partnerId, $partnerId, $partnerId]);
        $products = $stmt->fetchAll();
    }

    return $products;
}

/**
 * Get competitor price context for comparison
 */
function getCompetitorContext(PDO $db, int $partnerId, string $category, string $city, array $myProducts): string {
    if (empty($category) || empty($city)) return "Sem dados de concorrentes disponiveis.";

    $stmtComp = dbQuery($db, "
        SELECT partner_id FROM om_market_partners
        WHERE categoria = ? AND cidade = ? AND partner_id != ? AND status = '1'
        LIMIT 10
    ", [$category, $city, $partnerId]);
    $compIds = array_column($stmtComp->fetchAll(), 'partner_id');

    if (empty($compIds)) return "Sem concorrentes encontrados na mesma area.";

    $lines = [];
    $ph = implode(',', array_fill(0, count($compIds), '?'));

    foreach (array_slice($myProducts, 0, 15) as $p) {
        $name = $p['name'];
        $searchName = '%' . str_replace(['%', '_'], ['\\%', '\\_'], trim($name)) . '%';

        $stmtAvg = dbQuery($db, "
            SELECT AVG(price) as avg_price, MIN(price) as min_price, MAX(price) as max_price, COUNT(*) as cnt
            FROM (
                SELECT price FROM om_market_products WHERE partner_id IN ({$ph}) AND name ILIKE ? AND price > 0
                UNION ALL
                SELECT pp.price FROM om_market_products_price pp
                INNER JOIN om_market_products_base pb ON pb.product_id = pp.product_id
                WHERE pp.partner_id IN ({$ph}) AND pb.name ILIKE ? AND pp.price > 0
            ) comp
        ", array_merge($compIds, [$searchName], $compIds, [$searchName]));
        $comp = $stmtAvg->fetch();

        if ($comp && (int)$comp['cnt'] > 0) {
            $lines[] = "- {$name}: media R$ " . number_format((float)$comp['avg_price'], 2, ',', '.') .
                       " (min R$ " . number_format((float)$comp['min_price'], 2, ',', '.') .
                       ", max R$ " . number_format((float)$comp['max_price'], 2, ',', '.') .
                       ", {$comp['cnt']} concorrentes)";
        }
    }

    if (empty($lines)) return "Concorrentes encontrados, mas sem produtos comparaveis.";

    return "Precos de concorrentes na mesma area ({$category}, {$city}):\n" . implode("\n", $lines);
}

/**
 * Build system prompt for pricing AI
 */
function buildPricingSystemPrompt(array $partnerInfo): string {
    $name = $partnerInfo['trade_name'] ?? $partnerInfo['name'];
    $category = $partnerInfo['categoria'] ?? 'Geral';

    return "Voce e um especialista em precificacao e estrategia de vendas para delivery.
Analise os produtos do parceiro '{$name}' (categoria: {$category}) e sugira otimizacoes de preco.

REGRAS:
1. Responda SOMENTE em formato JSON valido, como um array de objetos.
2. Cada objeto deve ter: product_id (int), product_name (string), current_price (float), suggested_price (float), reason (string em portugues), impact_estimate (string em portugues).
3. Foque em produtos com oportunidades reais de otimizacao (margem baixa, preco fora do mercado, alta/baixa demanda).
4. Considere: custos (quando disponiveis), demanda (vendas 30d), concorrencia, e margens saudaveis.
5. Nao sugira mudancas menores que 3% do preco atual (insignificantes).
6. Razoes devem ser praticas e especificas (ex: 'Preco 15% acima da media do mercado, reduzir para aumentar volume').
7. impact_estimate deve ser uma estimativa de impacto (ex: '+12% volume estimado', '-5% margem mas +20% vendas').
8. Retorne no MAXIMO 10 sugestoes, priorizando as de maior impacto.
9. Se nao houver sugestoes relevantes, retorne um array vazio [].
10. NAO inclua markdown, explicacoes ou texto fora do JSON.";
}

/**
 * Build user message with product data
 */
function buildPricingUserMessage(array $products, string $competitorContext): string {
    $lines = ["Produtos do parceiro (ultimos 30 dias):\n"];

    foreach ($products as $p) {
        $price = (float)$p['price'];
        $cost = (float)$p['cost'];
        $sold = (int)$p['total_sold_30d'];
        $revenue = (float)$p['revenue_30d'];
        $margin = $cost > 0 ? round((($price - $cost) / $price) * 100, 1) : 'N/A';
        $promo = !empty($p['promotional_price']) ? " (promo: R$ " . number_format((float)$p['promotional_price'], 2, ',', '.') . ")" : '';

        $lines[] = "- ID:{$p['product_id']} | {$p['name']} | R$ " . number_format($price, 2, ',', '.') .
                   $promo .
                   " | Custo: R$ " . number_format($cost, 2, ',', '.') .
                   " | Margem: {$margin}%" .
                   " | Vendidos: {$sold}" .
                   " | Receita: R$ " . number_format($revenue, 2, ',', '.') .
                   " | Cat: " . ($p['category_name'] ?? 'N/A');
    }

    $lines[] = "\n" . $competitorContext;
    $lines[] = "\nGere sugestoes de otimizacao de preco em formato JSON:";

    return implode("\n", $lines);
}

/**
 * Parse AI response into structured suggestions
 */
function parseAISuggestions(string $aiText, array $products): array {
    // Extract JSON from the response
    $jsonText = $aiText;

    // Try to find JSON array in the response
    if (preg_match('/\[[\s\S]*\]/', $aiText, $matches)) {
        $jsonText = $matches[0];
    }

    $parsed = json_decode($jsonText, true);
    if (!is_array($parsed)) {
        error_log("[ai-price-suggestions] Failed to parse AI response as JSON: " . substr($aiText, 0, 500));
        return [];
    }

    // Build product lookup for validation
    $productLookup = [];
    foreach ($products as $p) {
        $productLookup[(int)$p['product_id']] = [
            'name' => $p['name'],
            'price' => (float)$p['price'],
        ];
    }

    $suggestions = [];
    foreach ($parsed as $item) {
        if (!is_array($item)) continue;

        $productId = (int)($item['product_id'] ?? 0);
        $suggestedPrice = (float)($item['suggested_price'] ?? 0);

        // Validate product belongs to partner
        if (!isset($productLookup[$productId])) continue;

        // Ensure suggested price is reasonable (0.01 to 10x current price)
        $currentPrice = $productLookup[$productId]['price'];
        if ($suggestedPrice < 0.01 || $suggestedPrice > $currentPrice * 10) continue;

        $diff = $suggestedPrice - $currentPrice;
        $pctChange = $currentPrice > 0 ? round(($diff / $currentPrice) * 100, 1) : 0;

        $suggestions[] = [
            'product_id' => $productId,
            'product_name' => $item['product_name'] ?? $productLookup[$productId]['name'],
            'current_price' => round($currentPrice, 2),
            'suggested_price' => round($suggestedPrice, 2),
            'difference' => round($diff, 2),
            'change_percent' => $pctChange,
            'direction' => $diff > 0 ? 'increase' : ($diff < 0 ? 'decrease' : 'same'),
            'reason' => $item['reason'] ?? 'Otimizacao sugerida pela IA',
            'impact_estimate' => $item['impact_estimate'] ?? 'Impacto estimado nao disponivel',
        ];
    }

    // Sort by absolute change percent (biggest opportunities first)
    usort($suggestions, fn($a, $b) => abs($b['change_percent']) <=> abs($a['change_percent']));

    return array_slice($suggestions, 0, 10);
}
