<?php
/**
 * GET /api/mercado/gift-cards/list.php
 * List gift cards purchased by and received by authenticated customer
 *
 * Returns: { purchased: [...], received: [...] }
 * Auth required
 */
require_once __DIR__ . "/../config/database.php";

setCorsHeaders();

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    response(false, null, "Metodo nao permitido", 405);
}

$customerId = requireCustomerAuth();
$db = getDB();

try {
    // First, expire any cards that are past their expiry date
    dbQuery($db, "
        UPDATE om_gift_cards
        SET status = 'expired', is_active = false
        WHERE status = 'active'
          AND expires_at IS NOT NULL
          AND expires_at < NOW()
          AND (purchaser_id = ? OR redeemer_id = ?)
    ", [$customerId, $customerId]);

    // Get cards purchased by this customer
    $stmt = dbQuery($db, "
        SELECT id, code, COALESCE(amount, value) as amount, recipient_name,
               recipient_email, message, status, redeemer_id, redeemed_at,
               expires_at, created_at
        FROM om_gift_cards
        WHERE purchaser_id = ?
        ORDER BY created_at DESC
        LIMIT 50
    ", [$customerId]);
    $purchased = $stmt->fetchAll();

    // Format codes
    foreach ($purchased as &$card) {
        $card['formatted_code'] = implode('-', str_split($card['code'], 4));
        $card['amount'] = (float)$card['amount'];
    }
    unset($card);

    // Get cards received/redeemed by this customer
    $stmt = dbQuery($db, "
        SELECT id, code, COALESCE(amount, value) as amount, purchaser_name,
               message, status, redeemed_at, expires_at, created_at
        FROM om_gift_cards
        WHERE redeemer_id = ?
        ORDER BY redeemed_at DESC
        LIMIT 50
    ", [$customerId]);
    $received = $stmt->fetchAll();

    // Format codes
    foreach ($received as &$card) {
        $card['formatted_code'] = implode('-', str_split($card['code'], 4));
        $card['amount'] = (float)$card['amount'];
    }
    unset($card);

    response(true, [
        'purchased' => $purchased,
        'received' => $received,
    ]);

} catch (Exception $e) {
    error_log("[GiftCard List] Erro: " . $e->getMessage());
    response(false, null, "Erro ao listar vale-presentes", 500);
}
