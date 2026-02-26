<?php
/**
 * GET /api/mercado/gift-cards/check.php?code=XXX
 * Check gift card info without redeeming
 */
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../helpers/rate-limit.php";

try {
    $db = getDB();

    // Rate limiting: 10 checks per 15 minutes per IP
    $ip = getRateLimitIP();
    if (!checkRateLimit("giftcard_check_{$ip}", 10, 15)) {
        response(false, null, "Muitas requisicoes. Tente novamente em 15 minutos.", 429);
    }

    // SECURITY: Additional rate limit per code to prevent brute-force enumeration
    $codeInput = strtoupper(preg_replace('/[^A-Z0-9]/', '', $_GET['code'] ?? ''));
    if (!empty($codeInput) && !checkRateLimit("giftcard_code_{$codeInput}", 3, 60)) {
        response(false, null, "Muitas tentativas para este codigo. Tente novamente em 1 hora.", 429);
    }

    $code = strtoupper(preg_replace('/[^A-Z0-9]/', '', $_GET['code'] ?? ''));
    if (empty($code)) response(false, null, "Codigo obrigatorio", 400);

    $stmt = $db->prepare("
        SELECT code, amount, balance, status, recipient_name, message, created_at
        FROM om_market_gift_cards
        WHERE code = ?
    ");
    $stmt->execute([$code]);
    $card = $stmt->fetch();

    if (!$card) response(false, null, "Cartao presente nao encontrado", 404);

    $formattedCode = implode('-', str_split($card['code'], 4));

    response(true, [
        'code' => $card['code'],
        'formatted_code' => $formattedCode,
        'amount' => (float)$card['amount'],
        'balance' => (float)$card['balance'],
        'status' => $card['status'],
        'recipient_name' => $card['recipient_name'],
        'message' => $card['message'],
        'created_at' => $card['created_at'],
        'is_valid' => $card['status'] === 'active' && (float)$card['balance'] > 0,
    ]);

} catch (Exception $e) {
    error_log("[API Gift Card Check] Erro: " . $e->getMessage());
    response(false, null, "Erro ao verificar cartao", 500);
}
