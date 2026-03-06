<?php
/**
 * POST /api/mercado/admin/scheduled-order-actions.php
 *
 * Acoes sobre pedidos agendados a partir do painel administrativo.
 *
 * Body: {
 *   order_id: int,
 *   action: 'cancel'|'reschedule'|'retry'|'pause'|'resume',
 *   scheduled_for?: string (date YYYY-MM-DD, required for reschedule),
 *   scheduled_time?: string (HH:MM),
 *   reason?: string
 * }
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";
require_once __DIR__ . "/../helpers/notify.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();
    $admin_id = (int)$payload['uid'];

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') response(false, null, "Metodo nao permitido", 405);

    $input = getInput();
    $order_id = (int)($input['order_id'] ?? 0);
    $action = trim($input['action'] ?? '');
    $scheduled_for = trim($input['scheduled_for'] ?? '');
    $scheduled_time = trim($input['scheduled_time'] ?? '');
    $reason = strip_tags(trim($input['reason'] ?? ''));

    if (!$order_id) response(false, null, "order_id obrigatorio", 400);
    if (!in_array($action, ['cancel', 'reschedule', 'retry', 'pause', 'resume'])) {
        response(false, null, "action invalida. Aceitos: cancel, reschedule, retry, pause, resume", 400);
    }

    $db->beginTransaction();

    // Lock scheduled order row
    $stmt = $db->prepare("SELECT * FROM om_market_scheduled_orders WHERE id = ? FOR UPDATE");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch();

    if (!$order) {
        $db->rollBack();
        response(false, null, "Pedido agendado nao encontrado", 404);
    }

    $old_status = $order['status'];

    // === CANCEL ===
    if ($action === 'cancel') {
        if ($order['status'] === 'cancelado') {
            $db->rollBack();
            response(false, null, "Pedido ja esta cancelado", 409);
        }

        $db->prepare("UPDATE om_market_scheduled_orders SET status = 'cancelado', updated_at = NOW() WHERE id = ?")
           ->execute([$order_id]);

        // If linked to recurring, optionally note it
        if ($order['recurring_id']) {
            // Just cancel this instance, not the recurrence
        }

        // Notify customer
        if ($order['customer_id']) {
            try {
                notifyCustomer(
                    $db,
                    (int)$order['customer_id'],
                    'Pedido agendado cancelado',
                    'Seu pedido agendado para ' . date('d/m/Y', strtotime($order['scheduled_date'])) . ' foi cancelado.' . ($reason ? " Motivo: {$reason}" : ''),
                    '/agendados'
                );
            } catch (Exception $e) {
                error_log("[scheduled-order-actions] Notify error: " . $e->getMessage());
            }
        }

        om_audit()->log('scheduled_order_cancel', 'scheduled_order', $order_id, ['status' => $old_status], ['status' => 'cancelado', 'reason' => $reason],
            "Pedido agendado #{$order_id} cancelado pelo admin" . ($reason ? ". Motivo: {$reason}" : ""));

        $db->commit();
        response(true, ['order_id' => $order_id, 'action' => 'cancel', 'new_status' => 'cancelado'], "Pedido agendado cancelado");
    }

    // === RESCHEDULE ===
    if ($action === 'reschedule') {
        if (!$scheduled_for) {
            $db->rollBack();
            response(false, null, "scheduled_for obrigatorio para reagendar (formato: YYYY-MM-DD)", 400);
        }

        // Validate date format
        $date = \DateTime::createFromFormat('Y-m-d', $scheduled_for);
        if (!$date || $date->format('Y-m-d') !== $scheduled_for) {
            $db->rollBack();
            response(false, null, "scheduled_for invalido (formato: YYYY-MM-DD)", 400);
        }

        // Must be in the future
        if ($scheduled_for < date('Y-m-d')) {
            $db->rollBack();
            response(false, null, "Data deve ser futura", 400);
        }

        $updates = "scheduled_date = ?, updated_at = NOW()";
        $updateParams = [$scheduled_for];

        if ($scheduled_time !== '') {
            $updates .= ", scheduled_time = ?";
            $updateParams[] = $scheduled_time;
        }

        // Reset to agendado if it was past due
        if ($order['status'] !== 'concluido') {
            $updates .= ", status = 'agendado'";
        }

        $updateParams[] = $order_id;
        $db->prepare("UPDATE om_market_scheduled_orders SET {$updates} WHERE id = ?")->execute($updateParams);

        // Notify customer
        if ($order['customer_id']) {
            try {
                $dateFormatted = date('d/m/Y', strtotime($scheduled_for));
                $timeStr = $scheduled_time ? " as {$scheduled_time}" : '';
                notifyCustomer(
                    $db,
                    (int)$order['customer_id'],
                    'Pedido reagendado',
                    "Seu pedido foi reagendado para {$dateFormatted}{$timeStr}.",
                    '/agendados'
                );
            } catch (Exception $e) {
                error_log("[scheduled-order-actions] Notify error: " . $e->getMessage());
            }
        }

        om_audit()->log('scheduled_order_reschedule', 'scheduled_order', $order_id,
            ['scheduled_date' => $order['scheduled_date'], 'scheduled_time' => $order['scheduled_time']],
            ['scheduled_date' => $scheduled_for, 'scheduled_time' => $scheduled_time ?: $order['scheduled_time']],
            "Pedido agendado #{$order_id} reagendado para {$scheduled_for}");

        $db->commit();
        response(true, [
            'order_id' => $order_id,
            'action' => 'reschedule',
            'scheduled_date' => $scheduled_for,
            'scheduled_time' => $scheduled_time ?: $order['scheduled_time'],
        ], "Pedido reagendado com sucesso");
    }

    // === RETRY: Re-trigger processing ===
    if ($action === 'retry') {
        if (!in_array($order['status'], ['agendado', 'falha'])) {
            $db->rollBack();
            response(false, null, "Apenas pedidos agendados ou com falha podem ser reprocessados (status atual: {$order['status']})", 409);
        }

        $db->prepare("UPDATE om_market_scheduled_orders SET status = 'processando', updated_at = NOW() WHERE id = ?")
           ->execute([$order_id]);

        om_audit()->log('scheduled_order_retry', 'scheduled_order', $order_id, ['status' => $old_status], ['status' => 'processando'],
            "Pedido agendado #{$order_id} reprocessado pelo admin");

        $db->commit();
        response(true, ['order_id' => $order_id, 'action' => 'retry', 'new_status' => 'processando'], "Pedido sendo reprocessado");
    }

    // === PAUSE: Pause recurring order ===
    if ($action === 'pause') {
        $recurring_id = (int)$order['recurring_id'];
        if (!$recurring_id) {
            $db->rollBack();
            response(false, null, "Este pedido nao esta vinculado a um pedido recorrente", 400);
        }

        // Pause the recurring order
        $stmt = $db->prepare("UPDATE om_market_recurring_orders SET is_active = false, updated_at = NOW() WHERE id = ? AND is_active = true");
        $stmt->execute([$recurring_id]);

        if ($stmt->rowCount() === 0) {
            $db->rollBack();
            response(false, null, "Pedido recorrente ja esta pausado ou nao encontrado", 409);
        }

        // Notify customer
        if ($order['customer_id']) {
            try {
                notifyCustomer(
                    $db,
                    (int)$order['customer_id'],
                    'Pedido recorrente pausado',
                    'Seu pedido recorrente foi pausado pelo suporte.' . ($reason ? " Motivo: {$reason}" : ''),
                    '/agendados'
                );
            } catch (Exception $e) {
                error_log("[scheduled-order-actions] Notify error: " . $e->getMessage());
            }
        }

        om_audit()->log('scheduled_order_pause', 'recurring_order', $recurring_id, ['is_active' => true], ['is_active' => false, 'reason' => $reason],
            "Pedido recorrente #{$recurring_id} pausado pelo admin" . ($reason ? ". Motivo: {$reason}" : ""));

        $db->commit();
        response(true, ['order_id' => $order_id, 'recurring_id' => $recurring_id, 'action' => 'pause'], "Pedido recorrente pausado");
    }

    // === RESUME: Resume paused recurring order ===
    if ($action === 'resume') {
        $recurring_id = (int)$order['recurring_id'];
        if (!$recurring_id) {
            $db->rollBack();
            response(false, null, "Este pedido nao esta vinculado a um pedido recorrente", 400);
        }

        // Resume the recurring order
        $stmt = $db->prepare("UPDATE om_market_recurring_orders SET is_active = true, updated_at = NOW() WHERE id = ? AND is_active = false");
        $stmt->execute([$recurring_id]);

        if ($stmt->rowCount() === 0) {
            $db->rollBack();
            response(false, null, "Pedido recorrente ja esta ativo ou nao encontrado", 409);
        }

        // Reset scheduled order status to agendado
        $db->prepare("UPDATE om_market_scheduled_orders SET status = 'agendado', updated_at = NOW() WHERE id = ?")
           ->execute([$order_id]);

        // Notify customer
        if ($order['customer_id']) {
            try {
                notifyCustomer(
                    $db,
                    (int)$order['customer_id'],
                    'Pedido recorrente reativado',
                    'Seu pedido recorrente foi reativado pelo suporte.' . ($reason ? " Nota: {$reason}" : ''),
                    '/agendados'
                );
            } catch (Exception $e) {
                error_log("[scheduled-order-actions] Notify error: " . $e->getMessage());
            }
        }

        om_audit()->log('scheduled_order_resume', 'recurring_order', $recurring_id, ['is_active' => false], ['is_active' => true, 'reason' => $reason],
            "Pedido recorrente #{$recurring_id} reativado pelo admin" . ($reason ? ". Nota: {$reason}" : ""));

        $db->commit();
        response(true, ['order_id' => $order_id, 'recurring_id' => $recurring_id, 'action' => 'resume', 'new_status' => 'agendado'], "Pedido recorrente reativado");
    }

    $db->rollBack();
    response(false, null, "Acao nao processada", 400);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log("[admin/scheduled-order-actions] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
