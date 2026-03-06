<?php
/**
 * POST /api/mercado/admin/group-order-actions.php
 *
 * Acoes sobre pedidos em grupo a partir do painel administrativo.
 *
 * Body: {
 *   group_id: int,
 *   action: 'cancel'|'remove_participant'|'force_close'|'refund_all',
 *   participant_id?: int,
 *   reason?: string
 * }
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";
require_once __DIR__ . "/../helpers/ws-customer-broadcast.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();
    $admin_id = (int)$payload['uid'];

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') response(false, null, "Metodo nao permitido", 405);

    $input = getInput();
    $group_id = (int)($input['group_id'] ?? 0);
    $action = trim($input['action'] ?? '');
    $participant_id = (int)($input['participant_id'] ?? 0);
    $reason = strip_tags(trim($input['reason'] ?? 'Acao administrativa'));

    if (!$group_id) response(false, null, "group_id obrigatorio", 400);
    if (!in_array($action, ['cancel', 'remove_participant', 'force_close', 'refund_all'])) {
        response(false, null, "action invalida. Aceitos: cancel, remove_participant, force_close, refund_all", 400);
    }

    $db->beginTransaction();

    // Lock group row
    $stmt = $db->prepare("SELECT * FROM om_market_group_orders WHERE id = ? FOR UPDATE");
    $stmt->execute([$group_id]);
    $group = $stmt->fetch();

    if (!$group) {
        $db->rollBack();
        response(false, null, "Grupo nao encontrado", 404);
    }

    // === CANCEL: Cancel group and all member items ===
    if ($action === 'cancel') {
        if ($group['status'] === 'cancelled') {
            $db->rollBack();
            response(false, null, "Grupo ja esta cancelado", 409);
        }

        $db->prepare("UPDATE om_market_group_orders SET status = 'cancelled', updated_at = NOW() WHERE id = ?")
           ->execute([$group_id]);

        // Broadcast to group
        try {
            wsBroadcastToGroup($group['share_code'], 'group_update', [
                'group_id' => $group_id,
                'status' => 'cancelled',
                'reason' => $reason,
            ]);
        } catch (Exception $e) {
            error_log("[group-order-actions] WS broadcast error: " . $e->getMessage());
        }

        om_audit()->log('group_order_cancel', 'group_order', $group_id, ['status' => $group['status']], ['status' => 'cancelled', 'reason' => $reason],
            "Grupo #{$group_id} (codigo: {$group['share_code']}) cancelado pelo admin. Motivo: {$reason}");

        $db->commit();
        response(true, ['group_id' => $group_id, 'action' => 'cancel'], "Grupo cancelado com sucesso");
    }

    // === REMOVE PARTICIPANT ===
    if ($action === 'remove_participant') {
        if (!$participant_id) {
            $db->rollBack();
            response(false, null, "participant_id obrigatorio para remover participante", 400);
        }

        // Verify participant belongs to this group
        $stmt = $db->prepare("SELECT * FROM om_market_group_order_participants WHERE id = ? AND group_order_id = ?");
        $stmt->execute([$participant_id, $group_id]);
        $participant = $stmt->fetch();

        if (!$participant) {
            $db->rollBack();
            response(false, null, "Participante nao encontrado neste grupo", 404);
        }

        // Remove participant items
        $db->prepare("DELETE FROM om_market_group_order_items WHERE participant_id = ? AND group_order_id = ?")
           ->execute([$participant_id, $group_id]);

        // Remove participant
        $db->prepare("DELETE FROM om_market_group_order_participants WHERE id = ?")
           ->execute([$participant_id]);

        // Broadcast update
        try {
            wsBroadcastToGroup($group['share_code'], 'group_update', [
                'group_id' => $group_id,
                'action' => 'participant_removed',
                'participant_id' => $participant_id,
            ]);
        } catch (Exception $e) {
            error_log("[group-order-actions] WS broadcast error: " . $e->getMessage());
        }

        om_audit()->log('group_order_remove_participant', 'group_order', $group_id, null,
            ['participant_id' => $participant_id, 'reason' => $reason],
            "Participante #{$participant_id} removido do grupo #{$group_id}");

        $db->commit();
        response(true, ['group_id' => $group_id, 'participant_id' => $participant_id, 'action' => 'remove_participant'], "Participante removido com sucesso");
    }

    // === FORCE CLOSE: Close group, finalize as-is ===
    if ($action === 'force_close') {
        if (in_array($group['status'], ['closed', 'cancelled'])) {
            $db->rollBack();
            response(false, null, "Grupo ja esta {$group['status']}", 409);
        }

        $db->prepare("UPDATE om_market_group_orders SET status = 'closed', updated_at = NOW() WHERE id = ?")
           ->execute([$group_id]);

        try {
            wsBroadcastToGroup($group['share_code'], 'group_update', [
                'group_id' => $group_id,
                'status' => 'closed',
                'reason' => $reason,
            ]);
        } catch (Exception $e) {
            error_log("[group-order-actions] WS broadcast error: " . $e->getMessage());
        }

        om_audit()->log('group_order_force_close', 'group_order', $group_id, ['status' => $group['status']], ['status' => 'closed', 'reason' => $reason],
            "Grupo #{$group_id} fechado forcadamente pelo admin. Motivo: {$reason}");

        $db->commit();
        response(true, ['group_id' => $group_id, 'action' => 'force_close'], "Grupo fechado com sucesso");
    }

    // === REFUND ALL: Cancel group and mark refunds for all participants ===
    if ($action === 'refund_all') {
        // Cancel the group
        $db->prepare("UPDATE om_market_group_orders SET status = 'cancelled', updated_at = NOW() WHERE id = ?")
           ->execute([$group_id]);

        // Get all participants with their totals
        $stmt = $db->prepare("
            SELECT gp.id as participant_id, gp.customer_id,
                   COALESCE(SUM(gi.price * gi.quantity), 0) as participant_total
            FROM om_market_group_order_participants gp
            LEFT JOIN om_market_group_order_items gi ON gi.participant_id = gp.id AND gi.group_order_id = ?
            WHERE gp.group_order_id = ?
            GROUP BY gp.id, gp.customer_id
        ");
        $stmt->execute([$group_id, $group_id]);
        $participants = $stmt->fetchAll();

        $refund_count = 0;
        foreach ($participants as $p) {
            if ((float)$p['participant_total'] > 0 && $p['customer_id']) {
                // Create refund record for each participant
                try {
                    $db->prepare("
                        INSERT INTO om_market_refunds (order_id, customer_id, amount, reason, status, created_at, reviewed_at, reviewed_by)
                        VALUES (0, ?, ?, ?, 'approved', NOW(), NOW(), ?)
                    ")->execute([(int)$p['customer_id'], $p['participant_total'], "Reembolso grupo #{$group_id}: {$reason}", $admin_id]);
                    $refund_count++;
                } catch (Exception $e) {
                    error_log("[group-order-actions] Refund error for participant {$p['participant_id']}: " . $e->getMessage());
                }
            }
        }

        try {
            wsBroadcastToGroup($group['share_code'], 'group_update', [
                'group_id' => $group_id,
                'status' => 'cancelled',
                'refunded' => true,
            ]);
        } catch (Exception $e) {
            error_log("[group-order-actions] WS broadcast error: " . $e->getMessage());
        }

        om_audit()->log('group_order_refund_all', 'group_order', $group_id, null,
            ['refund_count' => $refund_count, 'reason' => $reason],
            "Grupo #{$group_id} cancelado com reembolso para {$refund_count} participantes");

        $db->commit();
        response(true, ['group_id' => $group_id, 'action' => 'refund_all', 'refund_count' => $refund_count], "Grupo cancelado e reembolsos criados");
    }

    $db->rollBack();
    response(false, null, "Acao nao processada", 400);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log("[admin/group-order-actions] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
