<?php
/**
 * GET /api/mercado/intelligence/search-suggest.php?q=aca
 * AI-powered search suggestions with semantic understanding.
 *
 * Returns up to 8 suggestions combining:
 *   1. AI-generated semantic suggestions (for 3+ char queries)
 *   2. Product name matches from database
 *   3. Store name matches
 *   4. Popular/trending search terms
 *   5. Category matches
 *
 * Cache: 5 min for AI suggestions, 60s for DB lookups
 *
 * Response format:
 * {
 *   "suggestions": [
 *     { "text": "Acai na Tigela", "type": "ai", "icon": "sparkles", "category_hint": "acai" },
 *     { "text": "Acai 500ml", "type": "product", "id": 123, "image": "...", "price": 15.90 },
 *     { "text": "Acai Mania", "type": "store", "id": 5, "logo": "...", "rating": 4.8 },
 *     ...
 *   ]
 * }
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 2) . "/cache/CacheHelper.php";
require_once __DIR__ . "/../helpers/claude-client.php";

header('Cache-Control: public, max-age=30');

try {
    $q = trim($_GET["q"] ?? "");
    $limit = min(12, max(1, (int)($_GET["limit"] ?? 8)));

    if (strlen($q) < 2 || strlen($q) > 100) {
        response(true, ["suggestions" => []]);
    }

    // Full cache key for the complete response
    $cacheKey = "ai_search_suggest_v1_" . md5(mb_strtolower($q) . "_" . $limit);

    $data = CacheHelper::remember($cacheKey, 60, function() use ($q, $limit) {
        $db = getDB();
        $suggestions = [];
        $seen = []; // Track seen texts to avoid duplicates

        $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $q);
        $likeTerm = "%" . $escaped . "%";
        $likeStart = $escaped . "%";
        $lowerQ = mb_strtolower($q);

        // 1. AI-generated semantic suggestions (for longer/descriptive queries)
        $aiSuggestions = getAISuggestions($q);
        foreach ($aiSuggestions as $aiSug) {
            $key = mb_strtolower($aiSug['text'] ?? '');
            if ($key && !isset($seen[$key]) && count($suggestions) < $limit) {
                $seen[$key] = true;
                $suggestions[] = $aiSug;
            }
        }

        // 2. Product name matches (starts-with first, then contains)
        $stmt = $db->prepare("
            SELECT DISTINCT ON (LOWER(p.name))
                   p.product_id, p.name, p.price, p.special_price, p.image,
                   mp.partner_id, mp.name as partner_name, mp.logo as partner_logo
            FROM om_market_products p
            INNER JOIN om_market_partners mp ON p.partner_id = mp.partner_id AND mp.status::text = '1'
            WHERE p.status::text = '1'
              AND (p.image IS NOT NULL AND TRIM(p.image) != '' AND p.image NOT LIKE '%.svg')
              AND (p.name ILIKE ? OR p.name ILIKE ?)
            ORDER BY LOWER(p.name),
                     CASE WHEN p.name ILIKE ? THEN 0 ELSE 1 END ASC,
                     mp.rating DESC NULLS LAST
            LIMIT 6
        ");
        $stmt->execute([$likeStart, $likeTerm, $likeStart]);
        $products = $stmt->fetchAll();

        foreach ($products as $p) {
            $key = mb_strtolower($p['name']);
            if (isset($seen[$key]) || count($suggestions) >= $limit) continue;
            $seen[$key] = true;

            $preco = (float)$p['price'];
            $promoPreco = $p['special_price'] ? (float)$p['special_price'] : null;
            $emPromocao = $promoPreco && $promoPreco > 0 && $promoPreco < $preco;

            $suggestions[] = [
                'text' => $p['name'],
                'type' => 'product',
                'id' => (int)$p['product_id'],
                'nome' => $p['name'],
                'termo' => $p['name'],
                'imagem' => $p['image'],
                'image' => $p['image'],
                'preco' => $emPromocao ? $promoPreco : $preco,
                'price' => $emPromocao ? $promoPreco : $preco,
                'parceiro_nome' => $p['partner_name'],
                'partner_name' => $p['partner_name'],
                'parceiro_logo' => $p['partner_logo'],
                'partner_logo' => $p['partner_logo'],
                'partner_id' => (int)$p['partner_id'],
            ];
        }

        // 3. Store name matches
        $stmt = $db->prepare("
            SELECT mp.partner_id, mp.name, mp.logo, mp.rating, mp.categoria,
                   mp.delivery_fee, mp.delivery_time_min, mp.is_open
            FROM om_market_partners mp
            WHERE mp.status::text = '1'
              AND (mp.name ILIKE ? OR mp.trade_name ILIKE ?)
            ORDER BY
                CASE WHEN mp.name ILIKE ? THEN 0 ELSE 1 END ASC,
                mp.rating DESC NULLS LAST
            LIMIT 3
        ");
        $stmt->execute([$likeTerm, $likeTerm, $likeStart]);
        $stores = $stmt->fetchAll();

        foreach ($stores as $s) {
            $key = mb_strtolower($s['name']);
            if (isset($seen[$key]) || count($suggestions) >= $limit) continue;
            $seen[$key] = true;

            $suggestions[] = [
                'text' => $s['name'],
                'type' => 'store',
                'id' => (int)$s['partner_id'],
                'partner_id' => (int)$s['partner_id'],
                'nome' => $s['name'],
                'name' => $s['name'],
                'termo' => $s['name'],
                'logo' => $s['logo'],
                'avaliacao' => (float)($s['rating'] ?? 5.0),
                'rating' => (float)($s['rating'] ?? 5.0),
                'categoria' => $s['categoria'] ?? 'mercado',
                'aberto' => (int)($s['is_open'] ?? 0) === 1,
            ];
        }

        // 4. Popular search terms matching query
        try {
            $stmt = $db->prepare("
                SELECT term, search_count
                FROM om_search_logs
                WHERE term ILIKE ?
                  AND search_count >= 2
                ORDER BY
                    CASE WHEN term ILIKE ? THEN 0 ELSE 1 END ASC,
                    search_count DESC
                LIMIT 4
            ");
            $stmt->execute([$likeTerm, $likeStart]);
            $terms = $stmt->fetchAll();

            foreach ($terms as $t) {
                $key = mb_strtolower($t['term']);
                if (isset($seen[$key]) || count($suggestions) >= $limit) continue;
                $seen[$key] = true;
                $suggestions[] = [
                    'text' => $t['term'],
                    'type' => 'trending',
                    'termo' => $t['term'],
                    'nome' => $t['term'],
                    'name' => $t['term'],
                    'icon' => 'trending',
                    'buscas' => (int)$t['search_count'],
                ];
            }
        } catch (Exception $e) {
            // om_search_logs may not exist — skip
        }

        // 5. Category matches
        $categories = [
            'mercado' => 'Mercado',
            'restaurante' => 'Restaurante',
            'farmacia' => 'Farmacia',
            'bebidas' => 'Bebidas',
            'pet' => 'Pet Shop',
            'padaria' => 'Padaria',
            'acai' => 'Acai',
            'pizza' => 'Pizza',
            'hamburger' => 'Hamburger',
            'japonesa' => 'Japonesa',
            'brasileira' => 'Brasileira',
            'saudavel' => 'Saudavel',
            'doces' => 'Doces',
            'sorvete' => 'Sorvete',
            'conveniencia' => 'Conveniencia',
        ];

        foreach ($categories as $value => $label) {
            if (count($suggestions) >= $limit) break;
            if (mb_stripos($label, $q) !== false || mb_stripos($value, $q) !== false) {
                $key = mb_strtolower($label);
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $suggestions[] = [
                    'text' => $label,
                    'type' => 'category',
                    'value' => $value,
                    'termo' => $label,
                    'nome' => $label,
                    'name' => $label,
                    'icon' => 'category',
                ];
            }
        }

        return ["suggestions" => array_slice($suggestions, 0, $limit)];
    });

    response(true, $data);

} catch (Exception $e) {
    error_log("[API Search Suggest] Erro: " . $e->getMessage());
    response(false, null, "Erro ao buscar sugestoes", 500);
}

/**
 * Get AI-powered search suggestions for a query.
 * Uses Claude to understand intent and suggest relevant searches.
 * Cached for 5 minutes.
 *
 * @param string $q User query (2+ chars)
 * @return array List of suggestion items
 */
function getAISuggestions(string $q): array {
    $words = preg_split('/\s+/', trim($q));
    $wordCount = count($words);

    // Only use AI for queries that benefit from semantic understanding
    // (3+ words, or 2 words that look descriptive)
    if ($wordCount < 2) {
        return [];
    }

    // Check descriptive 2-word queries
    if ($wordCount === 2) {
        $lower = mb_strtolower($q);
        $hasDescriptor = preg_match('/\b(com|sem|tipo|sabor|diet|light|zero|integral|natural|vegano|organico|fit|grande|pequeno|barato|gelado|gelada|quente|fresco)\b/i', $lower);
        if (!$hasDescriptor) {
            return [];
        }
    }

    // Cache AI suggestions for 5 minutes
    $aiCacheKey = "ai_suggest_v1_" . md5(mb_strtolower($q));
    $cached = CacheHelper::get($aiCacheKey);
    if ($cached !== null && is_array($cached)) {
        return $cached;
    }

    try {
        $claude = new ClaudeClient('claude-sonnet-4-20250514', 10, 0); // 10s timeout, no retries

        $systemPrompt = <<<'PROMPT'
Voce e o assistente de busca do SuperBora, um app de delivery de supermercado brasileiro.
O usuario esta digitando uma busca. Sugira de 2 a 4 completions relevantes baseadas no que ele pode estar procurando.

Responda SOMENTE com JSON valido. Formato:
{
  "suggestions": [
    {"text": "Sugestao 1", "category_hint": "categoria"},
    {"text": "Sugestao 2", "category_hint": "categoria"}
  ]
}

Regras:
- Cada "text" deve ser um termo de busca curto e especifico (max 40 chars)
- Pense em produtos reais de supermercado/delivery brasileiro
- "category_hint" pode ser: mercado, restaurante, farmacia, bebidas, padaria, acai, pet, etc.
- Seja criativo mas realista — sugira produtos que existiriam em um delivery
- NAO repita o que o usuario ja digitou — complete ou expanda a intencao
- Responda em portugues brasileiro
PROMPT;

        $messages = [
            ['role' => 'user', 'content' => "Busca do usuario: \"{$q}\""]
        ];

        $result = $claude->send($systemPrompt, $messages, 256);

        if (!$result['success']) {
            return [];
        }

        $parsed = ClaudeClient::parseJson($result['text']);
        if (!$parsed || empty($parsed['suggestions'])) {
            return [];
        }

        $suggestions = [];
        foreach (array_slice($parsed['suggestions'], 0, 4) as $sug) {
            $text = trim($sug['text'] ?? '');
            if (mb_strlen($text) < 2 || mb_strlen($text) > 60) continue;

            $suggestions[] = [
                'text' => $text,
                'type' => 'ai',
                'termo' => $text,
                'nome' => $text,
                'name' => $text,
                'icon' => 'sparkles',
                'category_hint' => $sug['category_hint'] ?? null,
            ];
        }

        // Cache for 5 minutes
        CacheHelper::set($aiCacheKey, $suggestions, 300);

        return $suggestions;

    } catch (Exception $e) {
        error_log("[AI Suggest] Error: " . $e->getMessage());
        return [];
    }
}
