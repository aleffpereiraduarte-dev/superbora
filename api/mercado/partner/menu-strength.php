<?php
/**
 * GET /api/mercado/partner/menu-strength.php
 * Auth: partner
 * Returns an AI assessment of the partner's menu:
 *   - Score 0-100
 *   - Strengths/weaknesses
 *   - Suggested additions/removals
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';
require_once dirname(__DIR__, 3) . '/includes/classes/OmAuth.php';
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    response(false, null, 'Metodo nao permitido', 405);
}

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, 'Token ausente', 401);
    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== OmAuth::USER_TYPE_PARTNER) {
        response(false, null, 'Nao autorizado', 401);
    }
    $partnerId = (int)$payload['uid'];

    // Cache 24h
    $cacheFile = sys_get_temp_dir() . "/sb_menustrength_{$partnerId}_" . date('Ymd') . ".json";
    if (file_exists($cacheFile)) {
        response(true, json_decode(file_get_contents($cacheFile), true));
    }

    $stmt = $db->prepare(
        "SELECT name, description, price, COALESCE(image,'') AS image
         FROM om_market_products
         WHERE partner_id = :pid AND status::text = '1'
         LIMIT 100"
    );
    $stmt->execute([':pid' => $partnerId]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($products)) {
        response(false, null, 'sem produtos', 404);
    }

    $catalog = array_map(fn($p) => $p['name'] . ' R$' . $p['price'] . ($p['image'] ? '' : ' [SEM_FOTO]'), $products);

    $prompt = "Analise este cardapio do SuperBora. " . count($products) . " itens:\n\n" .
              implode("\n", array_slice($catalog, 0, 100)) . "\n\n" .
              "Avalie e responda APENAS JSON: " .
              '{"score":0-100,"strengths":["..."],"weaknesses":["..."],' .
              '"missing_categories":["..."],"recommended_additions":["..."],' .
              '"items_to_remove":["nome do item"],"price_concerns":["..."],' .
              '"items_without_photo":<count>,"action_summary":"max 200 chars"}';

    $reply = ClaudeClient::text(
        $prompt,
        'Voce eh consultor de cardapios. Pratico, especifico, baseado em dados.',
        1500
    );
    $parsed = ClaudeClient::parseJson($reply ?: '');
    if (!$parsed) response(false, null, 'falha analise', 502);

    $parsed['partner_id'] = $partnerId;
    $parsed['analyzed_at'] = date('c');
    @file_put_contents($cacheFile, json_encode($parsed));
    response(true, $parsed);
} catch (Exception $e) {
    error_log('[menu-strength] ' . $e->getMessage());
    response(false, null, 'erro', 500);
}
