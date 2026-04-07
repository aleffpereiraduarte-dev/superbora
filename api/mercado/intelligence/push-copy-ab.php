<?php
/**
 * POST /api/mercado/intelligence/push-copy-ab.php
 * Body: { "context":"flash sale acai 30% off","audience":"young","variants":4 }
 * Returns N variations of push title+body, each with a hypothesis about why it would work.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, 'Metodo nao permitido', 405);
}

try {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $context = trim($input['context'] ?? '');
    $audience = trim($input['audience'] ?? 'todos');
    $variants = max(2, min(8, (int)($input['variants'] ?? 4)));

    if ($context === '') {
        response(false, null, 'context obrigatorio', 400);
    }

    $prompt = "Gere {$variants} variacoes de push notification em pt-BR.\n" .
              "Contexto: {$context}\n" .
              "Audiencia: {$audience}\n\n" .
              "Cada variacao deve ter: titulo (max 50 chars), corpo (max 120 chars), hipotese (porque funcionaria), abordagem (urgencia|curiosidade|beneficio|prova_social|exclusividade). " .
              "Use emojis. Responda APENAS JSON: " .
              '{"variants":[{"title":"...","body":"...","hypothesis":"...","approach":"..."}]}';

    $reply = ClaudeClient::text(
        $prompt,
        'Voce eh growth hacker especializado em copy de push notifications.',
        1000
    );
    $parsed = ClaudeClient::parseJson($reply ?: '');
    if (!$parsed || empty($parsed['variants'])) response(false, null, 'falha geracao', 502);

    response(true, [
        'context' => $context,
        'audience' => $audience,
        'variants' => $parsed['variants'],
    ]);
} catch (Exception $e) {
    error_log('[push-copy-ab] ' . $e->getMessage());
    response(false, null, 'erro', 500);
}
