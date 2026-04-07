<?php
/**
 * POST /api/mercado/intelligence/cart-summary.php
 * Body: { "items": [{"name":"...","qty":1,"price":10}, ...] }
 * Returns: { "summary": "frase amigavel", "tags": ["saudavel","doce"], "missing": ["bebida"] }
 *
 * Used on the cart screen above the total to give the customer context about
 * what they're buying ("Voce esta levando 3 itens saudaveis e 2 doces").
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, 'Metodo nao permitido', 405);
}

try {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $items = $input['items'] ?? [];
    if (!is_array($items) || empty($items)) {
        response(false, null, 'items obrigatorio', 400);
    }

    $itemsList = [];
    foreach (array_slice($items, 0, 30) as $i) {
        $name = trim($i['name'] ?? '');
        $qty = (int)($i['qty'] ?? 1);
        if ($name === '') continue;
        $itemsList[] = "{$qty}x {$name}";
    }
    if (empty($itemsList)) {
        response(true, ['summary' => '', 'tags' => [], 'missing' => []]);
    }

    $cacheKey = 'cs_' . md5(implode('|', $itemsList));
    $cacheFile = sys_get_temp_dir() . '/sb_' . $cacheKey . '.json';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
        response(true, json_decode(file_get_contents($cacheFile), true));
    }

    $userPrompt = "Carrinho do cliente:\n" . implode("\n", $itemsList) . "\n\n" .
                  "Gere uma frase amigavel (max 100 chars) descrevendo a vibe do carrinho. " .
                  "Tambem extraia 2-4 tags e o que esta faltando (max 2). Responda APENAS JSON:\n" .
                  '{"summary":"frase","tags":["tag1","tag2"],"missing":["sugestao1"]}';

    $reply = ClaudeClient::text(
        $userPrompt,
        'Voce eh um curador alimentar. Sempre responda JSON valido.',
        300
    );
    $parsed = ClaudeClient::parseJson($reply ?: '') ?: [];
    $out = [
        'summary' => $parsed['summary'] ?? '',
        'tags' => is_array($parsed['tags'] ?? null) ? array_slice($parsed['tags'], 0, 4) : [],
        'missing' => is_array($parsed['missing'] ?? null) ? array_slice($parsed['missing'], 0, 2) : [],
    ];
    @file_put_contents($cacheFile, json_encode($out));
    response(true, $out);
} catch (Exception $e) {
    error_log('[cart-summary] ' . $e->getMessage());
    response(false, null, 'erro', 500);
}
