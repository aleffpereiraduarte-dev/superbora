<?php
/**
 * GET /api/mercado/produtos/buscar.php?q=leite&partner_id=1
 * Busca de produtos do mercado
 * Otimizado com cache (TTL: 5 min) e prepared statements
 *
 * IMPORTANTE: Retorna preco_venda para o cliente (preco_custo + markup)
 * O cliente NUNCA ve o preco_custo, apenas o preco_venda
 *
 * AI SEMANTIC SEARCH (Apr 2026):
 * When query looks like natural language (3+ words or descriptive terms),
 * uses Claude AI to expand search terms for broader matching.
 * Example: "acai com leite ninho e granola" -> searches for acai, leite ninho, granola, etc.
 * Falls back to normal ILIKE search for simple 1-2 word queries.
 * AI expansions cached for 1 hour.
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 2) . "/cache/CacheHelper.php";
require_once __DIR__ . "/../../../includes/classes/OmPricing.php";
require_once __DIR__ . "/../helpers/claude-client.php";

try {
    $q = trim($_GET["q"] ?? "");
    $partner_id = isset($_GET["partner_id"]) ? (int)$_GET["partner_id"] : null;

    if (strlen($q) < 2 || strlen($q) > 200) response(false, null, "Busca deve ter entre 2 e 200 caracteres", 400);

    $cacheKey = "busca_produtos_v3_" . md5($q . "_" . ($partner_id ?? "all"));

    $data = CacheHelper::remember($cacheKey, 300, function() use ($q, $partner_id) {
        $db = getDB();

        // Determine if query needs AI semantic expansion
        $searchTerms = getSearchTerms($q);

        // Build WHERE clause based on search terms
        $params = [];
        $conditions = [];

        foreach ($searchTerms as $term) {
            $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $term);
            $like = "%{$escaped}%";
            $conditions[] = "(p.name ILIKE ? OR p.description ILIKE ? OR p.barcode ILIKE ? OR p.category ILIKE ?)";
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $searchWhere = "(" . implode(" OR ", $conditions) . ")";

        $where = "p.status::text = '1' AND (p.image IS NOT NULL AND TRIM(p.image) != '' AND p.image NOT LIKE '%.svg' AND LOWER(p.image) NOT LIKE '%placeholder%' AND LOWER(p.image) NOT LIKE '%no-image%' AND LOWER(p.image) NOT LIKE '%noimage%') AND " . $searchWhere;

        if ($partner_id) {
            $where .= " AND p.partner_id = ?";
            $params[] = $partner_id;
        }

        $stmt = $db->prepare("SELECT p.*, c.name as categoria FROM om_market_products p
                              LEFT JOIN om_market_categories c ON p.category_id = c.category_id
                              WHERE $where
                              ORDER BY
                                CASE WHEN LOWER(p.name) LIKE LOWER(?) THEN 0 ELSE 1 END ASC,
                                p.name ASC
                              LIMIT 50");

        // Add the original query for sorting relevance (exact matches first)
        $originalLike = "%" . str_replace(['%', '_'], ['\\%', '\\_'], $q) . "%";
        $params[] = $originalLike;

        $stmt->execute($params);
        $produtos = $stmt->fetchAll();

        // Processar produtos para retornar preco_venda ao cliente
        $produtos_processados = array_map(function($p) {
            $preco_venda = null;

            if (!empty($p['preco_venda']) && $p['preco_venda'] > 0) {
                $preco_venda = floatval($p['preco_venda']);
            } elseif (!empty($p['preco_custo']) && $p['preco_custo'] > 0) {
                $preco_venda = OmPricing::calcularPrecoVenda(
                    floatval($p['preco_custo']),
                    $p['partner_id'] ?? null,
                    $p['category_id'] ?? null
                );
            } else {
                $preco_venda = floatval($p['price'] ?? 0);
            }

            return [
                'id' => $p['id'] ?? $p['product_id'],
                'product_id' => $p['product_id'] ?? $p['id'],
                'partner_id' => $p['partner_id'],
                'name' => $p['name'],
                'description' => $p['description'],
                'price' => $preco_venda,
                'preco' => $preco_venda,
                'preco_original' => floatval($p['original_price'] ?? $preco_venda),
                'image' => $p['image'],
                'categoria' => $p['categoria'],
                'category_id' => $p['category_id'],
                'unit' => $p['unit'] ?? 'un',
                'quantity' => $p['quantity'] ?? 999,
                'barcode' => $p['barcode'] ?? null,
                'status' => $p['status']
            ];
        }, $produtos);

        $result = [
            "termo" => htmlspecialchars($q, ENT_QUOTES, 'UTF-8'),
            "total" => count($produtos_processados),
            "produtos" => $produtos_processados
        ];

        // Include AI search metadata if semantic expansion was used
        if (count($searchTerms) > 1) {
            $result["semantic_search"] = true;
            $result["expanded_terms"] = $searchTerms;
        }

        return $result;
    });

    response(true, $data);

} catch (Exception $e) {
    error_log("[API Mercado Produtos Buscar] Erro: " . $e->getMessage());
    response(false, null, "Erro na busca. Tente novamente.", 500);
}

/**
 * Determine search terms: use AI expansion for natural language queries,
 * fall back to single-term search for simple queries.
 *
 * @param string $q Original query
 * @return array List of search terms to OR together
 */
function getSearchTerms(string $q): array {
    $words = preg_split('/\s+/', trim($q));
    $wordCount = count($words);

    // Simple queries (1-2 words without descriptive modifiers): use as-is
    if ($wordCount <= 2 && !isDescriptiveQuery($q)) {
        return [$q];
    }

    // Check AI expansion cache first (1 hour TTL)
    $aiCacheKey = "ai_search_expand_v1_" . md5(mb_strtolower($q));
    $cached = CacheHelper::get($aiCacheKey);
    if ($cached !== null && is_array($cached)) {
        return $cached;
    }

    // Try AI expansion
    try {
        $expanded = expandWithAI($q);
        if (!empty($expanded)) {
            // Always include the original query as a term
            if (!in_array($q, $expanded)) {
                array_unshift($expanded, $q);
            }
            // Cache for 1 hour
            CacheHelper::set($aiCacheKey, $expanded, 3600);
            return $expanded;
        }
    } catch (Exception $e) {
        error_log("[AI Search] Expansion failed: " . $e->getMessage());
    }

    // Fallback: split multi-word queries into individual terms + full query
    $terms = [$q];
    if ($wordCount >= 3) {
        // Also search individual significant words (skip prepositions/articles)
        $stopwords = ['com', 'de', 'do', 'da', 'dos', 'das', 'e', 'ou', 'em', 'no', 'na', 'nos', 'nas',
                      'um', 'uma', 'uns', 'umas', 'o', 'a', 'os', 'as', 'para', 'por', 'sem', 'ao', 'pra'];
        foreach ($words as $word) {
            $w = mb_strtolower(trim($word));
            if (mb_strlen($w) >= 3 && !in_array($w, $stopwords) && !in_array($w, $terms)) {
                $terms[] = $w;
            }
        }
    }

    return $terms;
}

/**
 * Check if a short query contains descriptive/natural language patterns
 */
function isDescriptiveQuery(string $q): bool {
    $lower = mb_strtolower($q);
    // Prepositions, conjunctions, and descriptive modifiers signal natural language
    $descriptivePatterns = [
        '/\b(com|sem|tipo|sabor|estilo|para|diet|light|zero|integral|natural|vegano|organico|fit)\b/i',
        '/\b(quero|preciso|busco|procuro|gostaria|tem)\b/i',
    ];
    foreach ($descriptivePatterns as $pattern) {
        if (preg_match($pattern, $lower)) {
            return true;
        }
    }
    return false;
}

/**
 * Use Claude AI to expand a natural language search query into search terms.
 * Uses claude-haiku for speed (low latency).
 *
 * @param string $q Natural language query
 * @return array Expanded search terms
 */
function expandWithAI(string $q): array {
    $claude = new ClaudeClient('claude-sonnet-4-20250514', 15, 0); // 15s timeout, no retries for speed

    $systemPrompt = <<<'PROMPT'
Voce e um assistente de busca para um app de delivery de supermercado brasileiro (SuperBora).
O usuario digitou uma busca em linguagem natural. Sua tarefa e extrair termos de busca para encontrar os produtos certos no banco de dados.

Responda SOMENTE com JSON valido, sem explicacao. Formato:
{
  "core_product": "produto principal",
  "modifiers": ["modificador1", "modificador2"],
  "search_terms": ["termo1", "termo2", "termo3"],
  "category_hint": "categoria_provavel"
}

Regras:
- "search_terms" deve conter entre 2 e 6 termos de busca curtos
- Inclua o produto principal e variantes/sinonimos comuns
- Inclua ingredientes/complementos mencionados como termos separados
- Pense em como os produtos estariam cadastrados no banco (nomes de prateleira)
- "category_hint" e opcional, pode ser: mercado, restaurante, farmacia, bebidas, padaria, acai, etc.
- NAO inclua artigos, preposicoes ou palavras genéricas demais

Exemplos:
- "acai com leite ninho e granola" -> {"core_product":"acai","modifiers":["leite ninho","granola"],"search_terms":["acai","leite ninho","granola","acai bowl","acai na tigela","leite condensado"],"category_hint":"acai"}
- "cerveja gelada para churrasco" -> {"core_product":"cerveja","modifiers":["gelada","churrasco"],"search_terms":["cerveja","cerveja pilsen","cerveja lata","skol","brahma","antartica"],"category_hint":"bebidas"}
- "remedio para dor de cabeca" -> {"core_product":"analgesico","modifiers":["dor de cabeca"],"search_terms":["dipirona","dorflex","paracetamol","ibuprofeno","tylenol","neosaldina"],"category_hint":"farmacia"}
PROMPT;

    $messages = [
        ['role' => 'user', 'content' => $q]
    ];

    $result = $claude->send($systemPrompt, $messages, 512);

    if (!$result['success']) {
        error_log("[AI Search] Claude error: " . ($result['error'] ?? 'unknown'));
        return [];
    }

    $parsed = ClaudeClient::parseJson($result['text']);
    if (!$parsed || empty($parsed['search_terms'])) {
        error_log("[AI Search] Failed to parse response: " . substr($result['text'] ?? '', 0, 200));
        return [];
    }

    // Sanitize and limit terms
    $terms = array_slice($parsed['search_terms'], 0, 6);
    $terms = array_map(function($t) {
        return trim(preg_replace('/[^\p{L}\p{N}\s\-]/u', '', $t));
    }, $terms);
    $terms = array_filter($terms, function($t) {
        return mb_strlen($t) >= 2;
    });

    return array_values($terms);
}
