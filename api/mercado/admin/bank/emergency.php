<?php
/**
 * POST /admin/bank/emergency.php  — global emergency actions
 *
 * Body:
 *   { "action": "pause_approvals" | "resume_approvals" | "block_all_active" | "unblock_all" }
 *
 * This is the admin's "big red button" — when something is going terribly
 * wrong, the admin can (a) pause all autonomous approvals, (b) emergency-block
 * every active card, or (c) resume normal operations.
 */
require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../helpers/bank-brain.php";
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
    $action = (string)($input['action'] ?? '');

    switch ($action) {
        case 'pause_approvals':
            $brain->updatePolicy(['auto_approval_enabled' => false], $adminId);
            $msg = 'Aprovacoes automaticas pausadas';
            break;
        case 'resume_approvals':
            $brain->updatePolicy(['auto_approval_enabled' => true], $adminId);
            $msg = 'Aprovacoes automaticas retomadas';
            break;
        case 'block_all_active':
            $upd = $db->prepare("
                UPDATE om_credit_cards
                SET status = 'blocked', blocked_at = NOW(),
                    block_reason = 'Bloqueio de emergencia pelo admin'
                WHERE status = 'active'
                RETURNING id, customer_id
            ");
            $upd->execute();
            $count = 0;
            foreach ($upd->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $db->prepare("
                    INSERT INTO om_credit_card_events (card_id, customer_id, event_type, actor_type, actor_id, notes)
                    VALUES (?, ?, 'emergency_block', 'admin', ?, ?)
                ")->execute([$r['id'], $r['customer_id'], $adminId, 'Bloqueio em massa pelo admin']);
                $count++;
            }
            $msg = "Bloqueados {$count} cartoes em emergencia";
            break;
        case 'unblock_all':
            $upd = $db->prepare("
                UPDATE om_credit_cards
                SET status = 'active', blocked_at = NULL, block_reason = NULL
                WHERE status = 'blocked' AND block_reason LIKE 'Bloqueio de emergencia%'
                RETURNING id, customer_id
            ");
            $upd->execute();
            $count = 0;
            foreach ($upd->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $db->prepare("
                    INSERT INTO om_credit_card_events (card_id, customer_id, event_type, actor_type, actor_id, notes)
                    VALUES (?, ?, 'emergency_unblock', 'admin', ?, 'Desbloqueio em massa')
                ")->execute([$r['id'], $r['customer_id'], $adminId]);
                $count++;
            }
            $msg = "Desbloqueados {$count} cartoes";
            break;
        default:
            response(false, null, 'Acao invalida', 400);
    }

    response(true, ['action' => $action, 'count' => $count ?? null], $msg);
} catch (Exception $e) {
    error_log('[admin-bank-emergency] ' . $e->getMessage());
    response(false, null, 'Erro na acao de emergencia', 500);
}
