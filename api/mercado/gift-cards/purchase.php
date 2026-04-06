<?php
/**
 * POST /api/mercado/gift-cards/purchase.php
 * Purchase a gift card
 *
 * Body: { amount, recipient_name, recipient_email?, message? }
 * Amounts: R$25, R$50, R$100, R$200 (fixed options)
 * Generates unique 16-char alphanumeric code (XXXX-XXXX-XXXX-XXXX)
 * Returns: { code, amount, expires_at (1 year), share_url }
 * Auth required (buyer customer_id)
 */
require_once __DIR__ . "/../config/database.php";

setCorsHeaders();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    response(false, null, "Metodo nao permitido", 405);
}

$customerId = requireCustomerAuth();
$db = getDB();
$input = getInput();

// Validate amount - fixed options only
$allowedAmounts = [25, 50, 100, 200];
$amount = (float)($input['amount'] ?? 0);

if (!in_array($amount, $allowedAmounts, false)) {
    // Check with float tolerance
    $validAmount = false;
    foreach ($allowedAmounts as $a) {
        if (abs($amount - $a) < 0.01) {
            $amount = (float)$a;
            $validAmount = true;
            break;
        }
    }
    if (!$validAmount) {
        response(false, null, "Valor invalido. Opcoes: R$25, R$50, R$100 ou R$200", 400);
    }
}

$recipientName = trim(sanitizeOutput($input['recipient_name'] ?? ''));
$recipientEmail = trim($input['recipient_email'] ?? '');
$message = trim(sanitizeOutput($input['message'] ?? ''));

if (empty($recipientName)) {
    response(false, null, "Nome do destinatario obrigatorio", 400);
}
if (mb_strlen($recipientName) > 255) {
    response(false, null, "Nome do destinatario muito longo", 400);
}
if (!empty($recipientEmail) && !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
    response(false, null, "Email invalido", 400);
}
if (mb_strlen($message) > 500) {
    response(false, null, "Mensagem muito longa (maximo 500 caracteres)", 400);
}

try {
    // Get purchaser name
    $stmt = dbQuery($db, "SELECT name FROM om_market_customers WHERE customer_id = ?", [$customerId]);
    $purchaserName = $stmt->fetchColumn() ?: 'Cliente';

    // Generate unique 16-char alphanumeric code
    $code = '';
    $attempts = 0;
    do {
        // SECURITY: Cryptographically secure random
        $raw = strtoupper(bin2hex(random_bytes(8))); // 16 hex chars
        $code = substr($raw, 0, 16);
        $check = dbQuery($db, "SELECT 1 FROM om_gift_cards WHERE code = ?", [$code]);
        $attempts++;
        if ($attempts > 20) {
            response(false, null, "Erro ao gerar codigo. Tente novamente.", 500);
        }
    } while ($check->fetch());

    // Format: XXXX-XXXX-XXXX-XXXX
    $formattedCode = implode('-', str_split($code, 4));

    // Expires in 1 year
    $expiresAt = date('Y-m-d H:i:s', strtotime('+1 year'));

    // Insert gift card
    $stmt = dbQuery($db, "
        INSERT INTO om_gift_cards
            (code, amount, value, balance, purchaser_id, purchased_by, purchaser_name,
             recipient_name, recipient_email, message, status, is_active, expires_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', true, ?)
    ", [
        $code, $amount, $amount, $amount,
        $customerId, $customerId, $purchaserName,
        $recipientName, $recipientEmail, $message,
        $expiresAt
    ]);

    $cardId = (int)$db->lastInsertId();

    $shareUrl = "https://superbora.com.br/gift-card?code={$code}";

    response(true, [
        'card_id' => $cardId,
        'code' => $code,
        'formatted_code' => $formattedCode,
        'amount' => $amount,
        'recipient_name' => $recipientName,
        'recipient_email' => $recipientEmail,
        'message' => $message,
        'purchaser_name' => $purchaserName,
        'expires_at' => $expiresAt,
        'share_url' => $shareUrl,
    ], "Vale-presente criado com sucesso!");

} catch (Exception $e) {
    error_log("[GiftCard Purchase] Erro: " . $e->getMessage());
    response(false, null, "Erro ao criar vale-presente", 500);
}
