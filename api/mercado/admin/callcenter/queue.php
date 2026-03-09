<?php
/**
 * /api/mercado/admin/callcenter/queue.php
 * Call queue management
 *
 * GET                  — Current queue (pending items, ordered by priority then time)
 * POST action=pick     — Pick next queued call and assign to current agent
 * POST action=callback — Schedule a callback for a queued caller
 */

require_once __DIR__ . '/../../config/database.php';
require_once dirname(__DIR__, 4) . '/includes/classes/OmAuth.php';
require_once dirname(__DIR__, 4) . '/includes/classes/OmAudit.php';
require_once __DIR__ . '/../../helpers/ws-callcenter-broadcast.php';
require_once __DIR__ . '/../../helpers/callcenter-sms.php';

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();
    $adminId = (int)$payload['uid'];

    // Get agent ID
    $agentStmt = $db->prepare("SELECT id, display_name FROM om_callcenter_agents WHERE admin_id = ? LIMIT 1");
    $agentStmt->execute([$adminId]);
    $agent = $agentStmt->fetch();

    $agentId = $agent ? (int)$agent['id'] : 0;
    $agentName = $agent ? $agent['display_name'] : '';

    $method = $_SERVER['REQUEST_METHOD'];

    // ════════════════════════════════════════════════════════════════════
    // GET — Current queue
    // ════════════════════════════════════════════════════════════════════
    if ($method === 'GET') {
        // Auto-cleanup: finalize stuck calls (ai_handling/in_progress/on_hold older than 30 min)
        $db->query("
            UPDATE om_callcenter_calls
            SET status = 'completed',
                ended_at = COALESCE(ended_at, NOW()),
                duration_seconds = COALESCE(duration_seconds, EXTRACT(EPOCH FROM (NOW() - started_at))::int)
            WHERE status IN ('ai_handling', 'in_progress', 'on_hold', 'ringing')
            AND started_at < NOW() - INTERVAL '30 minutes'
        ");
        // Cleanup orphaned queue entries
        $db->query("
            UPDATE om_callcenter_queue SET abandoned_at = NOW()
            WHERE picked_at IS NULL AND abandoned_at IS NULL
            AND queued_at < NOW() - INTERVAL '30 minutes'
        ");

        $stmt = $db->query("
            SELECT q.id, q.call_id, q.customer_phone, q.customer_name, q.customer_id,
                   q.priority, q.estimated_wait_seconds, q.queued_at,
                   q.callback_number,
                   c.twilio_call_sid, c.direction, c.status as call_status,
                   c.store_identified, c.started_at
            FROM om_callcenter_queue q
            LEFT JOIN om_callcenter_calls c ON c.id = q.call_id
            WHERE q.picked_at IS NULL AND q.abandoned_at IS NULL
            ORDER BY q.priority ASC, q.queued_at ASC
        ");
        $queue = $stmt->fetchAll();

        // Calculate position and estimated wait
        $position = 0;
        foreach ($queue as &$item) {
            $position++;
            $item['id'] = (int)$item['id'];
            $item['call_id'] = $item['call_id'] ? (int)$item['call_id'] : null;
            $item['customer_id'] = $item['customer_id'] ? (int)$item['customer_id'] : null;
            $item['priority'] = (int)$item['priority'];
            $item['position'] = $position;
            $item['wait_seconds'] = $item['queued_at']
                ? (int)(time() - strtotime($item['queued_at']))
                : 0;
        }
        unset($item);

        // Also return agent counts
        $agentCounts = $db->query("
            SELECT status, COUNT(*) as count FROM om_callcenter_agents GROUP BY status
        ")->fetchAll();
        $agentStats = [];
        foreach ($agentCounts as $ac) {
            $agentStats[$ac['status']] = (int)$ac['count'];
        }

        // Active calls (in_progress, on_hold, ai_handling, ringing)
        $activeStmt = $db->query("
            SELECT c.id, c.twilio_call_sid, c.customer_phone, c.customer_name, c.customer_id,
                   c.agent_id, c.direction, c.status, c.store_identified,
                   c.started_at, c.answered_at,
                   a.display_name as agent_name,
                   EXTRACT(EPOCH FROM (NOW() - c.started_at))::int as elapsed_seconds
            FROM om_callcenter_calls c
            LEFT JOIN om_callcenter_agents a ON a.id = c.agent_id
            WHERE c.status IN ('in_progress', 'on_hold', 'ai_handling', 'ringing', 'queued')
              AND c.started_at > NOW() - INTERVAL '4 hours'
            ORDER BY c.started_at DESC
        ");
        $activeCalls = $activeStmt->fetchAll();

        foreach ($activeCalls as &$ac) {
            $ac['id'] = (int)$ac['id'];
            $ac['agent_id'] = $ac['agent_id'] ? (int)$ac['agent_id'] : null;
            $ac['customer_id'] = $ac['customer_id'] ? (int)$ac['customer_id'] : null;
            $ac['elapsed_seconds'] = (int)$ac['elapsed_seconds'];
        }
        unset($ac);

        // Callbacks pending (calls with callback_requested but not completed)
        $cbStmt = $db->query("
            SELECT c.id, c.customer_phone, c.customer_name, c.customer_id,
                   c.store_identified, c.created_at,
                   EXTRACT(EPOCH FROM (NOW() - c.created_at))::int as wait_seconds
            FROM om_callcenter_calls c
            WHERE c.callback_requested = true
              AND c.callback_completed_at IS NULL
              AND c.created_at > NOW() - INTERVAL '24 hours'
            ORDER BY c.created_at ASC
        ");
        $callbacks = $cbStmt->fetchAll();
        foreach ($callbacks as &$cb) {
            $cb['id'] = (int)$cb['id'];
            $cb['customer_id'] = $cb['customer_id'] ? (int)$cb['customer_id'] : null;
            $cb['wait_seconds'] = (int)$cb['wait_seconds'];
        }
        unset($cb);

        response(true, [
            'queue' => $queue,
            'total' => count($queue),
            'agents' => $agentStats,
            'active_calls' => $activeCalls,
            'callbacks' => $callbacks,
        ]);
    }

    // ════════════════════════════════════════════════════════════════════
    // POST — Actions
    // ════════════════════════════════════════════════════════════════════
    if ($method === 'POST') {
        $input = getInput();
        $action = $input['action'] ?? '';

        // ── Pick next call from queue ───────────────────────────────────
        if ($action === 'pick') {
            if (!$agentId) response(false, null, 'Configure seu perfil de agente primeiro.', 403);
            $specificQueueId = (int)($input['queue_id'] ?? 0);

            $db->beginTransaction();
            try {
                // Pick specific or next in line
                if ($specificQueueId > 0) {
                    $stmt = $db->prepare("
                        SELECT q.id, q.call_id, q.customer_phone, q.customer_name
                        FROM om_callcenter_queue q
                        WHERE q.id = ? AND q.picked_at IS NULL AND q.abandoned_at IS NULL
                        FOR UPDATE SKIP LOCKED
                        LIMIT 1
                    ");
                    $stmt->execute([$specificQueueId]);
                } else {
                    $stmt = $db->query("
                        SELECT q.id, q.call_id, q.customer_phone, q.customer_name
                        FROM om_callcenter_queue q
                        WHERE q.picked_at IS NULL AND q.abandoned_at IS NULL
                        ORDER BY q.priority ASC, q.queued_at ASC
                        FOR UPDATE SKIP LOCKED
                        LIMIT 1
                    ");
                }
                $queueItem = $stmt->fetch();

                if (!$queueItem) {
                    $db->rollBack();
                    response(false, null, 'Fila vazia ou item ja atendido', 404);
                }

                $queueId = (int)$queueItem['id'];
                $callId = $queueItem['call_id'] ? (int)$queueItem['call_id'] : null;

                // Mark as picked
                $db->prepare("
                    UPDATE om_callcenter_queue SET picked_at = NOW(), picked_by = ? WHERE id = ?
                ")->execute([$agentId, $queueId]);

                // Update call record with agent
                if ($callId) {
                    $db->prepare("
                        UPDATE om_callcenter_calls
                        SET agent_id = ?, status = 'in_progress', answered_at = COALESCE(answered_at, NOW()),
                            wait_time_seconds = EXTRACT(EPOCH FROM (NOW() - started_at))::int
                        WHERE id = ?
                    ")->execute([$agentId, $callId]);
                }

                // Update agent status to busy
                $db->prepare("UPDATE om_callcenter_agents SET status = 'busy', updated_at = NOW() WHERE id = ?")
                   ->execute([$agentId]);

                $db->commit();

                // Broadcast
                ccBroadcastDashboard('queue_picked', [
                    'queue_id' => $queueId,
                    'call_id' => $callId,
                    'agent_id' => $agentId,
                    'agent_name' => $agentName,
                ]);

                error_log("[callcenter/queue] Agent {$agentId} picked queue_id={$queueId} call_id={$callId}");

                response(true, [
                    'queue_id' => $queueId,
                    'call_id' => $callId,
                    'customer_phone' => $queueItem['customer_phone'],
                    'customer_name' => $queueItem['customer_name'],
                ], 'Chamada atribuida');

            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
        }

        // ── Schedule callback ───────────────────────────────────────────
        if ($action === 'callback') {
            $queueId = (int)($input['queue_id'] ?? 0);
            $callId = (int)($input['call_id'] ?? 0);
            $callbackNumber = trim($input['callback_number'] ?? '');
            $estimatedMinutes = max(5, min(120, (int)($input['estimated_minutes'] ?? 15)));

            if ((!$queueId && !$callId) || empty($callbackNumber)) {
                response(false, null, 'queue_id ou call_id e callback_number obrigatorios', 400);
            }

            // Update queue entry
            if ($queueId) {
                $db->prepare("
                    UPDATE om_callcenter_queue
                    SET callback_number = ?, picked_at = NOW(), picked_by = ?
                    WHERE id = ?
                ")->execute([$callbackNumber, $agentId, $queueId]);
            }

            // Update call as callback
            if ($callId) {
                $db->prepare("
                    UPDATE om_callcenter_calls
                    SET status = 'callback', callback_requested = true, agent_id = ?
                    WHERE id = ?
                ")->execute([$agentId, $callId]);
            }

            // Send SMS notification
            $smsResult = sendCallbackNotice($callbackNumber, $estimatedMinutes);

            ccBroadcastDashboard('callback_scheduled', [
                'queue_id' => $queueId,
                'call_id' => $callId,
                'callback_number' => $callbackNumber,
                'agent_id' => $agentId,
                'estimated_minutes' => $estimatedMinutes,
            ]);

            error_log("[callcenter/queue] Callback scheduled: {$callbackNumber} by agent {$agentId}");

            response(true, [
                'callback_number' => $callbackNumber,
                'estimated_minutes' => $estimatedMinutes,
                'sms_sent' => $smsResult['success'] ?? false,
            ], 'Callback agendado');
        }

        // ── Transfer call to another agent ───────────────────────────────
        if ($action === 'transfer') {
            if (!$agentId) response(false, null, 'Configure seu perfil de agente primeiro.', 403);
            $callId = (int)($input['call_id'] ?? 0);
            $targetAgentId = (int)($input['target_agent_id'] ?? 0);

            if (!$callId || !$targetAgentId) {
                response(false, null, 'call_id e target_agent_id obrigatorios', 400);
            }

            // Verify call exists and belongs to current agent
            $callStmt = $db->prepare("SELECT id, customer_phone, customer_name, twilio_call_sid FROM om_callcenter_calls WHERE id = ? AND agent_id = ?");
            $callStmt->execute([$callId, $agentId]);
            $call = $callStmt->fetch();

            if (!$call) {
                response(false, null, 'Chamada nao encontrada ou nao pertence a voce', 404);
            }

            // Verify target agent exists and is online
            $targetStmt = $db->prepare("SELECT id, display_name, status FROM om_callcenter_agents WHERE id = ?");
            $targetStmt->execute([$targetAgentId]);
            $targetAgent = $targetStmt->fetch();

            if (!$targetAgent) {
                response(false, null, 'Agente destino nao encontrado', 404);
            }
            if ($targetAgent['status'] === 'offline') {
                response(false, null, 'Agente destino esta offline', 400);
            }

            $db->beginTransaction();
            try {
                // Transfer: update call to new agent
                $db->prepare("
                    UPDATE om_callcenter_calls SET agent_id = ?, notes = COALESCE(notes, '') || E'\\n[Transferido de ' || ? || ' para ' || ? || ' em ' || NOW()::text || ']'
                    WHERE id = ?
                ")->execute([$targetAgentId, $agentName, $targetAgent['display_name'], $callId]);

                // Set current agent back to online
                $db->prepare("UPDATE om_callcenter_agents SET status = 'online', updated_at = NOW() WHERE id = ?")
                   ->execute([$agentId]);

                // Set target agent to busy
                $db->prepare("UPDATE om_callcenter_agents SET status = 'busy', updated_at = NOW() WHERE id = ?")
                   ->execute([$targetAgentId]);

                $db->commit();

                ccBroadcastDashboard('call_transferred', [
                    'call_id' => $callId,
                    'from_agent_id' => $agentId,
                    'from_agent_name' => $agentName,
                    'to_agent_id' => $targetAgentId,
                    'to_agent_name' => $targetAgent['display_name'],
                ]);

                error_log("[callcenter/queue] Call {$callId} transferred from agent {$agentId} to {$targetAgentId}");

                response(true, [
                    'call_id' => $callId,
                    'transferred_to' => $targetAgent['display_name'],
                ], 'Chamada transferida para ' . $targetAgent['display_name']);
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
        }

        // ── Put call on hold ─────────────────────────────────────────────
        if ($action === 'hold') {
            if (!$agentId) response(false, null, 'Configure seu perfil de agente primeiro.', 403);
            $callId = (int)($input['call_id'] ?? 0);

            if (!$callId) response(false, null, 'call_id obrigatorio', 400);

            $db->prepare("UPDATE om_callcenter_calls SET status = 'on_hold' WHERE id = ? AND agent_id = ?")
               ->execute([$callId, $agentId]);

            ccBroadcastDashboard('call_hold', ['call_id' => $callId, 'agent_id' => $agentId]);

            response(true, ['call_id' => $callId], 'Chamada em espera');
        }

        // ── Resume call from hold ────────────────────────────────────────
        if ($action === 'resume') {
            if (!$agentId) response(false, null, 'Configure seu perfil de agente primeiro.', 403);
            $callId = (int)($input['call_id'] ?? 0);

            if (!$callId) response(false, null, 'call_id obrigatorio', 400);

            $db->prepare("UPDATE om_callcenter_calls SET status = 'in_progress' WHERE id = ? AND agent_id = ?")
               ->execute([$callId, $agentId]);

            ccBroadcastDashboard('call_resumed', ['call_id' => $callId, 'agent_id' => $agentId]);

            response(true, ['call_id' => $callId], 'Chamada retomada');
        }

        response(false, null, 'Acao invalida', 400);
    }

    response(false, null, 'Method not allowed', 405);

} catch (Exception $e) {
    error_log("[callcenter/queue] Error: " . $e->getMessage());
    response(false, null, 'Erro interno', 500);
}
