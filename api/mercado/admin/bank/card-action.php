<?php
/**
 * POST /admin/bank/card-action.php
 *
 * Manual admin card actions. Body:
 *   { "card_id": 123, "action": "block" | "unblock" | "cancel" | "set_limit", "limit": 1500, "reason": "..." }
 */
require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../helpers/bank-brain.php";
require_once __DIR__ . "/../../helpers/NotificationSender.php";
require_once dirname(__DIR__, 4) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        response(false, null, 'Metodo nao permitido', 405);
    }
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    $admin = om_auth()->requireAdmin();
    $adminId = (int)($admin['uid'] ?? $admin['user_id'] ?? 0);
    $brain = new SuperBoraBankBrain($db);

    $input = getInput();
    $cardId = (int)($input['card_id'] ?? 0);
    $action = (string)($input['action'] ?? '');
    $reason = substr((string)($input['reason'] ?? ''), 0, 200);

    if ($cardId <= 0) response(false, null, 'card_id obrigatorio', 400);

    $card = $db->prepare("SELECT * FROM om_credit_cards WHERE id = ?");
    $card->execute([$cardId]);
    $c = $card->fetch(PDO::FETCH_ASSOC);
    if (!$c) response(false, null, 'Cartao nao encontrado', 404);
    $customerId = (int)$c['customer_id'];

    $sender = NotificationSender::getInstance($db);

    switch ($action) {
        case 'block':
            $db->prepare("UPDATE om_credit_cards SET status='blocked', blocked_at=NOW(), block_reason=? WHERE id=?")
               ->execute([$reason ?: 'Bloqueio manual', $cardId]);
            $db->prepare("INSERT INTO om_credit_card_events (card_id, customer_id, event_type, actor_type, actor_id, notes) VALUES (?, ?, 'admin_block', 'admin', ?, ?)")
               ->execute([$cardId, $customerId, $adminId, $reason]);
            try {
                $sender->notifyCustomer($customerId, 'Cartao bloqueado',
                    'Seu cartao foi bloqueado. Entre em contato com o suporte.',
                    ['type' => 'admin_block']);
            } catch (Exception $e) { /* ignore */ }
            break;

        case 'unblock':
            $db->prepare("UPDATE om_credit_cards SET status='active', blocked_at=NULL, block_reason=NULL WHERE id=?")
               ->execute([$cardId]);
            $db->prepare("INSERT INTO om_credit_card_events (card_id, customer_id, event_type, actor_type, actor_id, notes) VALUES (?, ?, 'admin_unblock', 'admin', ?, ?)")
               ->execute([$cardId, $customerId, $adminId, $reason]);
            try {
                $sender->notifyCustomer($customerId, 'Cartao desbloqueado',
                    'Seu cartao esta funcionando novamente.',
                    ['type' => 'admin_unblock']);
            } catch (Exception $e) { /* ignore */ }
            break;

        case 'cancel':
            $db->prepare("UPDATE om_credit_cards SET status='cancelled', cancelled_at=NOW(), cancel_reason=? WHERE id=?")
               ->execute([$reason ?: 'Cancelado pelo admin', $cardId]);
            $db->prepare("INSERT INTO om_credit_card_events (card_id, customer_id, event_type, actor_type, actor_id, notes) VALUES (?, ?, 'admin_cancel', 'admin', ?, ?)")
               ->execute([$cardId, $customerId, $adminId, $reason]);
            break;

        case 'set_limit':
            $newLimit = round((float)($input['limit'] ?? 0), 2);
            if ($newLimit < 0 || $newLimit > 100000) {
                response(false, null, 'Limite invalido', 400);
            }
            $old = (float)$c['credit_limit'];
            $db->prepare("UPDATE om_credit_cards SET credit_limit=? WHERE id=?")
               ->execute([$newLimit, $cardId]);
            $db->prepare("
                INSERT INTO om_credit_card_events (card_id, customer_id, event_type, actor_type, actor_id, payload, notes)
                VALUES (?, ?, 'admin_set_limit', 'admin', ?, ?::jsonb, ?)
            ")->execute([$cardId, $customerId, $adminId,
                json_encode(['old_limit' => $old, 'new_limit' => $newLimit]), $reason]);
            try {
                $sender->notifyCustomer($customerId,
                    $newLimit > $old ? 'Seu limite aumentou!' : 'Seu limite foi ajustado',
                    'Novo limite: R$ ' . number_format($newLimit, 2, ',', '.'),
                    ['type' => 'admin_set_limit', 'new_limit' => $newLimit]);
            } catch (Exception $e) { /* ignore */ }
            break;

        default:
            response(false, null, 'Acao invalida', 400);
    }

    response(true, ['card_id' => $cardId, 'action' => $action], 'Acao executada');
} catch (Exception $e) {
    error_log('[admin-bank-card-action] ' . $e->getMessage());
    response(false, null, 'Erro ao executar acao', 500);
}
