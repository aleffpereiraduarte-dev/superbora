<?php
/**
 * GET /api/mercado/intelligence/explain-product.php?product_id=123
 * Returns an AI-generated explainer for the product:
 *   - Friendly description
 *   - Approximate nutritional info (when applicable)
 *   - Best paired items
 *   - When/why to consume
 *
 * Cached 24h per product.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    response(false, null, 'Metodo nao permitido', 405);
}

try {
    $productId = (int)($_GET['product_id'] ?? 0);
    if (!$productId) {
        response(false, null, 'product_id obrigatorio', 400);
    }

    $cacheFile = sys_get_temp_dir() . "/sb_explain_{$productId}.json";
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 86400) {
        response(true, json_decode(file_get_contents($cacheFile), true));
    }

    $db = getDB();
    $stmt = $db->prepare(
        "SELECT p.product_id, p.name, p.description, p.price, p.unit, p.brand,
                pa.name AS partner_name, pa.categoria
         FROM om_market_products p
         LEFT JOIN om_market_partners pa ON p.partner_id = pa.partner_id
         WHERE p.product_id = :pid"
    );
    $stmt->execute([':pid' => $productId]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$p) {
        response(false, null, 'produto nao encontrado', 404);
    }

    $userPrompt = "Produto: {$p['name']}\n" .
                  "Marca: " . ($p['brand'] ?? '-') . "\n" .
                  "Loja: {$p['partner_name']} (categoria: {$p['categoria']})\n" .
                  "Descricao atual: " . ($p['description'] ?? '-') . "\n" .
                  "Preco: R$ {$p['price']}\n\n" .
                  "Gere um explainer amigavel em portugues do Brasil. Responda APENAS com JSON valido:\n" .
                  '{"resumo":"1-2 frases","nutricional":"info nutricional aproximada ou null","quando_consumir":"sugestao de momento","combina_com":["item1","item2"],"curiosidade":"fato divertido"}';

    $reply = ClaudeClient::text(
        $userPrompt,
        'Voce eh um especialista em alimentos e bebidas. Responda APENAS JSON valido.',
        500
    );
    if (!$reply) {
        response(false, null, 'falha ao gerar explainer', 502);
    }

    $parsed = ClaudeClient::parseJson($reply) ?: [];
    $out = [
        'product_id' => $productId,
        'product_name' => $p['name'],
        'resumo' => $parsed['resumo'] ?? '',
        'nutricional' => $parsed['nutricional'] ?? null,
        'quando_consumir' => $parsed['quando_consumir'] ?? '',
        'combina_com' => is_array($parsed['combina_com'] ?? null) ? $parsed['combina_com'] : [],
        'curiosidade' => $parsed['curiosidade'] ?? '',
    ];
    @file_put_contents($cacheFile, json_encode($out));
    response(true, $out);
} catch (Exception $e) {
    error_log('[explain-product] ' . $e->getMessage());
    response(false, null, 'erro', 500);
}
