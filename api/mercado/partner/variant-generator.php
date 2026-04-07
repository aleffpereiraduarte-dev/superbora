<?php
/**
 * POST /api/mercado/partner/variant-generator.php
 * Body: { "base_product":"X-Burger Classico", "category":"hamburgueria" }
 *
 * Generates suggested product variants (sizes, additions, combos).
 * Used in the partner panel when creating a new product.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, 'Metodo nao permitido', 405);
}

try {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $base = trim($input['base_product'] ?? '');
    $category = trim($input['category'] ?? '');
    if ($base === '') response(false, null, 'base_product obrigatorio', 400);

    $prompt = "Produto base: \"{$base}\" (categoria: {$category}).\n\n" .
              "Gere variantes/adicionais que parceiros tipicamente oferecem. " .
              "Responda APENAS JSON: " .
              '{"sizes":[{"name":"P","extra_price":0},{"name":"M","extra_price":5}],' .
              '"additions":[{"name":"Bacon extra","extra_price":4}],' .
              '"removals":["cebola","tomate"],' .
              '"combo_ideas":[{"name":"Combo individual","includes":["produto","fritas","bebida"],"discount_pct":10}],' .
              '"suggested_descriptions":["descricao curta atrativa"]}';

    $reply = ClaudeClient::text($prompt, 'Voce eh especialista em catalogo de delivery. JSON apenas.', 800);
    $parsed = ClaudeClient::parseJson($reply ?: '');
    if (!$parsed) response(false, null, 'falha geracao', 502);

    response(true, $parsed);
} catch (Exception $e) {
    error_log('[variant-gen] ' . $e->getMessage());
    response(false, null, 'erro', 500);
}
