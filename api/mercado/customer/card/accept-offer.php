<?php
/**
 * POST /customer/card/accept-offer.php
 *
 * Customer accepts a pre-approval. Generates the actual card number + CVV
 * (encrypted at rest), activates the card, returns the card details.
 *
 * Body: { card_id: 123 }   // optional — defaults to latest pre_approved card
 * Response: { card_id, status:'active', credit_limit, masked_number, ... }
 */
require_once __DIR__ . "/_common.php";
setCorsHeaders();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        response(false, null, 'Método não permitido', 405);
    }

    $db = getDB();
    $customerId = requireCustomerAuth();
    ensureCardTables($db);

    $input = getInput();
    $cardId = isset($input['card_id']) ? (int)$input['card_id'] : 0;

    $db->beginTransaction();

    // Load pre-approved offer
    if ($cardId > 0) {
        $stmt = $db->prepare("
            SELECT * FROM om_credit_cards
            WHERE id = ? AND customer_id = ? AND status = 'pre_approved'
            FOR UPDATE
        ");
        $stmt->execute([$cardId, $customerId]);
    } else {
        $stmt = $db->prepare("
            SELECT * FROM om_credit_cards
            WHERE customer_id = ? AND status = 'pre_approved'
            ORDER BY offer_created_at DESC NULLS LAST, created_at DESC
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([$customerId]);
    }
    $offer = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$offer) {
        $db->rollBack();
        response(false, null, 'Nenhuma oferta pré-aprovada encontrada', 404);
    }

    $cardId = (int)$offer['id'];

    // Expiry check
    if (!empty($offer['offer_expires_at']) && strtotime($offer['offer_expires_at']) < time()) {
        $db->prepare("UPDATE om_credit_cards SET status = 'offer_expired' WHERE id = ?")->execute([$cardId]);
        $db->commit();
        logCardEvent($db, $cardId, $customerId, 'offer_expired_on_accept', 'system', null, []);
        response(false, null, 'Esta oferta expirou. Solicite uma nova avaliacao.', 410);
    }

    // Generate card number + CVV
    $brand = $offer['card_brand'] ?: 'Mastercard';
    $number = generateCardNumber($brand);
    $last4  = substr($number, -4);
    $cvv    = generateCvv();
    $expDt  = new DateTime('+5 years');
    // Store as DATE (last day of the month) for DB; also keep display string
    $expDateSql = $expDt->format('Y-m-t'); // "2031-04-30"
    $expStr = $expDt->format('m/Y'); // "04/2031" for display

    $numberEnc = encryptData($number);
    $cvvEnc    = encryptData($cvv);

    $stmt = $db->prepare("
        UPDATE om_credit_cards
        SET status = 'active',
            card_number_encrypted = ?,
            card_last4 = ?,
            cvv_encrypted = ?,
            expires_at = ?,
            accepted_at = NOW(),
            approved_at = COALESCE(approved_at, NOW()),
            activated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$numberEnc, $last4, $cvvEnc, $expDateSql, $cardId]);

    logCardEvent($db, $cardId, $customerId, 'accepted', 'customer', $customerId, [
        'limit' => (float)$offer['credit_limit'],
    ]);

    $db->commit();

    // Broadcast pro app avisar que o cartão foi ativado — a tela de carteira,
    // checkout, cartões e oferta reagem em tempo real.
    try {
        require_once dirname(__DIR__, 2) . '/helpers/ws-customer-broadcast.php';
        if (function_exists('wsBroadcastToCustomer')) {
            wsBroadcastToCustomer($customerId, 'card_activated', [
                'card_id'        => $cardId,
                'credit_limit'   => (float)$offer['credit_limit'],
                'brand'          => $brand,
                'last4'          => $last4,
            ]);
        }
    } catch (\Throwable $e) { /* silent */ }

    response(true, [
        'card_id'         => $cardId,
        'status'          => 'active',
        'credit_limit'    => (float)$offer['credit_limit'],
        'available_limit' => (float)$offer['credit_limit'],
        'used_limit'      => 0,
        'card_brand'      => $brand,
        'masked_number'   => '**** **** **** ' . $last4,
        'last4'           => $last4,
        'expires_at'      => $expStr,
        'virtual'         => (bool)$offer['virtual'],
    ]);
} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('[customer-card-accept-offer] ' . $e->getMessage());
    response(false, null, 'Erro ao aceitar oferta', 500);
}
