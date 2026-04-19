<?php
/**
 * POST /admin/card-support/reissue-card.php
 *
 * Generate a new virtual card number for a customer:
 *   - cancels the existing card (status = cancelled, cancel_reason = "Reissued")
 *   - clones the most relevant fields into a new card row
 *   - returns the new card summary
 *
 * Body:
 *   { card_id, reason? }
 */

require_once __DIR__ . "/_common.php";
require_once __DIR__ . "/../../helpers/notify.php";
require_once __DIR__ . "/../../helpers/encryption.php";

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        response(false, null, 'Metodo nao permitido', 405);
    }

    $ctx = bootstrapCardSupport();
    $db  = $ctx['db'];
    $adminId = $ctx['admin_id'];

    $input = getInput();
    $cardId = (int)($input['card_id'] ?? 0);
    $reason = trim((string)($input['reason'] ?? 'Reemissao solicitada via suporte'));
    if ($cardId <= 0) response(false, null, 'card_id obrigatorio', 400);

    $db->beginTransaction();

    $stmt = $db->prepare("SELECT * FROM om_credit_cards WHERE id = ? FOR UPDATE");
    $stmt->execute([$cardId]);
    $old = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$old) { $db->rollBack(); response(false, null, 'Cartao nao encontrado', 404); }

    $customerId = (int)$old['customer_id'];
    $brand      = $old['card_brand']    ?: 'Mastercard';
    $oldLimit   = (float)$old['credit_limit'];
    $usedLimit  = (float)$old['used_limit'];

    // Generate new card + CVV
    $newNumber = generateCardNumber($brand);
    $newLast4  = substr($newNumber, -4);
    $newCvv    = generateCvv();
    $newExpiry = (new DateTime('+4 years'))->format('m/Y');

    $encNumber = encryptData($newNumber);
    $encCvv    = encryptData($newCvv);

    // Cancel old card (keeps used_limit on the old for accounting)
    $stmt = $db->prepare("
        UPDATE om_credit_cards
        SET status = 'cancelled',
            cancelled_at = NOW(),
            cancel_reason = ?
        WHERE id = ?
    ");
    $stmt->execute(['Reemitido: ' . ($reason ?: 'sem motivo informado'), $cardId]);

    // Create new card with same limit/terms; transfer used_limit so outstanding stays owed.
    $stmt = $db->prepare("
        INSERT INTO om_credit_cards (
            customer_id, card_brand, card_number_encrypted, card_last4, cvv_encrypted,
            expires_at, credit_limit, used_limit, status, virtual,
            declared_income, score, closing_day, due_day,
            approved_at, activated_at
        ) VALUES (
            ?, ?, ?, ?, ?,
            ?, ?, ?, 'active', true,
            ?, ?, ?, ?,
            NOW(), NOW()
        )
        RETURNING id
    ");
    $stmt->execute([
        $customerId, $brand, $encNumber, $newLast4, $encCvv,
        $newExpiry, $oldLimit, $usedLimit,
        $old['declared_income'], $old['score'],
        $old['closing_day'] ?? 5, $old['due_day'] ?? 15,
    ]);
    $newCardId = (int)$stmt->fetchColumn();

    // Transfer any open bills/transactions pointers? We leave existing bills tied
    // to the cancelled card to preserve history. Going-forward transactions will
    // hit the new card.

    logCardSupportEvent($db, $cardId, $customerId, 'reissued', $adminId, [
        'reason'        => $reason,
        'new_card_id'   => $newCardId,
        'new_last4'     => $newLast4,
    ], 'Cartao reemitido');

    logCardSupportEvent($db, $newCardId, $customerId, 'issued_from_reissue', $adminId, [
        'old_card_id' => $cardId,
        'old_last4'   => $old['card_last4'],
    ], 'Novo cartao emitido por reemissao');

    // Support note
    $noteStmt = $db->prepare("
        INSERT INTO om_card_support_notes (customer_id, card_id, agent_id, agent_name, note, visibility)
        VALUES (?, ?, ?, ?, ?, 'internal')
    ");
    $noteStmt->execute([
        $customerId, $newCardId, $adminId, $ctx['admin_name'],
        sprintf('Cartao reemitido. Antigo: **** %s - Novo: **** %s. Motivo: %s',
            $old['card_last4'] ?? '????', $newLast4, $reason),
    ]);

    $db->commit();

    // Notify customer
    try {
        notifyCustomer(
            $db,
            $customerId,
            'Novo cartao SuperBora disponivel',
            sprintf('Seu novo cartao %s %s foi emitido. O antigo foi cancelado.', $brand, '**** ' . $newLast4),
            '/cartao',
            ['type' => 'card_reissued', 'card_id' => $newCardId]
        );
    } catch (Exception $e) { error_log('[card-support-reissue notify] ' . $e->getMessage()); }

    response(true, [
        'old_card_id'   => $cardId,
        'new_card_id'   => $newCardId,
        'new_last4'     => $newLast4,
        'new_masked'    => '**** **** **** ' . $newLast4,
        'new_expires'   => $newExpiry,
        'credit_limit'  => $oldLimit,
        'used_limit'    => $usedLimit,
    ]);
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log('[card-support-reissue] ' . $e->getMessage());
    response(false, null, 'Erro ao reemitir cartao: ' . $e->getMessage(), 500);
}
