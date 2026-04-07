<?php
/**
 * POST /api/mercado/intelligence/product-questions-bot.php
 * Body: { "product_id":123, "question":"Esse produto contem gluten?" }
 *
 * Answers customer questions about a specific product based on its name,
 * description, brand, and attributes_json (filled by extract-product-attributes).
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, 'Metodo nao permitido', 405);
}

try {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $productId = (int)($input['product_id'] ?? 0);
    $question = trim($input['question'] ?? '');
    if (!$productId || $question === '') response(false, null, 'product_id e question obrigatorios', 400);
    if (mb_strlen($question) > 300) response(false, null, 'pergunta muito longa', 400);

    $db = getDB();
    $stmt = $db->prepare(
        "SELECT name, description, COALESCE(brand,'') AS brand, attributes_json,
                (SELECT name FROM om_market_partners WHERE partner_id = p.partner_id) AS partner_name
         FROM om_market_products p WHERE product_id = :pid"
    );
    $stmt->execute([':pid' => $productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$product) response(false, null, 'produto nao encontrado', 404);

    $context = "Produto: {$product['name']}\nMarca: {$product['brand']}\n" .
               "Descricao: " . ($product['description'] ?? '-') . "\n" .
               "Loja: {$product['partner_name']}\n" .
               "Atributos: " . ($product['attributes_json'] ?? '{}');

    $prompt = $context . "\n\nPergunta do cliente: \"{$question}\"\n\n" .
              "Responda em pt-BR max 250 chars baseado APENAS no contexto acima. " .
              "Se nao souber, diga que precisa confirmar com a loja. " .
              "Nao invente informacoes sobre alergenicos ou nutricionais que nao estao no contexto.";

    $reply = ClaudeClient::text($prompt, 'Voce eh atendente da loja. Honesto, util, nao inventa.', 300);
    if (!$reply) response(false, null, 'falha resposta', 502);

    response(true, [
        'product_id' => $productId,
        'question' => $question,
        'answer' => $reply,
        'disclaimer' => 'Resposta gerada por IA. Em duvida, confirme com a loja.',
    ]);
} catch (Exception $e) {
    error_log('[product-q-bot] ' . $e->getMessage());
    response(false, null, 'erro', 500);
}
