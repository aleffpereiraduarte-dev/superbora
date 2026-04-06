<?php
/**
 * POST /api/mercado/partner/ai-descriptions.php
 * Generates appealing Portuguese descriptions for products using Claude AI.
 *
 * Auth: Bearer token (partner type)
 *
 * Body (JSON):
 *   product_ids  — array of product_ids (max 20)
 *   style        — description style: "appetizing"|"professional"|"casual"|"gourmet"|"fun" (default: "appetizing")
 *   max_length   — max chars per description (default: 200)
 *   auto_select  — if true, auto-selects products missing descriptions (ignores product_ids)
 *   limit        — when auto_select=true, how many to generate (default: 20, max: 20)
 */
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../helpers/claude-client.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

const MAX_BATCH      = 20;
const MIN_DESC_LEN   = 20; // descriptions shorter than this are considered "missing"
const CLAUDE_MODEL   = 'claude-sonnet-4-20250514';

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    // --- Auth ---
    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Token ausente", 401);

    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== OmAuth::USER_TYPE_PARTNER) {
        response(false, null, "Nao autorizado", 401);
    }
    $partnerId = (int)$payload['uid'];

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        response(false, null, "Metodo nao permitido. Use POST.", 405);
    }

    $input     = getInput();
    $style     = in_array($input['style'] ?? '', ['appetizing', 'professional', 'casual', 'gourmet', 'fun'])
                    ? $input['style'] : 'appetizing';
    $maxLength = min(500, max(50, (int)($input['max_length'] ?? 200)));
    $autoSelect = (bool)($input['auto_select'] ?? false);
    $limit      = min(MAX_BATCH, max(1, (int)($input['limit'] ?? MAX_BATCH)));

    // --- Determine which model (catalog or simple) ---
    $stmtCheck = $db->prepare("SELECT COUNT(*) FROM om_market_products_price WHERE partner_id = ?");
    $stmtCheck->execute([$partnerId]);
    $useCatalog = (int)$stmtCheck->fetchColumn() > 0;

    $products = [];

    if ($autoSelect) {
        // Auto-select products with missing/short descriptions
        if ($useCatalog) {
            $stmt = $db->prepare("
                SELECT pp.product_id, pb.name, pb.description, pb.brand, pb.unit, pp.price,
                       cat.name as category_name
                FROM om_market_products_price pp
                INNER JOIN om_market_products_base pb ON pb.product_id = pp.product_id
                LEFT JOIN om_market_categories cat ON cat.category_id = pb.category_id
                WHERE pp.partner_id = ? AND pp.status = 1
                  AND (pb.description IS NULL OR LENGTH(TRIM(pb.description)) < ?)
                ORDER BY pb.name ASC
                LIMIT ?
            ");
            $stmt->execute([$partnerId, MIN_DESC_LEN, $limit]);
        } else {
            $stmt = $db->prepare("
                SELECT p.product_id, p.name, p.description, p.brand, p.unit, p.price,
                       p.category as category_name
                FROM om_market_products p
                WHERE p.partner_id = ? AND COALESCE(p.status, 1) = 1
                  AND (p.description IS NULL OR LENGTH(TRIM(COALESCE(p.description, ''))) < ?)
                ORDER BY p.name ASC
                LIMIT ?
            ");
            $stmt->execute([$partnerId, MIN_DESC_LEN, $limit]);
        }
        $products = $stmt->fetchAll();
    } else {
        // Explicit product_ids
        $productIds = $input['product_ids'] ?? [];
        if (!is_array($productIds) || empty($productIds)) {
            response(false, null, "Envie 'product_ids' (array) ou use 'auto_select': true.", 400);
        }

        $productIds = array_slice(array_map('intval', $productIds), 0, MAX_BATCH);
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));

        if ($useCatalog) {
            $stmt = $db->prepare("
                SELECT pp.product_id, pb.name, pb.description, pb.brand, pb.unit, pp.price,
                       cat.name as category_name
                FROM om_market_products_price pp
                INNER JOIN om_market_products_base pb ON pb.product_id = pp.product_id
                LEFT JOIN om_market_categories cat ON cat.category_id = pb.category_id
                WHERE pp.partner_id = ? AND pp.product_id IN ({$placeholders})
                ORDER BY pb.name ASC
            ");
            $stmt->execute(array_merge([$partnerId], $productIds));
        } else {
            $stmt = $db->prepare("
                SELECT p.product_id, p.name, p.description, p.brand, p.unit, p.price,
                       p.category as category_name
                FROM om_market_products p
                WHERE p.partner_id = ? AND p.product_id IN ({$placeholders})
                ORDER BY p.name ASC
            ");
            $stmt->execute(array_merge([$partnerId], $productIds));
        }
        $products = $stmt->fetchAll();
    }

    if (empty($products)) {
        response(true, [
            'descriptions' => [],
            'total' => 0,
            'message' => $autoSelect
                ? 'Todos os produtos ja possuem descricoes adequadas.'
                : 'Nenhum produto encontrado com os IDs informados.'
        ], $autoSelect ? 'Nenhum produto precisa de descricao.' : 'Produtos nao encontrados.');
    }

    // --- Build Claude prompt ---
    $styleLabels = [
        'appetizing'   => 'apetitosa e convidativa, que faz o cliente sentir vontade de comprar',
        'professional' => 'profissional e informativa, destacando qualidade e caracteristicas',
        'casual'       => 'casual e descontraida, como se estivesse indicando para um amigo',
        'gourmet'      => 'sofisticada e gourmet, destacando sabores e experiencia sensorial',
        'fun'          => 'divertida e criativa, com personalidade e humor leve',
    ];

    $styleDesc = $styleLabels[$style] ?? $styleLabels['appetizing'];

    $systemPrompt  = "Voce e um copywriter especialista em descricoes de produtos para delivery e supermercado no Brasil. ";
    $systemPrompt .= "Gere descricoes em portugues brasileiro que sejam {$styleDesc}.\n\n";
    $systemPrompt .= "## Regras:\n";
    $systemPrompt .= "1. Cada descricao deve ter no maximo {$maxLength} caracteres.\n";
    $systemPrompt .= "2. Use linguagem natural e atraente.\n";
    $systemPrompt .= "3. Destaque beneficios e diferenciais do produto.\n";
    $systemPrompt .= "4. NAO use emojis.\n";
    $systemPrompt .= "5. NAO invente ingredientes ou informacoes que nao existem.\n";
    $systemPrompt .= "6. Se o produto ja tem descricao curta, melhore-a sem mudar o significado.\n";
    $systemPrompt .= "7. Considere o nome, marca, categoria e preco ao escrever.\n\n";
    $systemPrompt .= "## Formato de resposta (JSON array):\n";
    $systemPrompt .= "[\n";
    $systemPrompt .= "  {\n";
    $systemPrompt .= "    \"product_id\": 123,\n";
    $systemPrompt .= "    \"descricao\": \"Descricao gerada aqui\",\n";
    $systemPrompt .= "    \"keywords\": [\"palavra1\", \"palavra2\"]\n";
    $systemPrompt .= "  }\n";
    $systemPrompt .= "]\n\n";
    $systemPrompt .= "Retorne APENAS o JSON, sem texto antes ou depois.";

    // Build product list for Claude
    $productList = [];
    foreach ($products as $p) {
        $item = "- ID {$p['product_id']}: \"{$p['name']}\"";
        if (!empty($p['brand']))         $item .= " (marca: {$p['brand']})";
        if (!empty($p['category_name'])) $item .= " [cat: {$p['category_name']}]";
        if (!empty($p['price']))         $item .= " R$ " . number_format((float)$p['price'], 2, ',', '.');
        if (!empty($p['unit']))          $item .= " ({$p['unit']})";
        if (!empty($p['description']) && strlen(trim($p['description'])) > 0) {
            $item .= "\n  Descricao atual: \"{$p['description']}\"";
        }
        $productList[] = $item;
    }

    $userMessage = "Gere descricoes para estes " . count($products) . " produtos:\n\n" . implode("\n", $productList);

    // --- Call Claude ---
    $claude = new ClaudeClient(CLAUDE_MODEL, 120);
    $messages = [['role' => 'user', 'content' => $userMessage]];
    $maxTokens = min(8192, count($products) * 400); // ~400 tokens per product
    $claudeResult = $claude->send($systemPrompt, $messages, $maxTokens);

    if (!$claudeResult['success']) {
        error_log("[ai-descriptions] Claude error for partner {$partnerId}: " . ($claudeResult['error'] ?? 'unknown'));
        response(false, null, "Erro ao gerar descricoes. Tente novamente.", 500);
    }

    // --- Parse response ---
    $parsed = ClaudeClient::parseJson($claudeResult['text']);

    if ($parsed === null) {
        error_log("[ai-descriptions] Parse failed for partner {$partnerId}. Raw: " . substr($claudeResult['text'], 0, 500));
        response(false, ['raw_response' => $claudeResult['text']], "A IA retornou uma resposta, mas nao foi possivel estrutura-la.", 422);
    }

    // Handle if Claude returned {descriptions: [...]} instead of [...]
    if (isset($parsed['descriptions']) && is_array($parsed['descriptions'])) {
        $parsed = $parsed['descriptions'];
    }

    // Validate and normalize
    $descriptions = [];
    $productMap = [];
    foreach ($products as $p) {
        $productMap[(int)$p['product_id']] = $p;
    }

    foreach ($parsed as $item) {
        $pid = (int)($item['product_id'] ?? 0);
        if ($pid <= 0 || !isset($productMap[$pid])) continue;

        $desc = trim($item['descricao'] ?? $item['description'] ?? '');
        if (empty($desc)) continue;

        // Enforce max length
        if (mb_strlen($desc) > $maxLength) {
            $desc = mb_substr($desc, 0, $maxLength - 3) . '...';
        }

        $keywords = is_array($item['keywords'] ?? null) ? $item['keywords'] : [];

        $descriptions[] = [
            'product_id'        => $pid,
            'product_name'      => $productMap[$pid]['name'],
            'current_description' => trim($productMap[$pid]['description'] ?? ''),
            'suggested_description' => $desc,
            'keywords'          => $keywords,
        ];
    }

    // --- Log session ---
    try {
        $stmtLog = $db->prepare("
            INSERT INTO om_ai_menu_sessions (partner_id, session_type, input_data, result_data, status, tokens_used, page_count)
            VALUES (?, 'descriptions', ?, ?, 'completed', ?, 0)
        ");
        $stmtLog->execute([
            $partnerId,
            json_encode(['style' => $style, 'product_count' => count($products), 'auto_select' => $autoSelect]),
            json_encode(['generated' => count($descriptions)]),
            $claudeResult['total_tokens'] ?? 0,
        ]);
    } catch (Exception $e) {
        error_log("[ai-descriptions] Log error: " . $e->getMessage());
    }

    response(true, [
        'descriptions'  => $descriptions,
        'total'         => count($descriptions),
        'style'         => $style,
        'tokens_used'   => $claudeResult['total_tokens'] ?? 0,
        'model'         => $claudeResult['model'] ?? CLAUDE_MODEL,
    ], count($descriptions) . " descricoes geradas com sucesso!");

} catch (Exception $e) {
    error_log("[ai-descriptions] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
