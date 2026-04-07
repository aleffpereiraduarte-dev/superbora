<?php
/**
 * POST /api/mercado/intelligence/referral-message.php
 * Body: { "friend_name":"...","relation":"familiar|amigo|colega","style":"casual|formal|engracado" }
 *
 * Generates a personalized referral message that the user can send via WhatsApp.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, 'Metodo nao permitido', 405);
}

try {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $friendName = trim($input['friend_name'] ?? '');
    $relation = trim($input['relation'] ?? 'amigo');
    $style = trim($input['style'] ?? 'casual');
    $referralCode = trim($input['referral_code'] ?? '');

    if ($friendName === '') response(false, null, 'friend_name obrigatorio', 400);

    $prompt = "Gere uma mensagem de WhatsApp personalizada pra convidar {$friendName} ({$relation}) " .
              "pro SuperBora delivery. Estilo: {$style}. " .
              "Cupom: R\$15 OFF no 1o pedido + R\$10 cashback pra mim. " .
              "Codigo: {$referralCode}\n\n" .
              "Responda APENAS JSON: " .
              '{"message":"texto curto pessoal max 350 chars","tip":"dica de quando enviar"}';

    $reply = ClaudeClient::text($prompt, 'Voce escreve mensagens de indicacao naturais, sem soar comercial.', 500);
    $parsed = ClaudeClient::parseJson($reply ?: '');
    if (!$parsed) response(false, null, 'falha geracao', 502);

    response(true, [
        'message' => $parsed['message'] ?? '',
        'tip' => $parsed['tip'] ?? '',
        'whatsapp_url' => 'https://wa.me/?text=' . urlencode($parsed['message'] ?? ''),
    ]);
} catch (Exception $e) {
    error_log('[referral-message] ' . $e->getMessage());
    response(false, null, 'erro', 500);
}
