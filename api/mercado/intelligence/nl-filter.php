<?php
/**
 * POST /api/mercado/intelligence/nl-filter.php
 * Body: { "query": "acai sem lactose com granola e banana" }
 * Returns structured filter intent the search engine can apply:
 *   {
 *     "main_term": "acai",
 *     "include": ["granola","banana"],
 *     "exclude": ["lactose","leite"],
 *     "dietary": ["sem-lactose"],
 *     "max_price": null,
 *     "category_hint": "doces"
 *   }
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, 'Metodo nao permitido', 405);
}

try {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $query = trim($input['query'] ?? '');
    if ($query === '') {
        response(false, null, 'query obrigatoria', 400);
    }
    if (mb_strlen($query) > 200) {
        response(false, null, 'query muito longa', 400);
    }

    $cacheKey = 'nlf_' . md5(strtolower($query));
    $cacheFile = sys_get_temp_dir() . '/sb_' . $cacheKey . '.json';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 86400) {
        response(true, json_decode(file_get_contents($cacheFile), true));
    }

    $userPrompt = "Extraia a intencao de busca da seguinte query em portugues do Brasil. " .
                  "Responda APENAS JSON:\n\n" .
                  "Query: \"{$query}\"\n\n" .
                  '{"main_term":"termo principal","include":["palavras"],"exclude":["palavras"],' .
                  '"dietary":["sem-lactose","vegano",...],"max_price":null,"category_hint":"restaurante|mercado|farmacia|bebidas|doces|outros"}';

    $reply = ClaudeClient::text(
        $userPrompt,
        'Voce eh um parser de intent de busca. Sempre responda JSON valido.',
        300
    );
    $parsed = ClaudeClient::parseJson($reply ?: '') ?: [];

    $out = [
        'main_term' => $parsed['main_term'] ?? $query,
        'include' => is_array($parsed['include'] ?? null) ? array_slice($parsed['include'], 0, 6) : [],
        'exclude' => is_array($parsed['exclude'] ?? null) ? array_slice($parsed['exclude'], 0, 6) : [],
        'dietary' => is_array($parsed['dietary'] ?? null) ? array_slice($parsed['dietary'], 0, 4) : [],
        'max_price' => isset($parsed['max_price']) && is_numeric($parsed['max_price']) ? (float)$parsed['max_price'] : null,
        'category_hint' => $parsed['category_hint'] ?? null,
        'original_query' => $query,
    ];
    @file_put_contents($cacheFile, json_encode($out));
    response(true, $out);
} catch (Exception $e) {
    error_log('[nl-filter] ' . $e->getMessage());
    response(false, null, 'erro', 500);
}
