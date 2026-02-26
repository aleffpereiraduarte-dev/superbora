<?php
/**
 * AI Tools API — Comprehensive AI-powered menu and product tools
 *
 * POST /api/mercado/partner/ai-tools.php
 *
 * Actions:
 * - generate_descriptions: Generate appetizing descriptions for products
 * - suggest_prices: Suggest optimal prices based on category/market
 * - nutritional_info: Estimate calories and nutritional info
 * - improve_product: Enhance product name, description, and tags
 * - suggest_combos: Suggest product combos based on menu
 * - menu_analysis: Analyze entire menu for improvements
 * - generate_tags: Generate searchable tags/keywords
 * - translate: Translate product info to another language
 * - bulk_enhance: Enhance multiple products at once
 * - photo_analysis: Analyze a product photo for description
 *
 * Auth: Bearer token (partner type)
 */
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../helpers/rate-limit.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

const CLAUDE_MODEL = 'claude-sonnet-4-20250514';
const MAX_TOKENS = 4096;

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Token ausente", 401);

    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== OmAuth::USER_TYPE_PARTNER) {
        response(false, null, "Nao autorizado", 401);
    }

    $partnerId = (int)$payload['uid'];
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method !== 'POST') {
        response(false, null, "Use POST", 405);
    }

    // Rate limit: 30 AI calls per hour
    if (!checkRateLimit("ai_tools_{$partnerId}", 30, 60)) {
        response(false, null, "Limite de uso atingido. Tente novamente em 1 hora.", 429);
    }

    $input = getInput();
    $action = trim($input['action'] ?? '');

    if (!$action) {
        response(false, null, "Campo 'action' e obrigatorio", 400);
    }

    // Get partner info for context
    $stmtPartner = $db->prepare("SELECT name, categoria, descricao FROM om_market_partners WHERE partner_id = ?");
    $stmtPartner->execute([$partnerId]);
    $partner = $stmtPartner->fetch(PDO::FETCH_ASSOC);
    $storeName = $partner['name'] ?? 'Loja';
    $storeType = $partner['categoria'] ?? '';
    $storeDesc = $partner['descricao'] ?? '';

    switch ($action) {
        case 'generate_descriptions':
            handleGenerateDescriptions($db, $partnerId, $input, $storeName, $storeType);
            break;

        case 'suggest_prices':
            handleSuggestPrices($db, $partnerId, $input, $storeName, $storeType);
            break;

        case 'nutritional_info':
            handleNutritionalInfo($input);
            break;

        case 'improve_product':
            handleImproveProduct($input, $storeName, $storeType);
            break;

        case 'suggest_combos':
            handleSuggestCombos($db, $partnerId, $storeName, $storeType);
            break;

        case 'menu_analysis':
            handleMenuAnalysis($db, $partnerId, $storeName, $storeType);
            break;

        case 'generate_tags':
            handleGenerateTags($input, $storeType);
            break;

        case 'translate':
            handleTranslate($input);
            break;

        case 'bulk_enhance':
            handleBulkEnhance($db, $partnerId, $input, $storeName, $storeType);
            break;

        case 'photo_analysis':
            handlePhotoAnalysis();
            break;

        case 'apply_description':
            handleApplyDescription($db, $partnerId, $input);
            break;

        case 'apply_bulk':
            handleApplyBulk($db, $partnerId, $input);
            break;

        default:
            response(false, null, "Acao '{$action}' nao reconhecida", 400);
    }

} catch (Exception $e) {
    error_log("[partner/ai-tools] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}

// =====================================================================
// Handler Functions
// =====================================================================

/**
 * Generate appetizing descriptions for one or more products
 */
function handleGenerateDescriptions($db, $partnerId, $input, $storeName, $storeType) {
    $products = $input['products'] ?? [];
    if (empty($products)) {
        // If no products provided, fetch from DB
        $productIds = $input['product_ids'] ?? [];
        if (!empty($productIds)) {
            $placeholders = implode(',', array_fill(0, count($productIds), '?'));
            $stmt = $db->prepare("
                SELECT pb.product_id as id, pb.name as nome, pb.description as descricao, pp.price as preco,
                       c.name as categoria
                FROM om_market_products_base pb
                JOIN om_market_products_price pp ON pb.product_id = pp.product_id AND pp.partner_id = ?
                LEFT JOIN om_market_categories c ON pb.category_id = c.category_id
                WHERE pb.product_id IN ({$placeholders})
            ");
            $stmt->execute(array_merge([$partnerId], $productIds));
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    if (empty($products)) {
        response(false, null, "Envie 'products' ou 'product_ids'", 400);
    }

    $style = $input['style'] ?? 'appetizing'; // appetizing, professional, casual, gourmet, fun
    $maxLength = min((int)($input['max_length'] ?? 150), 300);

    $productList = "";
    foreach ($products as $p) {
        $name = $p['nome'] ?? $p['name'] ?? '';
        $price = $p['preco'] ?? $p['price'] ?? 0;
        $cat = $p['categoria'] ?? $p['category'] ?? '';
        $currentDesc = $p['descricao'] ?? $p['description'] ?? '';
        $id = $p['id'] ?? $p['product_id'] ?? 0;
        $productList .= "- ID:{$id} | {$name} | R\${$price} | Categoria: {$cat} | Descricao atual: {$currentDesc}\n";
    }

    $styleGuide = match($style) {
        'professional' => "Use linguagem profissional e descritiva, focando em ingredientes e metodo de preparo.",
        'casual' => "Use linguagem casual e amigavel, como se estivesse conversando com um amigo.",
        'gourmet' => "Use linguagem sofisticada e gastronomica, destacando tecnicas e harmonizacoes.",
        'fun' => "Use linguagem divertida e criativa, com emojis e expressoes descontraidas.",
        default => "Use linguagem apetitosa que de agua na boca, destacando sabores e texturas.",
    };

    $systemPrompt = "Voce e um copywriter especialista em gastronomia e delivery. Crie descricoes irresistiveis para produtos de um restaurante/delivery.\n\n";
    $systemPrompt .= "Regras:\n";
    $systemPrompt .= "1. Retorne APENAS JSON valido, sem markdown.\n";
    $systemPrompt .= "2. Cada descricao deve ter no maximo {$maxLength} caracteres.\n";
    $systemPrompt .= "3. {$styleGuide}\n";
    $systemPrompt .= "4. Se o produto ja tem descricao, melhore-a mantendo a essencia.\n";
    $systemPrompt .= "5. Nao invente ingredientes que nao sejam obvios pelo nome do produto.\n";
    $systemPrompt .= "6. Destaque diferenciais: artesanal, caseiro, fresco, etc quando apropriado.\n\n";
    $systemPrompt .= "Formato de resposta:\n";
    $systemPrompt .= '{
  "descriptions": [
    { "id": 123, "name": "Nome", "description": "Nova descricao apetitosa", "short_description": "Versao curta (max 60 chars)" }
  ]
}';

    $userMsg = "Loja: {$storeName} ({$storeType})\n\nProdutos para criar descricoes:\n{$productList}";

    $result = callClaudeAPI($systemPrompt, $userMsg);
    if (!$result['success']) {
        response(false, null, "Erro na IA: " . $result['error'], 500);
    }

    $parsed = parseJsonResponse($result['response']);
    if (!$parsed) {
        response(false, ['raw' => $result['response']], "Erro ao processar resposta da IA", 422);
    }

    response(true, [
        'descriptions' => $parsed['descriptions'] ?? [],
        'style' => $style,
        'tokens_used' => $result['tokens_used'] ?? 0,
    ], "Descricoes geradas com sucesso!");
}

/**
 * Suggest optimal prices
 */
function handleSuggestPrices($db, $partnerId, $input, $storeName, $storeType) {
    $productIds = $input['product_ids'] ?? [];

    // Fetch products with sales data
    $query = "
        SELECT pb.product_id as id, pb.name as nome, pp.price as preco_atual,
               c.name as categoria,
               COALESCE(sales.total_vendas, 0) as total_vendas,
               COALESCE(sales.qtd_vendida, 0) as qtd_vendida
        FROM om_market_products_base pb
        JOIN om_market_products_price pp ON pb.product_id = pp.product_id AND pp.partner_id = ?
        LEFT JOIN om_market_categories c ON pb.category_id = c.category_id
        LEFT JOIN (
            SELECT oi.product_id,
                   SUM(oi.quantity * oi.price) as total_vendas,
                   SUM(oi.quantity) as qtd_vendida
            FROM om_market_order_items oi
            JOIN om_market_orders o ON oi.order_id = o.id
            WHERE o.partner_id = ? AND o.status = 'entregue'
            AND o.created_at > NOW() - INTERVAL '90 days'
            GROUP BY oi.product_id
        ) sales ON pb.product_id = sales.product_id
        WHERE pp.status = 1
    ";
    $params = [$partnerId, $partnerId];

    if (!empty($productIds)) {
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $query .= " AND pb.product_id IN ({$placeholders})";
        $params = array_merge($params, $productIds);
    }

    $query .= " ORDER BY pb.name LIMIT 50";
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($products)) {
        response(false, null, "Nenhum produto encontrado", 404);
    }

    $productList = "";
    foreach ($products as $p) {
        $productList .= "- {$p['nome']} | Preco atual: R\${$p['preco_atual']} | Categoria: {$p['categoria']} | Vendas 90d: {$p['qtd_vendida']} un (R\${$p['total_vendas']})\n";
    }

    $systemPrompt = "Voce e um consultor de pricing para restaurantes/delivery no Brasil. Analise os precos atuais e sugira otimizacoes.\n\n";
    $systemPrompt .= "Regras:\n";
    $systemPrompt .= "1. Retorne APENAS JSON valido.\n";
    $systemPrompt .= "2. Considere: tipo de cozinha, categoria do produto, volume de vendas.\n";
    $systemPrompt .= "3. Precos devem ser realistas para o mercado brasileiro de delivery.\n";
    $systemPrompt .= "4. Sugira precos arredondados (ex: R\$29,90 em vez de R\$29,37).\n";
    $systemPrompt .= "5. Explique o racional de cada sugestao.\n\n";
    $systemPrompt .= 'Formato:
{
  "suggestions": [
    {
      "nome": "Produto",
      "preco_atual": 29.90,
      "preco_sugerido": 34.90,
      "variacao_pct": 16.7,
      "confianca": "alta",
      "racional": "Preco abaixo da media para a categoria. Pode aumentar sem impactar vendas."
    }
  ],
  "resumo": "Analise geral do pricing"
}';

    $userMsg = "Loja: {$storeName} (Tipo: {$storeType})\n\nProdutos:\n{$productList}";

    $result = callClaudeAPI($systemPrompt, $userMsg);
    if (!$result['success']) {
        response(false, null, "Erro na IA: " . $result['error'], 500);
    }

    $parsed = parseJsonResponse($result['response']);
    response(true, $parsed ?? ['raw' => $result['response']], "Sugestoes de precos geradas!");
}

/**
 * Estimate nutritional info
 */
function handleNutritionalInfo($input) {
    $products = $input['products'] ?? [];
    if (empty($products)) {
        response(false, null, "Envie 'products' com nome e descricao", 400);
    }

    $productList = "";
    foreach ($products as $p) {
        $name = $p['nome'] ?? $p['name'] ?? '';
        $desc = $p['descricao'] ?? $p['description'] ?? '';
        $productList .= "- {$name}: {$desc}\n";
    }

    $systemPrompt = "Voce e um nutricionista. Estime informacoes nutricionais aproximadas para produtos de restaurante.\n\n";
    $systemPrompt .= "IMPORTANTE: Deixe claro que sao ESTIMATIVAS e que valores reais podem variar.\n";
    $systemPrompt .= "Retorne APENAS JSON valido.\n\n";
    $systemPrompt .= 'Formato:
{
  "nutritional_info": [
    {
      "nome": "Produto",
      "calorias": 450,
      "proteinas_g": 25,
      "carboidratos_g": 45,
      "gorduras_g": 15,
      "fibras_g": 3,
      "sodio_mg": 800,
      "porcao": "1 unidade (300g)",
      "alergenos": ["gluten", "lactose"],
      "classificacao": "moderado",
      "dica_saude": "Rico em proteinas. Boa opcao para refeicao principal."
    }
  ],
  "aviso": "Valores nutricionais estimados. Podem variar de acordo com o preparo."
}';

    $result = callClaudeAPI($systemPrompt, "Estime as informacoes nutricionais:\n{$productList}");
    if (!$result['success']) {
        response(false, null, "Erro na IA: " . $result['error'], 500);
    }

    $parsed = parseJsonResponse($result['response']);
    response(true, $parsed ?? ['raw' => $result['response']], "Info nutricional estimada!");
}

/**
 * Improve a single product (name, description, tags)
 */
function handleImproveProduct($input, $storeName, $storeType) {
    $name = trim($input['nome'] ?? $input['name'] ?? '');
    $description = trim($input['descricao'] ?? $input['description'] ?? '');
    $price = (float)($input['preco'] ?? $input['price'] ?? 0);
    $category = trim($input['categoria'] ?? $input['category'] ?? '');

    if (!$name) {
        response(false, null, "Nome do produto e obrigatorio", 400);
    }

    $systemPrompt = "Voce e um especialista em marketing gastronomico. Melhore os dados de um produto para um cardapio de delivery.\n\n";
    $systemPrompt .= "Retorne APENAS JSON valido.\n\n";
    $systemPrompt .= 'Formato:
{
  "nome_original": "...",
  "nome_melhorado": "...",
  "descricao_curta": "... (max 60 chars)",
  "descricao_completa": "... (max 200 chars, apetitosa e descritiva)",
  "tags": ["tag1", "tag2", "tag3"],
  "categoria_sugerida": "...",
  "dicas_foto": "Dica de como tirar uma boa foto deste produto",
  "destaque": "O que torna este produto especial",
  "sugestao_combo": "Sugestao de acompanhamento ideal"
}';

    $userMsg = "Loja: {$storeName} ({$storeType})\n\nProduto para melhorar:\n";
    $userMsg .= "Nome: {$name}\nDescricao atual: {$description}\nPreco: R\${$price}\nCategoria: {$category}";

    $result = callClaudeAPI($systemPrompt, $userMsg);
    if (!$result['success']) {
        response(false, null, "Erro na IA: " . $result['error'], 500);
    }

    $parsed = parseJsonResponse($result['response']);
    response(true, $parsed ?? ['raw' => $result['response']], "Produto aprimorado!");
}

/**
 * Suggest product combos based on the full menu
 */
function handleSuggestCombos($db, $partnerId, $storeName, $storeType) {
    // Fetch all active products
    $stmt = $db->prepare("
        SELECT pb.product_id as id, pb.name as nome, pp.price as preco, c.name as categoria
        FROM om_market_products_base pb
        JOIN om_market_products_price pp ON pb.product_id = pp.product_id AND pp.partner_id = ?
        LEFT JOIN om_market_categories c ON pb.category_id = c.category_id
        WHERE pp.status = 1
        ORDER BY c.name, pb.name
        LIMIT 100
    ");
    $stmt->execute([$partnerId]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($products) < 2) {
        response(false, null, "Precisa de pelo menos 2 produtos para sugerir combos", 400);
    }

    // Also get top selling pairs from orders
    $stmtPairs = $db->prepare("
        SELECT oi1.product_id as p1, oi2.product_id as p2, COUNT(*) as freq
        FROM om_market_order_items oi1
        JOIN om_market_order_items oi2 ON oi1.order_id = oi2.order_id AND oi1.product_id < oi2.product_id
        JOIN om_market_orders o ON oi1.order_id = o.id
        WHERE o.partner_id = ? AND o.status = 'entregue'
        AND o.created_at > NOW() - INTERVAL '60 days'
        GROUP BY oi1.product_id, oi2.product_id
        ORDER BY freq DESC
        LIMIT 20
    ");
    $stmtPairs->execute([$partnerId]);
    $topPairs = $stmtPairs->fetchAll(PDO::FETCH_ASSOC);

    $productList = "";
    foreach ($products as $p) {
        $productList .= "- ID:{$p['id']} {$p['nome']} (R\${$p['preco']}) [{$p['categoria']}]\n";
    }

    $pairsList = "";
    foreach ($topPairs as $pair) {
        $pairsList .= "- Produto {$pair['p1']} + Produto {$pair['p2']}: {$pair['freq']} vezes juntos\n";
    }

    $systemPrompt = "Voce e um especialista em cardapios e combos para delivery. Sugira combos lucrativos.\n\n";
    $systemPrompt .= "Regras:\n";
    $systemPrompt .= "1. Retorne APENAS JSON valido.\n";
    $systemPrompt .= "2. Sugira 5-8 combos, variando entre economicos e premium.\n";
    $systemPrompt .= "3. O preco do combo deve ter desconto de 10-20% vs comprar separado.\n";
    $systemPrompt .= "4. Considere combinacoes logicas (prato + bebida, entrada + prato).\n";
    $systemPrompt .= "5. Use os dados de compras frequentes para priorizar combos populares.\n\n";
    $systemPrompt .= 'Formato:
{
  "combos": [
    {
      "nome": "Combo Familia",
      "descricao": "Descricao atrativa do combo",
      "produtos": [{"id": 1, "nome": "Produto 1"}, {"id": 2, "nome": "Produto 2"}],
      "preco_separado": 59.80,
      "preco_combo": 49.90,
      "economia": 9.90,
      "economia_pct": 16.6,
      "tipo": "familia",
      "justificativa": "Combina bem e produtos frequentemente comprados juntos"
    }
  ],
  "dicas": ["Dica para maximizar vendas de combos"]
}';

    $userMsg = "Loja: {$storeName} ({$storeType})\n\nCardapio completo:\n{$productList}";
    if ($pairsList) {
        $userMsg .= "\n\nProdutos comprados juntos frequentemente:\n{$pairsList}";
    }

    $result = callClaudeAPI($systemPrompt, $userMsg);
    if (!$result['success']) {
        response(false, null, "Erro na IA: " . $result['error'], 500);
    }

    $parsed = parseJsonResponse($result['response']);
    response(true, $parsed ?? ['raw' => $result['response']], "Combos sugeridos!");
}

/**
 * Full menu analysis and improvement suggestions
 */
function handleMenuAnalysis($db, $partnerId, $storeName, $storeType) {
    // Fetch products with sales data
    $stmt = $db->prepare("
        SELECT pb.product_id as id, pb.name as nome, pb.description as descricao,
               pp.price as preco, c.name as categoria,
               COALESCE(s.qtd, 0) as vendas_qtd,
               COALESCE(s.valor, 0) as vendas_valor
        FROM om_market_products_base pb
        JOIN om_market_products_price pp ON pb.product_id = pp.product_id AND pp.partner_id = ?
        LEFT JOIN om_market_categories c ON pb.category_id = c.category_id
        LEFT JOIN (
            SELECT oi.product_id, SUM(oi.quantity) as qtd, SUM(oi.quantity * oi.price) as valor
            FROM om_market_order_items oi
            JOIN om_market_orders o ON oi.order_id = o.id
            WHERE o.partner_id = ? AND o.status = 'entregue'
            AND o.created_at > NOW() - INTERVAL '30 days'
            GROUP BY oi.product_id
        ) s ON pb.product_id = s.product_id
        WHERE pp.status = 1
        ORDER BY s.qtd DESC NULLS LAST
        LIMIT 100
    ");
    $stmt->execute([$partnerId, $partnerId]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get order stats
    $stmtStats = $db->prepare("
        SELECT COUNT(*) as total_pedidos,
               AVG(total) as ticket_medio,
               COUNT(CASE WHEN status = 'cancelado' THEN 1 END) as cancelados
        FROM om_market_orders
        WHERE partner_id = ? AND created_at > NOW() - INTERVAL '30 days'
    ");
    $stmtStats->execute([$partnerId]);
    $stats = $stmtStats->fetch(PDO::FETCH_ASSOC);

    $productList = "";
    foreach ($products as $p) {
        $hasDesc = !empty($p['descricao']) ? 'Sim' : 'Nao';
        $productList .= "- {$p['nome']} | R\${$p['preco']} | Cat: {$p['categoria']} | Vendas: {$p['vendas_qtd']}un (R\${$p['vendas_valor']}) | Descricao: {$hasDesc}\n";
    }

    $systemPrompt = "Voce e um consultor de cardapio digital para restaurantes de delivery no Brasil. Faca uma analise completa.\n\n";
    $systemPrompt .= "Retorne APENAS JSON valido.\n\n";
    $systemPrompt .= 'Formato:
{
  "score_geral": 72,
  "nota_variedade": 80,
  "nota_precos": 65,
  "nota_descricoes": 40,
  "nota_categorias": 75,
  "nota_estrategia": 60,
  "pontos_fortes": ["Boa variedade de pratos principais", "Precos competitivos"],
  "pontos_fracos": ["Muitos produtos sem descricao", "Falta bebidas no cardapio"],
  "acoes_imediatas": [
    {
      "prioridade": "alta",
      "acao": "Adicionar descricoes aos 10 produtos mais vendidos",
      "impacto": "Aumento estimado de 15% na conversao",
      "produtos_afetados": ["Produto 1", "Produto 2"]
    }
  ],
  "sugestoes_novos_produtos": [
    {
      "nome": "Sugestao de novo produto",
      "categoria": "Categoria",
      "preco_sugerido": 19.90,
      "justificativa": "Complementa o cardapio e atende demanda de sobremesas"
    }
  ],
  "analise_categorias": "Analise da organizacao por categorias",
  "analise_precos": "Analise da faixa de precos",
  "benchmark": "Comparacao com padroes do mercado"
}';

    $userMsg = "Loja: {$storeName} (Tipo: {$storeType})\n";
    $userMsg .= "Pedidos ultimos 30 dias: {$stats['total_pedidos']} | Ticket medio: R\$" . number_format($stats['ticket_medio'] ?? 0, 2) . " | Cancelamentos: {$stats['cancelados']}\n\n";
    $userMsg .= "Cardapio atual:\n{$productList}";

    $result = callClaudeAPI($systemPrompt, $userMsg, 6000);
    if (!$result['success']) {
        response(false, null, "Erro na IA: " . $result['error'], 500);
    }

    $parsed = parseJsonResponse($result['response']);
    response(true, $parsed ?? ['raw' => $result['response']], "Analise de cardapio concluida!");
}

/**
 * Generate searchable tags for products
 */
function handleGenerateTags($input, $storeType) {
    $products = $input['products'] ?? [];
    if (empty($products)) {
        response(false, null, "Envie 'products'", 400);
    }

    $productList = "";
    foreach ($products as $p) {
        $name = $p['nome'] ?? $p['name'] ?? '';
        $desc = $p['descricao'] ?? $p['description'] ?? '';
        $productList .= "- {$name}: {$desc}\n";
    }

    $systemPrompt = "Gere tags de busca para produtos de delivery. Retorne APENAS JSON.\n\n";
    $systemPrompt .= 'Formato: { "tags": [ { "nome": "Produto", "tags": ["tag1", "tag2", "tag3", "tag4", "tag5"] } ] }';
    $systemPrompt .= "\n\nInclua: ingredientes principais, tipo de prato, metodo de preparo, dieta (vegano, sem gluten), ocasiao (almoco, lanche).";

    $result = callClaudeAPI($systemPrompt, "Tipo de cozinha: {$storeType}\n\nProdutos:\n{$productList}");
    if (!$result['success']) {
        response(false, null, "Erro na IA: " . $result['error'], 500);
    }

    $parsed = parseJsonResponse($result['response']);
    response(true, $parsed ?? ['raw' => $result['response']], "Tags geradas!");
}

/**
 * Translate product info
 */
function handleTranslate($input) {
    $products = $input['products'] ?? [];
    $targetLang = $input['idioma'] ?? 'en'; // en, es, fr, it, de, ja, zh

    if (empty($products)) {
        response(false, null, "Envie 'products'", 400);
    }

    $langNames = [
        'en' => 'Ingles', 'es' => 'Espanhol', 'fr' => 'Frances',
        'it' => 'Italiano', 'de' => 'Alemao', 'ja' => 'Japones', 'zh' => 'Chines'
    ];
    $langName = $langNames[$targetLang] ?? 'Ingles';

    $productList = "";
    foreach ($products as $p) {
        $name = $p['nome'] ?? $p['name'] ?? '';
        $desc = $p['descricao'] ?? $p['description'] ?? '';
        $productList .= "- Nome: {$name} | Descricao: {$desc}\n";
    }

    $systemPrompt = "Traduza os produtos para {$langName}. Retorne APENAS JSON valido.\n";
    $systemPrompt .= "Mantenha nomes proprios quando apropriado. Adapte culturalmente.\n\n";
    $systemPrompt .= 'Formato: { "translations": [ { "nome_original": "...", "nome_traduzido": "...", "descricao_traduzida": "..." } ] }';

    $result = callClaudeAPI($systemPrompt, "Produtos:\n{$productList}");
    if (!$result['success']) {
        response(false, null, "Erro na IA: " . $result['error'], 500);
    }

    $parsed = parseJsonResponse($result['response']);
    response(true, array_merge($parsed ?? ['raw' => $result['response']], ['idioma' => $targetLang]), "Traduzido para {$langName}!");
}

/**
 * Bulk enhance products (descriptions + improvements)
 */
function handleBulkEnhance($db, $partnerId, $input, $storeName, $storeType) {
    $limit = min((int)($input['limit'] ?? 20), 50);
    $onlyEmpty = (bool)($input['only_empty_descriptions'] ?? true);

    $query = "
        SELECT pb.product_id as id, pb.name as nome, pb.description as descricao,
               pp.price as preco, c.name as categoria
        FROM om_market_products_base pb
        JOIN om_market_products_price pp ON pb.product_id = pp.product_id AND pp.partner_id = ?
        LEFT JOIN om_market_categories c ON pb.category_id = c.category_id
        WHERE pp.status = 1
    ";
    $params = [$partnerId];

    if ($onlyEmpty) {
        $query .= " AND (pb.description IS NULL OR pb.description = '')";
    }

    $query .= " ORDER BY pb.name LIMIT ?";
    $params[] = $limit;

    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($products)) {
        response(true, ['enhanced' => [], 'count' => 0], "Todos os produtos ja tem descricao!");
    }

    $productList = "";
    foreach ($products as $p) {
        $productList .= "- ID:{$p['id']} | {$p['nome']} | R\${$p['preco']} | Cat: {$p['categoria']} | Desc: " . ($p['descricao'] ?: '(vazio)') . "\n";
    }

    $systemPrompt = "Voce e um copywriter gastronomico. Crie/melhore descricoes para todos os produtos listados.\n\n";
    $systemPrompt .= "Regras:\n1. Retorne APENAS JSON.\n2. Descricoes ate 150 chars.\n3. Apetitosas e descritivas.\n4. Destaque ingredientes e diferenciais.\n\n";
    $systemPrompt .= 'Formato: { "enhanced": [ { "id": 123, "nome": "...", "descricao": "Nova descricao apetitosa", "tags": ["tag1", "tag2"] } ] }';

    $userMsg = "Loja: {$storeName} ({$storeType})\n\nProdutos:\n{$productList}";

    $result = callClaudeAPI($systemPrompt, $userMsg, 6000);
    if (!$result['success']) {
        response(false, null, "Erro na IA: " . $result['error'], 500);
    }

    $parsed = parseJsonResponse($result['response']);
    $enhanced = $parsed['enhanced'] ?? [];

    response(true, [
        'enhanced' => $enhanced,
        'count' => count($enhanced),
        'tokens_used' => $result['tokens_used'] ?? 0,
    ], count($enhanced) . " produtos aprimorados!");
}

/**
 * Analyze a product photo
 */
function handlePhotoAnalysis() {
    if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        response(false, null, "Envie uma foto do produto (campo 'image')", 400);
    }

    $file = $_FILES['image'];
    if ($file['size'] > 10 * 1024 * 1024) {
        response(false, null, "Imagem muito grande. Max 10 MB.", 400);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    if (!in_array($mime, $allowed, true)) {
        response(false, null, "Tipo nao suportado. Use JPEG, PNG, WebP ou GIF.", 400);
    }

    $base64 = base64_encode(file_get_contents($file['tmp_name']));

    $systemPrompt = "Voce e um especialista em fotografia e marketing gastronomico. Analise a foto de um produto alimenticio.\n\n";
    $systemPrompt .= "Retorne APENAS JSON valido.\n\n";
    $systemPrompt .= 'Formato:
{
  "produto_identificado": "Nome provavel do produto",
  "descricao_sugerida": "Descricao apetitosa baseada na foto",
  "ingredientes_visiveis": ["ingrediente1", "ingrediente2"],
  "categoria_sugerida": "Categoria do produto",
  "qualidade_foto": {
    "nota": 7,
    "iluminacao": "boa",
    "angulo": "adequado",
    "composicao": "pode melhorar",
    "dicas": ["Use luz natural", "Adicione um fundo neutro"]
  },
  "tags": ["tag1", "tag2"],
  "preco_estimado": 29.90
}';

    $apiKey = $_ENV['CLAUDE_API_KEY'] ?? '';
    if (empty($apiKey)) {
        response(false, null, "API key nao configurada", 500);
    }

    $data = [
        'model' => CLAUDE_MODEL,
        'max_tokens' => MAX_TOKENS,
        'system' => $systemPrompt,
        'messages' => [[
            'role' => 'user',
            'content' => [
                ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $base64]],
                ['type' => 'text', 'text' => 'Analise esta foto de produto alimenticio e retorne as informacoes solicitadas.']
            ]
        ]]
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_TIMEOUT => 120,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        response(false, null, "Erro na API de IA", 500);
    }

    $result = json_decode($response, true);
    $text = $result['content'][0]['text'] ?? '';
    $parsed = parseJsonResponse($text);

    response(true, $parsed ?? ['raw' => $text], "Foto analisada!");
}

/**
 * Apply a generated description to a product in the database
 */
function handleApplyDescription($db, $partnerId, $input) {
    $productId = (int)($input['product_id'] ?? 0);
    $description = trim($input['description'] ?? '');

    if (!$productId || !$description) {
        response(false, null, "product_id e description sao obrigatorios", 400);
    }

    // Verify product belongs to partner
    $stmt = $db->prepare("
        SELECT pb.product_id FROM om_market_products_base pb
        JOIN om_market_products_price pp ON pb.product_id = pp.product_id
        WHERE pb.product_id = ? AND pp.partner_id = ?
    ");
    $stmt->execute([$productId, $partnerId]);
    if (!$stmt->fetch()) {
        response(false, null, "Produto nao encontrado", 404);
    }

    $stmtUpdate = $db->prepare("UPDATE om_market_products_base SET description = ?, date_modified = NOW() WHERE product_id = ?");
    $stmtUpdate->execute([$description, $productId]);

    response(true, ['product_id' => $productId], "Descricao aplicada!");
}

/**
 * Apply bulk enhancements to products
 */
function handleApplyBulk($db, $partnerId, $input) {
    $items = $input['items'] ?? [];
    if (empty($items)) {
        response(false, null, "Envie 'items' com product_id e description", 400);
    }

    $db->beginTransaction();
    try {
        $updated = 0;
        $stmtCheck = $db->prepare("
            SELECT pb.product_id FROM om_market_products_base pb
            JOIN om_market_products_price pp ON pb.product_id = pp.product_id
            WHERE pb.product_id = ? AND pp.partner_id = ?
        ");
        $stmtUpdate = $db->prepare("UPDATE om_market_products_base SET description = ?, date_modified = NOW() WHERE product_id = ?");

        foreach ($items as $item) {
            $pid = (int)($item['id'] ?? $item['product_id'] ?? 0);
            $desc = trim($item['descricao'] ?? $item['description'] ?? '');
            if (!$pid || !$desc) continue;

            $stmtCheck->execute([$pid, $partnerId]);
            if ($stmtCheck->fetch()) {
                $stmtUpdate->execute([$desc, $pid]);
                $updated++;
            }
        }

        $db->commit();
        response(true, ['updated' => $updated], "{$updated} produto(s) atualizado(s)!");
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

// =====================================================================
// Utility Functions
// =====================================================================

function callClaudeAPI(string $systemPrompt, string $userMessage, int $maxTokens = MAX_TOKENS): array {
    $apiKey = $_ENV['CLAUDE_API_KEY'] ?? '';
    if (empty($apiKey)) {
        return ['success' => false, 'error' => 'CLAUDE_API_KEY not configured'];
    }

    $data = [
        'model' => CLAUDE_MODEL,
        'max_tokens' => $maxTokens,
        'system' => $systemPrompt,
        'messages' => [['role' => 'user', 'content' => $userMessage]],
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) return ['success' => false, 'error' => 'cURL: ' . $curlError];
    if ($httpCode !== 200) {
        $err = json_decode($response, true);
        return ['success' => false, 'error' => $err['error']['message'] ?? "HTTP {$httpCode}"];
    }

    $result = json_decode($response, true);
    if (!isset($result['content'][0]['text'])) {
        return ['success' => false, 'error' => 'Invalid response'];
    }

    return [
        'success' => true,
        'response' => $result['content'][0]['text'],
        'tokens_used' => ($result['usage']['input_tokens'] ?? 0) + ($result['usage']['output_tokens'] ?? 0),
        'model' => $result['model'] ?? CLAUDE_MODEL,
    ];
}

function parseJsonResponse(string $raw): ?array {
    $text = trim($raw);
    if (preg_match('/^```(?:json)?\s*\n?(.*?)\n?```$/s', $text, $m)) {
        $text = trim($m[1]);
    }
    $parsed = json_decode($text, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) return $parsed;
    if (preg_match('/\{[\s\S]*\}/s', $text, $m)) {
        $parsed = json_decode($m[0], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) return $parsed;
    }
    return null;
}
