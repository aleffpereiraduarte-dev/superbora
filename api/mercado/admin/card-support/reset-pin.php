<?php
/**
 * POST /admin/card-support/reset-pin.php
 *
 * Request a PIN reset for the customer's card. The virtual card doesn't have a
 * server-side PIN yet, so this endpoint just records the event + notifies the
 * customer that the reset was completed (mock).
 *
 * Body:
 *   { card_id, reason?: string }
 */

require_once __DIR__ . "/_common.php";
require_once __DIR__ . "/../../helpers/notify.php";

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        response(false, null, 'Metodo nao permitido', 405);
    }

    $ctx = bootstrapCardSupport();
    $db  = $ctx['db'];
    $adminId = $ctx['admin_id'];

    $input = getInput();
    $cardId = (int)($input['card_id'] ?? 0);
    $reason = trim((string)($input['reason'] ?? ''));

    if ($cardId <= 0) response(false, null, 'card_id obrigatorio', 400);

    $stmt = $db->prepare("SELECT id, customer_id, status, card_last4 FROM om_credit_cards WHERE id = ? LIMIT 1");
    $stmt->execute([$cardId]);
    $card = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$card) response(false, null, 'Cartao nao encontrado', 404);

    $customerId = (int)$card['customer_id'];

    logCardSupportEvent($db, $cardId, $customerId, 'pin_reset', $adminId, [
        'reason' => $reason,
    ], $reason !== '' ? $reason : 'PIN reset pelo suporte');

    // Support note
    $noteStmt = $db->prepare("
        INSERT INTO om_card_support_notes (customer_id, card_id, agent_id, agent_name, note, visibility)
        VALUES (?, ?, ?, ?, ?, 'internal')
    ");
    $noteStmt->execute([
        $customerId, $cardId, $adminId, $ctx['admin_name'],
        sprintf("PIN resetado via suporte. Motivo: %s", $reason !== '' ? $reason : 'nao informado'),
    ]);

    // Notify customer
    try {
        notifyCustomer(
            $db,
            $customerId,
            'PIN do cartao resetado',
            'O PIN do seu cartao final ' . ($card['card_last4'] ?? '') . ' foi resetado. Defina um novo PIN no app.',
            '/cartao',
            ['type' => 'card_pin_reset', 'card_id' => $cardId]
        );
    } catch (Exception $e) { error_log('[card-support-reset-pin notify] ' . $e->getMessage()); }

    response(true, [
        'card_id'    => $cardId,
        'pin_reset'  => true,
        'reset_at'   => date('Y-m-d H:i:s'),
    ]);
} catch (Exception $e) {
    error_log('[card-support-reset-pin] ' . $e->getMessage());
    response(false, null, 'Erro ao resetar PIN', 500);
}
