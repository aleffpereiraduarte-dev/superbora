<?php
/**
 * POST /api/mercado/gift-cards/redeem.php
 * Redeem a gift card code
 *
 * Body: { code }
 * Validates: code exists, not already redeemed, not expired
 * Credits amount to customer's cashback wallet (om_cashback_wallet)
 * Marks gift card as redeemed with redeemer_id and redeemed_at
 * Auth required
 */
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../helpers/cashback.php";

setCorsHeaders();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    response(false, null, "Metodo nao permitido", 405);
}

$customerId = requireCustomerAuth();
$db = getDB();
$input = getInput();

// Clean code: remove dashes, spaces, lowercase
$code = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $input['code'] ?? ''));

if (empty($code) || strlen($code) < 10 || strlen($code) > 20) {
    response(false, null, "Codigo invalido", 400);
}

try {
    $db->beginTransaction();

    // SECURITY: FOR UPDATE lock to prevent double-spend race condition
    $stmt = dbQuery($db, "
        SELECT id, code, COALESCE(amount, value) as amount, status, expires_at
        FROM om_gift_cards
        WHERE code = ?
        FOR UPDATE
    ", [$code]);
    $card = $stmt->fetch();

    if (!$card) {
        $db->rollBack();
        response(false, null, "Vale-presente nao encontrado", 404);
    }

    // Check status
    if ($card['status'] === 'redeemed') {
        $db->rollBack();
        response(false, null, "Este vale-presente ja foi resgatado", 400);
    }
    if ($card['status'] === 'expired') {
        $db->rollBack();
        response(false, null, "Este vale-presente expirou", 400);
    }
    if ($card['status'] !== 'active') {
        $db->rollBack();
        response(false, null, "Este vale-presente nao esta disponivel", 400);
    }

    // Check expiry
    if (!empty($card['expires_at']) && strtotime($card['expires_at']) < time()) {
        // Mark as expired
        dbQuery($db, "UPDATE om_gift_cards SET status = 'expired', is_active = false WHERE id = ?", [$card['id']]);
        $db->commit();
        response(false, null, "Este vale-presente expirou", 400);
    }

    $amount = (float)$card['amount'];

    // Mark gift card as redeemed (atomic with lock held)
    $stmt = dbQuery($db, "
        UPDATE om_gift_cards
        SET status = 'redeemed',
            redeemer_id = ?,
            redeemed_by = ?,
            redeemed_at = NOW(),
            balance = 0,
            is_active = false
        WHERE id = ? AND status = 'active'
    ", [$customerId, $customerId, $card['id']]);

    if ($stmt->rowCount() === 0) {
        $db->rollBack();
        response(false, null, "Vale-presente ja foi resgatado por outra pessoa", 400);
    }

    // Credit amount to customer's cashback wallet
    // Ensure wallet exists
    // NOTE: ON CONFLICT (upsert) is PostgreSQL-specific syntax. This is correct for our stack (PostgreSQL via PDO).
    dbQuery($db, "
        INSERT INTO om_cashback_wallet (customer_id, balance, total_earned)
        VALUES (?, ?, ?)
        ON CONFLICT (customer_id) DO UPDATE SET
            balance = om_cashback_wallet.balance + EXCLUDED.balance,
            total_earned = om_cashback_wallet.total_earned + EXCLUDED.total_earned
    ", [$customerId, $amount, $amount]);

    // Record transaction
    $formattedCode = implode('-', str_split($card['code'], 4));
    $description = "Vale-presente resgatado: {$formattedCode}";

    // Get updated balance
    $stmt = dbQuery($db, "SELECT balance FROM om_cashback_wallet WHERE customer_id = ?", [$customerId]);
    $newBalance = (float)$stmt->fetchColumn();

    dbQuery($db, "
        INSERT INTO om_cashback_transactions
            (customer_id, type, amount, balance_after, description)
        VALUES (?, 'credit', ?, ?, ?)
    ", [$customerId, $amount, $newBalance, $description]);

    $db->commit();

    response(true, [
        'redeemed_amount' => $amount,
        'amount' => $amount,
        'total_balance' => $newBalance,
        'code' => $card['code'],
        'formatted_code' => $formattedCode,
    ], "Vale-presente resgatado! R$ " . number_format($amount, 2, ',', '.') . " adicionado ao seu saldo.");

} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log("[GiftCard Redeem] Erro: " . $e->getMessage());
    response(false, null, "Erro ao resgatar vale-presente", 500);
}
