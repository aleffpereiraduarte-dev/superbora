<?php
/**
 * POST /admin/credit/override.php
 *
 * Admin can manually override the AI decision or credit limit for a
 * specific evaluation. Updates om_credit_evaluations with the admin's
 * decision and also pushes the new limit to om_credit_cards if the
 * customer already has an active card.
 *
 * Body:
 *   {
 *     "evaluation_id": 123,                   // required
 *     "final_decision": "aprovado|revisao|negado",  // required
 *     "final_limit": 2500,                    // required when decision != negado
 *     "notes": "justificativa opcional"
 *   }
 *
 * Also notifies the customer via push when a limit increase/decrease
 * happens so they see the change immediately.
 */

require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../helpers/credit-scoring.php";
require_once __DIR__ . "/../../helpers/NotificationSender.php";
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

    // Make sure table exists
    (new CreditScoringEngine($db));

    $input = getInput();
    $evaluationId = (int)($input['evaluation_id'] ?? 0);
    $decision     = (string)($input['final_decision'] ?? '');
    $limit        = isset($input['final_limit']) ? (float)$input['final_limit'] : null;
    $notes        = trim((string)($input['notes'] ?? ''));

    if ($evaluationId <= 0) response(false, null, 'evaluation_id obrigatorio', 400);
    if (!in_array($decision, ['aprovado','revisao','negado'], true)) {
        response(false, null, 'final_decision invalida', 400);
    }
    if ($decision !== 'negado') {
        if ($limit === null || $limit < 0) response(false, null, 'final_limit obrigatorio', 400);
        if ($limit > 50000) response(false, null, 'Limite excede o maximo permitido', 400);
    } else {
        $limit = 0.0;
    }

    // Fetch evaluation
    $stmt = $db->prepare("SELECT * FROM om_credit_evaluations WHERE id = ? LIMIT 1");
    $stmt->execute([$evaluationId]);
    $eval = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$eval) response(false, null, 'Avaliacao nao encontrada', 404);

    $customerId  = (int)$eval['customer_id'];
    $previousLimit = (float)$eval['final_limit'];

    $db->beginTransaction();

    try {
        $upd = $db->prepare("
            UPDATE om_credit_evaluations
               SET admin_override       = true,
                   admin_override_by    = ?,
                   admin_override_limit = ?,
                   admin_override_notes = ?,
                   admin_override_at    = NOW(),
                   final_decision       = ?,
                   final_limit          = ?
             WHERE id = ?
        ");
        $upd->execute([$adminId, $limit, $notes, $decision, $limit, $evaluationId]);

        // Reflect on existing credit card, if any
        $card = $db->prepare("
            SELECT id, status, credit_limit FROM om_credit_cards
            WHERE customer_id = ?
            ORDER BY created_at DESC LIMIT 1
        ");
        $card->execute([$customerId]);
        $existingCard = $card->fetch(PDO::FETCH_ASSOC);

        if ($existingCard) {
            if ($decision === 'negado') {
                $db->prepare("
                    UPDATE om_credit_cards
                       SET status = 'rejected',
                           block_reason = 'Avaliacao revisada por admin',
                           blocked_at = NOW()
                     WHERE id = ?
                ")->execute([$existingCard['id']]);
            } else {
                $db->prepare("UPDATE om_credit_cards SET credit_limit = ? WHERE id = ?")
                   ->execute([$limit, $existingCard['id']]);
            }
        }

        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }

    // Notify the customer if their limit changed materially
    try {
        $sender = NotificationSender::getInstance($db);
        if ($decision === 'negado' && $previousLimit > 0) {
            $sender->notifyCustomer($customerId,
                'SuperBora Bank',
                'Seu limite de credito foi revisado. Entre em contato com o suporte para mais detalhes.',
                ['type' => 'credit_override', 'decision' => 'negado']
            );
        } elseif ($limit > $previousLimit + 50) {
            $sender->notifyCustomer($customerId,
                'Seu limite aumentou!',
                'Seu novo limite de credito e de R$ ' . number_format($limit, 2, ',', '.'),
                ['type' => 'credit_override', 'decision' => 'aprovado', 'limit' => $limit]
            );
        } elseif ($limit < $previousLimit - 50) {
            $sender->notifyCustomer($customerId,
                'Seu limite foi ajustado',
                'Seu novo limite de credito e de R$ ' . number_format($limit, 2, ',', '.'),
                ['type' => 'credit_override', 'decision' => 'revisao', 'limit' => $limit]
            );
        }
    } catch (Exception $e) {
        error_log('[admin-credit-override] notify failed: ' . $e->getMessage());
    }

    response(true, [
        'evaluation_id'   => $evaluationId,
        'customer_id'     => $customerId,
        'final_decision'  => $decision,
        'final_limit'     => $limit,
        'previous_limit'  => $previousLimit,
        'admin_override'  => true,
    ], 'Override aplicado com sucesso');
} catch (Exception $e) {
    error_log('[admin-credit-override] ' . $e->getMessage());
    response(false, null, 'Erro ao aplicar override', 500);
}
