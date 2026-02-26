<?php
/**
 * POST /api/mercado/gift-cards/purchase.php
 * Purchase a gift card
 */
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../helpers/rate-limit.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

try {
    $input = getInput();
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    // Auth is optional for gift card purchase (guests can buy too)
    $buyerId = null;
    $token = om_auth()->getTokenFromRequest();
    if ($token) {
        $payload = om_auth()->validateToken($token);
        if ($payload && $payload['type'] === 'customer') {
            $buyerId = (int)$payload['uid'];
        }
    }

    // Rate limiting: 10 purchases per hour per customer (or IP for guests)
    $rateLimitKey = $buyerId ? "giftcard_purchase_c{$buyerId}" : "giftcard_purchase_" . getRateLimitIP();
    if (!checkRateLimit($rateLimitKey, 10, 60)) {
        response(false, null, "Muitas compras de cartao presente. Tente novamente em 1 hora.", 429);
    }

    $amount = (float)($input['amount'] ?? 0);
    $recipientName = trim($input['recipient_name'] ?? '');
    $recipientEmail = trim($input['recipient_email'] ?? '');
    $message = trim($input['message'] ?? '');

    if ($amount < 10 || $amount > 1000 || !is_finite($amount)) {
        response(false, null, "Valor deve ser entre R$10 e R$1000", 400);
    }
    if (empty($recipientName)) {
        response(false, null, "Nome do destinatario obrigatorio", 400);
    }
    if (mb_strlen($recipientName) > 100) {
        response(false, null, "Nome muito longo", 400);
    }
    if (!empty($recipientEmail) && !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        response(false, null, "Email invalido", 400);
    }
    if (mb_strlen($message) > 500) {
        response(false, null, "Mensagem muito longa", 400);
    }

    // Generate unique 16-char code
    $code = '';
    $attempts = 0;
    do {
        $code = strtoupper(substr(bin2hex(random_bytes(10)), 0, 16));
        // Format: XXXX-XXXX-XXXX-XXXX
        $check = $db->prepare("SELECT id FROM om_market_gift_cards WHERE code = ?");
        $check->execute([$code]);
        $attempts++;
    } while ($check->fetch() && $attempts < 10);

    // Gift card is created as 'pending_payment' until payment is confirmed.
    // Payment confirmation webhook should update status to 'active'.
    $stmt = $db->prepare("
        INSERT INTO om_market_gift_cards (code, amount, balance, buyer_id, recipient_name, recipient_email, message, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'pending_payment')
    ");
    $stmt->execute([$code, $amount, $amount, $buyerId, $recipientName, $recipientEmail, $message]);

    $cardId = (int)$db->lastInsertId();

    // Format code for display
    $formattedCode = implode('-', str_split($code, 4));

    response(true, [
        'card_id' => $cardId,
        'code' => $code,
        'formatted_code' => $formattedCode,
        'amount' => $amount,
        'recipient_name' => $recipientName,
        'recipient_email' => $recipientEmail,
        'message' => $message,
    ], "Cartao presente criado com sucesso!");

} catch (Exception $e) {
    error_log("[API Gift Card Purchase] Erro: " . $e->getMessage());
    response(false, null, "Erro ao criar cartao presente", 500);
}
