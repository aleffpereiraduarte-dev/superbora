<?php
/**
 * GET /api/mercado/partner/content-calendar.php?days=7
 * Auth: partner
 *
 * Generates a content calendar with daily post ideas for the partner.
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
    if (!$payload || $payload['type'] !== OmAuth::USER_TYPE_PARTNER) response(false, null, 'Acesso restrito', 403);
    $partnerId = (int)$payload['uid'];

    $days = max(1, min(30, (int)($_GET['days'] ?? 7)));

    $stmt = $db->prepare("SELECT name, categoria, address_city FROM om_market_partners WHERE partner_id = :pid");
    $stmt->execute([':pid' => $partnerId]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$p) response(false, null, 'parceiro nao encontrado', 404);

    // Cache 1 day per partner
    $cacheFile = sys_get_temp_dir() . "/sb_calendar_{$partnerId}_{$days}_" . date('Ymd') . ".json";
    if (file_exists($cacheFile)) {
        response(true, json_decode(file_get_contents($cacheFile), true));
    }

    $prompt = "Loja: {$p['name']} ({$p['categoria']}) em {$p['address_city']}. " .
              "Crie um calendario de conteudo de {$days} dias com 1 post por dia. " .
              "Variar formatos (foto produto, video, story, antes/depois, depoimento, promo, curiosidade). " .
              "Considerar dia da semana (segunda mais comercial, sexta mais festivo). " .
              "Responda APENAS JSON: " .
              '{"days":[{"day":1,"weekday":"Segunda","format":"foto","theme":"...","caption":"max 200 chars","best_time":"manha|tarde|noite","cta":"..."}]}';

    $reply = ClaudeClient::text($prompt, 'Voce eh content manager experiente em food delivery.', 2500);
    $parsed = ClaudeClient::parseJson($reply ?: '');
    if (!$parsed || empty($parsed['days'])) response(false, null, 'falha geracao', 502);

    @file_put_contents($cacheFile, json_encode($parsed));
    response(true, $parsed);
} catch (Exception $e) {
    error_log('[content-calendar] ' . $e->getMessage());
    response(false, null, 'erro', 500);
}
