<?php
/**
 * /api/mercado/admin/callcenter/calls.php
 * Call history management
 *
 * GET             — List calls with filters
 * GET ?id=X       — Single call detail
 * POST action=add_note  — Add note to call
 * POST action=rate      — Rate call (CSAT 1-5)
 */

require_once __DIR__ . '/../../config/database.php';
require_once dirname(__DIR__, 4) . '/includes/classes/OmAuth.php';
require_once dirname(__DIR__, 4) . '/includes/classes/OmAudit.php';

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();
    $adminId = (int)$payload['uid'];

    // Get agent ID for this admin
    $agentStmt = $db->prepare("SELECT id FROM om_callcenter_agents WHERE admin_id = ? LIMIT 1");
    $agentStmt->execute([$adminId]);
    $agentRow = $agentStmt->fetch();
    $agentId = $agentRow ? (int)$agentRow['id'] : null;

    $method = $_SERVER['REQUEST_METHOD'];

    // ════════════════════════════════════════════════════════════════════
    // GET — List or detail
    // ════════════════════════════════════════════════════════════════════
    if ($method === 'GET') {
        // Single call detail
        if (isset($_GET['id'])) {
            $callId = (int)$_GET['id'];
            $stmt = $db->prepare("
                SELECT c.*,
                       a.display_name as agent_name,
                       (SELECT COUNT(*) FROM om_callcenter_order_drafts d WHERE d.call_id = c.id) as draft_count
                FROM om_callcenter_calls c
                LEFT JOIN om_callcenter_agents a ON a.id = c.agent_id
                WHERE c.id = ?
            ");
            $stmt->execute([$callId]);
            $call = $stmt->fetch();

            if (!$call) {
                response(false, null, 'Chamada nao encontrada', 404);
            }

            // Cast types
            $call['id'] = (int)$call['id'];
            $call['customer_id'] = $call['customer_id'] ? (int)$call['customer_id'] : null;
            $call['agent_id'] = $call['agent_id'] ? (int)$call['agent_id'] : null;
            $call['duration_seconds'] = $call['duration_seconds'] ? (int)$call['duration_seconds'] : null;
            $call['recording_duration'] = $call['recording_duration'] ? (int)$call['recording_duration'] : null;
            $call['order_id'] = $call['order_id'] ? (int)$call['order_id'] : null;
            $call['wait_time_seconds'] = $call['wait_time_seconds'] ? (int)$call['wait_time_seconds'] : null;
            $call['draft_count'] = (int)$call['draft_count'];

            response(true, $call);
        }

        // List with filters
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 30)));
        $offset = ($page - 1) * $limit;

        $conditions = [];
        $params = [];

        // Date range
        if (!empty($_GET['date_from'])) {
            $conditions[] = "c.created_at >= ?::timestamptz";
            $params[] = $_GET['date_from'];
        }
        if (!empty($_GET['date_to'])) {
            $conditions[] = "c.created_at <= ?::timestamptz";
            $params[] = $_GET['date_to'] . ' 23:59:59';
        }

        // Agent filter
        if (!empty($_GET['agent_id'])) {
            $conditions[] = "c.agent_id = ?";
            $params[] = (int)$_GET['agent_id'];
        }

        // Status filter
        if (!empty($_GET['status'])) {
            $allowed = ['queued', 'ringing', 'ai_handling', 'in_progress', 'on_hold', 'completed', 'missed', 'voicemail', 'callback'];
            if (in_array($_GET['status'], $allowed, true)) {
                $conditions[] = "c.status = ?";
                $params[] = $_GET['status'];
            }
        }

        // Direction filter
        if (!empty($_GET['direction'])) {
            $allowed = ['inbound', 'outbound'];
            if (in_array($_GET['direction'], $allowed, true)) {
                $conditions[] = "c.direction = ?";
                $params[] = $_GET['direction'];
            }
        }

        // Search by protocol code (exact match)
        if (!empty($_GET['protocol'])) {
            $conditions[] = "c.protocol_code = ?";
            $params[] = strtoupper(trim($_GET['protocol']));
        }

        // Search by phone, name, or protocol
        if (!empty($_GET['search'])) {
            $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $_GET['search']);
            $conditions[] = "(c.customer_phone ILIKE ? OR c.customer_name ILIKE ? OR c.protocol_code ILIKE ?)";
            $searchParam = '%' . $escaped . '%';
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
        }

        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        // Count total
        $countSql = "SELECT COUNT(*) as total FROM om_callcenter_calls c {$where}";
        $countStmt = $db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetch()['total'];

        // Fetch calls
        $params[] = $limit;
        $params[] = $offset;
        $sql = "
            SELECT c.id, c.twilio_call_sid, c.protocol_code, c.customer_phone, c.customer_name, c.customer_id,
                   c.agent_id, c.direction, c.status, c.duration_seconds, c.recording_url,
                   c.ai_summary, c.ai_sentiment, c.notes, c.order_id, c.store_identified,
                   c.callback_requested, c.wait_time_seconds, c.started_at, c.answered_at, c.ended_at,
                   a.display_name as agent_name
            FROM om_callcenter_calls c
            LEFT JOIN om_callcenter_agents a ON a.id = c.agent_id
            {$where}
            ORDER BY c.created_at DESC
            LIMIT ? OFFSET ?
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $calls = $stmt->fetchAll();

        // Cast types
        foreach ($calls as &$call) {
            $call['id'] = (int)$call['id'];
            $call['customer_id'] = $call['customer_id'] ? (int)$call['customer_id'] : null;
            $call['agent_id'] = $call['agent_id'] ? (int)$call['agent_id'] : null;
            $call['duration_seconds'] = $call['duration_seconds'] ? (int)$call['duration_seconds'] : null;
            $call['order_id'] = $call['order_id'] ? (int)$call['order_id'] : null;
            $call['wait_time_seconds'] = $call['wait_time_seconds'] ? (int)$call['wait_time_seconds'] : null;
            $call['callback_requested'] = (bool)$call['callback_requested'];
        }
        unset($call);

        response(true, [
            'calls' => $calls,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => ceil($total / $limit),
        ]);
    }

    // ════════════════════════════════════════════════════════════════════
    // POST — Actions
    // ════════════════════════════════════════════════════════════════════
    if ($method === 'POST') {
        $input = getInput();
        $action = $input['action'] ?? '';

        // ── Add Note ────────────────────────────────────────────────────
        if ($action === 'add_note') {
            $callId = (int)($input['call_id'] ?? 0);
            $note = trim($input['note'] ?? '');

            if (!$callId || empty($note)) {
                response(false, null, 'call_id e note obrigatorios', 400);
            }

            // Append note with timestamp and agent name
            $agentName = $agentRow ? $agentRow['display_name'] ?? "Admin #{$adminId}" : "Admin #{$adminId}";
            // Fetch current agent name
            if ($agentId) {
                $nameStmt = $db->prepare("SELECT display_name FROM om_callcenter_agents WHERE id = ?");
                $nameStmt->execute([$agentId]);
                $nameRow = $nameStmt->fetch();
                if ($nameRow) $agentName = $nameRow['display_name'];
            }

            $timestamp = date('d/m/Y H:i');
            $noteEntry = "[{$timestamp} - {$agentName}] {$note}";

            $stmt = $db->prepare("
                UPDATE om_callcenter_calls
                SET notes = CASE
                    WHEN notes IS NULL OR notes = '' THEN ?
                    ELSE notes || E'\\n' || ?
                END
                WHERE id = ?
                RETURNING id
            ");
            $stmt->execute([$noteEntry, $noteEntry, $callId]);
            $updated = $stmt->fetch();

            if (!$updated) {
                response(false, null, 'Chamada nao encontrada', 404);
            }

            response(true, ['call_id' => $callId], 'Nota adicionada');
        }

        // ── Rate Call (CSAT) ────────────────────────────────────────────
        if ($action === 'rate') {
            $callId = (int)($input['call_id'] ?? 0);
            $csat = (int)($input['csat'] ?? 0);

            if (!$callId || $csat < 1 || $csat > 5) {
                response(false, null, 'call_id e csat (1-5) obrigatorios', 400);
            }

            // Get the call to find its agent and date for metrics
            $callStmt = $db->prepare("SELECT agent_id, DATE(created_at) as call_date FROM om_callcenter_calls WHERE id = ?");
            $callStmt->execute([$callId]);
            $callData = $callStmt->fetch();

            if (!$callData) {
                response(false, null, 'Chamada nao encontrada', 404);
            }

            // Update metrics
            if ($callData['agent_id']) {
                $db->prepare("
                    INSERT INTO om_callcenter_metrics (date, agent_id, csat_sum, csat_count)
                    VALUES (?, ?, ?, 1)
                    ON CONFLICT (date, agent_id)
                    DO UPDATE SET csat_sum = om_callcenter_metrics.csat_sum + ?,
                                 csat_count = om_callcenter_metrics.csat_count + 1
                ")->execute([$callData['call_date'], (int)$callData['agent_id'], $csat, $csat]);
            }

            response(true, ['call_id' => $callId, 'csat' => $csat], 'Avaliacao registrada');
        }

        response(false, null, 'Acao invalida', 400);
    }

    response(false, null, 'Method not allowed', 405);

} catch (Exception $e) {
    error_log("[callcenter/calls] Error: " . $e->getMessage());
    response(false, null, 'Erro interno', 500);
}
