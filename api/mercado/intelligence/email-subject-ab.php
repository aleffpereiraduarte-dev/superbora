<?php
/**
 * POST /api/mercado/intelligence/email-subject-ab.php
 * Body: { "campaign_topic":"...", "tone":"...", "variants":4 }
 *
 * Generates A/B/C variations of email subject lines + preview text.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, 'Metodo nao permitido', 405);
}

try {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $topic = trim($input['campaign_topic'] ?? '');
    $tone = trim($input['tone'] ?? 'casual');
    $variants = max(2, min(10, (int)($input['variants'] ?? 4)));
    if ($topic === '') response(false, null, 'campaign_topic obrigatorio', 400);

    $prompt = "Gere {$variants} variacoes de email subject line em pt-BR para:\n" .
              "Topico: {$topic}\nTom: {$tone}\n\n" .
              "Cada variacao: subject (max 50 chars), preview (max 90 chars), abordagem (urgencia|curiosidade|beneficio|personal|promocional). " .
              "Responda APENAS JSON: " .
              '{"variants":[{"subject":"...","preview":"...","approach":"...","predicted_open_rate":"alto|medio|baixo","why":"..."}]}';

    $reply = ClaudeClient::text($prompt, 'Voce eh email copywriter expert em open rates.', 1200);
    $parsed = ClaudeClient::parseJson($reply ?: '');
    if (!$parsed || empty($parsed['variants'])) response(false, null, 'falha geracao', 502);

    response(true, [
        'topic' => $topic,
        'tone' => $tone,
        'variants' => $parsed['variants'],
    ]);
} catch (Exception $e) {
    error_log('[email-subject-ab] ' . $e->getMessage());
    response(false, null, 'erro', 500);
}
