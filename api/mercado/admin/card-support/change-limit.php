<?php
/**
 * POST /admin/card-support/change-limit.php
 *
 * Change a card's credit limit (support wrapper around admin/cards/action.php logic).
 *
 * Body:
 *   { card_id, new_limit, justification }
 *
 * Validates that:
 *   - card exists
 *   - new_limit >= current used_limit
 *   - justification is provided (support accountability)
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
    $cardId        = (int)($input['card_id'] ?? 0);
    $newLimit      = (float)($input['new_limit'] ?? -1);
    $justification = trim((string)($input['justification'] ?? ''));

    if ($cardId <= 0)              response(false, null, 'card_id obrigatorio', 400);
    if ($newLimit < 0 || $newLimit > 50000) response(false, null, 'new_limit invalido (0..50000)', 400);
    if (strlen($justification) < 5) response(false, null, 'Justificativa obrigatoria (minimo 5 caracteres)', 400);

    $db->beginTransaction();

    $stmt = $db->prepare("SELECT * FROM om_credit_cards WHERE id = ? FOR UPDATE");
    $stmt->execute([$cardId]);
    $card = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$card) { $db->rollBack(); response(false, null, 'Cartao nao encontrado', 404); }

    $used = (float)$card['used_limit'];
    $old  = (float)$card['credit_limit'];

    if ($newLimit < $used) {
        $db->rollBack();
        response(false, null, sprintf('Limite nao pode ser menor que o valor usado (R$ %s)', number_format($used, 2, ',', '.')), 409);
    }

    $stmt = $db->prepare("UPDATE om_credit_cards SET credit_limit = ? WHERE id = ?");
    $stmt->execute([$newLimit, $cardId]);

    $customerId = (int)$card['customer_id'];
    logCardSupportEvent($db, $cardId, $customerId, 'limit_changed', $adminId, [
        'old_limit'     => $old,
        'new_limit'     => $newLimit,
        'delta'         => $newLimit - $old,
        'justification' => $justification,
        'via'           => 'card-support',
    ], $justification);

    // Persist a support note for visibility
    $noteStmt = $db->prepare("
        INSERT INTO om_card_support_notes (customer_id, card_id, agent_id, agent_name, note, visibility)
        VALUES (?, ?, ?, ?, ?, 'internal')
    ");
    $noteStmt->execute([
        $customerId,
        $cardId,
        $adminId,
        $ctx['admin_name'],
        sprintf("Limite alterado de R$ %s para R$ %s. Justificativa: %s",
            number_format($old, 2, ',', '.'),
            number_format($newLimit, 2, ',', '.'),
            $justification
        ),
    ]);

    $db->commit();

    // Notify customer (fire-and-forget)
    try {
        $delta = $newLimit - $old;
        $title = $delta >= 0 ? 'Limite do cartao aumentado!' : 'Limite do cartao ajustado';
        $body  = sprintf('Novo limite: R$ %s (antes R$ %s)',
            number_format($newLimit, 2, ',', '.'),
            number_format($old, 2, ',', '.'));
        notifyCustomer($db, $customerId, $title, $body, '/cartao', [
            'type'    => 'card_limit_changed',
            'card_id' => $cardId,
        ]);
    } catch (Exception $e) { error_log('[card-support-change-limit notify] ' . $e->getMessage()); }

    response(true, [
        'card_id'   => $cardId,
        'old_limit' => $old,
        'new_limit' => $newLimit,
        'delta'     => $newLimit - $old,
    ]);
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log('[card-support-change-limit] ' . $e->getMessage());
    response(false, null, 'Erro ao alterar limite', 500);
}
