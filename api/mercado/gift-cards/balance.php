<?php
/**
 * GET /api/mercado/gift-cards/balance.php?code=XXXX-XXXX-XXXX-XXXX
 * Check gift card status/balance by code
 *
 * Returns: { valid, amount, status (active/redeemed/expired), purchaser_name, message }
 * No auth required (anyone can check balance)
 */
require_once __DIR__ . "/../config/database.php";

setCorsHeaders();

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    response(false, null, "Metodo nao permitido", 405);
}

// Clean code: remove dashes, spaces
$code = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $_GET['code'] ?? ''));

if (empty($code)) {
    response(false, null, "Codigo obrigatorio. Use ?code=XXXX-XXXX-XXXX-XXXX", 400);
}
if (strlen($code) < 10 || strlen($code) > 20) {
    response(false, null, "Codigo invalido", 400);
}

try {
    $db = getDB();

    $stmt = dbQuery($db, "
        SELECT id, code, value as card_amount,
               status, purchaser_name, recipient_name, message, expires_at, created_at
        FROM om_gift_cards
        WHERE code = ?
    ", [$code]);
    $card = $stmt->fetch();

    if (!$card) {
        response(true, [
            'valid' => false,
            'status' => 'not_found',
        ], "Vale-presente nao encontrado");
    }

    $cardAmount = (float)($card['card_amount'] ?: 0);
    $status = $card['status'] ?: 'active';

    // Check if expired by date even if status is still 'active'
    if ($status === 'active' && !empty($card['expires_at']) && strtotime($card['expires_at']) < time()) {
        $status = 'expired';
        // Update in background
        dbQuery($db, "UPDATE om_gift_cards SET status = 'expired', is_active = false WHERE id = ?", [$card['id']]);
    }

    $valid = ($status === 'active');

    response(true, [
        'valid' => $valid,
        'is_valid' => $valid, // alias for frontend compatibility
        'amount' => $cardAmount,
        'status' => $status,
        'purchaser_name' => $card['purchaser_name'] ?: null,
        'recipient_name' => $card['recipient_name'] ?: null,
        'message' => $card['message'] ?: null,
        'expires_at' => $card['expires_at'],
        'created_at' => $card['created_at'],
        'code' => $card['code'],
        'formatted_code' => implode('-', str_split($card['code'], 4)),
    ]);

} catch (Exception $e) {
    error_log("[GiftCard Balance] Erro: " . $e->getMessage());
    response(false, null, "Erro ao verificar vale-presente", 500);
}
