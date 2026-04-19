<?php
/**
 * POST /admin/cards/action.php
 *
 * Admin actions on a credit card:
 *   - block          : status -> blocked (record reason)
 *   - unblock        : blocked -> active (clears block_reason)
 *   - cancel         : any -> cancelled
 *   - change_limit   : update credit_limit (must be >= used_limit)
 *   - resend_offer   : renew offer_expires_at on a pre_approved card
 *
 * Body:
 *   {
 *     "card_id":  123,
 *     "action":   "block" | "unblock" | "cancel" | "change_limit" | "resend_offer",
 *     "reason":   "string (optional)",
 *     "new_limit": 2500       (only when action = change_limit),
 *     "extra_days": 30        (only when action = resend_offer, default 30)
 *   }
 *
 * Response: updated card row + notifies customer.
 */

require_once __DIR__ . "/../../customer/card/_common.php";
require_once __DIR__ . "/../../helpers/notify.php";
require_once dirname(__DIR__, 4) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        response(false, null, 'Metodo nao permitido', 405);
    }

    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    $adminPayload = om_auth()->requireAdmin();
    $adminId = (int)($adminPayload['uid'] ?? $adminPayload['user_id'] ?? 0);
    ensureCardTables($db);

    $input = getInput();
    $cardId   = (int)($input['card_id'] ?? 0);
    $action   = (string)($input['action'] ?? '');
    $reason   = trim((string)($input['reason'] ?? ''));
    $newLimit = isset($input['new_limit']) ? (float)$input['new_limit'] : null;
    $extraDays = max(1, min(90, (int)($input['extra_days'] ?? 30)));

    if ($cardId <= 0) response(false, null, 'card_id obrigatorio', 400);
    if (!in_array($action, ['block', 'unblock', 'cancel', 'change_limit', 'resend_offer'], true)) {
        response(false, null, 'Acao invalida', 400);
    }

    $db->beginTransaction();

    $stmt = $db->prepare("SELECT * FROM om_credit_cards WHERE id = ? FOR UPDATE");
    $stmt->execute([$cardId]);
    $card = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$card) {
        $db->rollBack();
        response(false, null, 'Cartao nao encontrado', 404);
    }

    $customerId = (int)$card['customer_id'];
    $status = $card['status'];

    $notifyTitle = null;
    $notifyBody  = null;

    switch ($action) {
        case 'block':
            if (!in_array($status, ['active', 'pre_approved', 'pending_approval'], true)) {
                $db->rollBack();
                response(false, null, "Cartao nao pode ser bloqueado no status atual ({$status})", 409);
            }
            $stmt = $db->prepare("
                UPDATE om_credit_cards
                SET status = 'blocked', blocked_at = NOW(), block_reason = ?
                WHERE id = ?
            ");
            $stmt->execute([$reason !== '' ? $reason : 'Acao administrativa', $cardId]);
            logCardEvent($db, $cardId, $customerId, 'blocked', 'admin', $adminId, ['reason' => $reason]);
            $notifyTitle = 'Cartao SuperBora bloqueado';
            $notifyBody  = $reason !== '' ? "Motivo: {$reason}" : 'Seu cartao foi temporariamente bloqueado.';
            break;

        case 'unblock':
            if ($status !== 'blocked') {
                $db->rollBack();
                response(false, null, 'Cartao nao esta bloqueado', 409);
            }
            $stmt = $db->prepare("
                UPDATE om_credit_cards
                SET status = 'active', blocked_at = NULL, block_reason = NULL
                WHERE id = ?
            ");
            $stmt->execute([$cardId]);
            logCardEvent($db, $cardId, $customerId, 'unblocked', 'admin', $adminId, []);
            $notifyTitle = 'Cartao SuperBora desbloqueado';
            $notifyBody  = 'Seu cartao voltou a ficar ativo.';
            break;

        case 'cancel':
            if (in_array($status, ['cancelled', 'rejected', 'offer_expired', 'declined_by_customer'], true)) {
                $db->rollBack();
                response(false, null, 'Cartao ja esta em estado terminal', 409);
            }
            $stmt = $db->prepare("
                UPDATE om_credit_cards
                SET status = 'cancelled', cancelled_at = NOW(), cancel_reason = ?
                WHERE id = ?
            ");
            $stmt->execute([$reason !== '' ? $reason : 'Cancelado pelo admin', $cardId]);
            logCardEvent($db, $cardId, $customerId, 'cancelled', 'admin', $adminId, ['reason' => $reason]);
            $notifyTitle = 'Cartao SuperBora cancelado';
            $notifyBody  = $reason !== '' ? "Motivo: {$reason}" : 'Seu cartao foi cancelado.';
            break;

        case 'change_limit':
            if ($newLimit === null || $newLimit < 0 || $newLimit > 50000) {
                $db->rollBack();
                response(false, null, 'new_limit invalido (0..50000)', 400);
            }
            $used = (float)$card['used_limit'];
            if ($newLimit < $used) {
                $db->rollBack();
                response(false, null, sprintf('Limite nao pode ser menor que o valor usado (R$ %s)', number_format($used, 2, ',', '.')), 409);
            }
            $oldLimit = (float)$card['credit_limit'];
            $stmt = $db->prepare("UPDATE om_credit_cards SET credit_limit = ? WHERE id = ?");
            $stmt->execute([$newLimit, $cardId]);
            logCardEvent($db, $cardId, $customerId, 'limit_changed', 'admin', $adminId, [
                'old_limit' => $oldLimit, 'new_limit' => $newLimit, 'reason' => $reason,
            ]);
            $delta = $newLimit - $oldLimit;
            $notifyTitle = $delta >= 0 ? 'Limite do cartao aumentado!' : 'Limite do cartao ajustado';
            $notifyBody  = sprintf(
                'Novo limite: R$ %s (antes R$ %s)',
                number_format($newLimit, 2, ',', '.'),
                number_format($oldLimit, 2, ',', '.')
            );
            break;

        case 'resend_offer':
            if ($status !== 'pre_approved') {
                $db->rollBack();
                response(false, null, 'Apenas ofertas pre-aprovadas podem ser reenviadas', 409);
            }
            $newExpires = (new DateTime("+{$extraDays} days"))->format('Y-m-d H:i:s');
            $stmt = $db->prepare("UPDATE om_credit_cards SET offer_expires_at = ? WHERE id = ?");
            $stmt->execute([$newExpires, $cardId]);
            logCardEvent($db, $cardId, $customerId, 'offer_resent', 'admin', $adminId, [
                'new_expires_at' => $newExpires, 'extra_days' => $extraDays,
            ]);
            $notifyTitle = 'Sua oferta de cartao ainda esta de pe!';
            $notifyBody  = sprintf('Limite de R$ %s reservado. Aceite no app.', number_format((float)$card['credit_limit'], 2, ',', '.'));
            break;
    }

    $db->commit();

    // Push notify customer (fire-and-forget)
    if ($notifyTitle) {
        try {
            notifyCustomer($db, $customerId, $notifyTitle, $notifyBody, '/cartao', [
                'type'    => 'card_status_change',
                'card_id' => $cardId,
                'action'  => $action,
            ]);
        } catch (Exception $e) {
            error_log('[admin-cards-action notify] ' . $e->getMessage());
        }
    }

    // Return updated card
    $stmt = $db->prepare("SELECT * FROM om_credit_cards WHERE id = ?");
    $stmt->execute([$cardId]);
    $updated = $stmt->fetch(PDO::FETCH_ASSOC);

    response(true, [
        'card_id'          => $cardId,
        'status'           => $updated['status'],
        'credit_limit'     => (float)$updated['credit_limit'],
        'used_limit'       => (float)$updated['used_limit'],
        'blocked_at'       => $updated['blocked_at'],
        'block_reason'     => $updated['block_reason'],
        'cancelled_at'     => $updated['cancelled_at']     ?? null,
        'cancel_reason'    => $updated['cancel_reason']    ?? null,
        'offer_expires_at' => $updated['offer_expires_at'] ?? null,
        'action'           => $action,
    ]);
} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('[admin-cards-action] ' . $e->getMessage());
    response(false, null, 'Erro ao executar acao', 500);
}
