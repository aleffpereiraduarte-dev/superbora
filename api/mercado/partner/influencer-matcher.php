<?php
/**
 * POST /api/mercado/partner/influencer-matcher.php
 * Body: { "partner_id":123, "city":"...","budget_brl":500 }
 *
 * Suggests microinfluencer profiles that fit a partner store and gives
 * an outreach script.
 *
 * Note: doesn't actually search Instagram — uses Llama to suggest archetypes
 * and provide an outreach template.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, 'Metodo nao permitido', 405);
}

try {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $partnerId = (int)($input['partner_id'] ?? 0);
    $city = trim($input['city'] ?? '');
    $budget = (float)($input['budget_brl'] ?? 0);

    if (!$partnerId) response(false, null, 'partner_id obrigatorio', 400);

    $db = getDB();
    $stmt = $db->prepare("SELECT name, categoria, city FROM om_market_partners WHERE partner_id = :pid");
    $stmt->execute([':pid' => $partnerId]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$p) response(false, null, 'parceiro nao encontrado', 404);

    $cityFinal = $city ?: $p['city'];

    $prompt = "Loja: {$p['name']} ({$p['categoria']}) em {$cityFinal}. Budget: R\${$budget}.\n\n" .
              "Sugira 5 ARQUETIPOS de microinfluencers locais (1k-30k seguidores) que combinariam. " .
              "Inclua: nicho, faixa de seguidores ideal, tipo de conteudo, hashtags pra encontrar, " .
              "preco aproximado por post, e um script de abordagem inicial. " .
              "Responda APENAS JSON: " .
              '{"archetypes":[{"niche":"...","follower_range":"...","content_type":"...","search_hashtags":["#"],"approx_price":0,"why_fits":"..."}],' .
              '"outreach_script":"DM template em pt-BR max 400 chars"}';

    $reply = ClaudeClient::text($prompt, 'Voce eh especialista em marketing de influencia local. Pratico.', 1200);
    $parsed = ClaudeClient::parseJson($reply ?: '');
    if (!$parsed) response(false, null, 'falha geracao', 502);

    response(true, [
        'partner_id' => $partnerId,
        'city' => $cityFinal,
        'budget' => $budget,
        'archetypes' => $parsed['archetypes'] ?? [],
        'outreach_script' => $parsed['outreach_script'] ?? '',
    ]);
} catch (Exception $e) {
    error_log('[influencer-matcher] ' . $e->getMessage());
    response(false, null, 'erro', 500);
}
