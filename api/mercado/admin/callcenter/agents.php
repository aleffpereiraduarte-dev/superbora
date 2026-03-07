<?php
/**
 * /api/mercado/admin/callcenter/agents.php
 *
 * Call Center Agent Management
 *
 * GET: List all agents with today's stats (calls, orders).
 * POST action='update_status': Update own agent status (online/busy/break/offline).
 * POST action='create': Create new agent profile.
 * POST action='update': Update existing agent profile.
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
    $admin_id = (int)$payload['uid'];

    // =================== GET: List agents with stats ===================
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {

        $today = date('Y-m-d');

        $stmt = $db->prepare("
            SELECT
                a.id, a.admin_id, a.display_name, a.extension, a.status,
                a.skills, a.max_concurrent, a.avatar_url,
                a.created_at, a.updated_at,
                COALESCE(calls_today.cnt, 0) AS calls_today,
                COALESCE(orders_today.cnt, 0) AS orders_today,
                COALESCE(active_calls.cnt, 0) AS active_calls,
                COALESCE(active_drafts.cnt, 0) AS active_drafts
            FROM om_callcenter_agents a
            LEFT JOIN (
                SELECT agent_id, COUNT(*) AS cnt
                FROM om_callcenter_calls
                WHERE created_at::date = ?
                GROUP BY agent_id
            ) calls_today ON calls_today.agent_id = a.id
            LEFT JOIN (
                SELECT agent_id, COUNT(*) AS cnt
                FROM om_callcenter_order_drafts
                WHERE status = 'submitted' AND created_at::date = ?
                GROUP BY agent_id
            ) orders_today ON orders_today.agent_id = a.id
            LEFT JOIN (
                SELECT agent_id, COUNT(*) AS cnt
                FROM om_callcenter_calls
                WHERE status IN ('in_progress', 'ai_handling', 'on_hold')
                GROUP BY agent_id
            ) active_calls ON active_calls.agent_id = a.id
            LEFT JOIN (
                SELECT agent_id, COUNT(*) AS cnt
                FROM om_callcenter_order_drafts
                WHERE status IN ('building', 'review', 'awaiting_payment')
                GROUP BY agent_id
            ) active_drafts ON active_drafts.agent_id = a.id
            ORDER BY
                CASE a.status
                    WHEN 'online' THEN 1
                    WHEN 'busy' THEN 2
                    WHEN 'break' THEN 3
                    WHEN 'offline' THEN 4
                END,
                a.display_name ASC
        ");
        $stmt->execute([$today, $today]);
        $agents = $stmt->fetchAll();

        // Parse skills array from PostgreSQL format
        foreach ($agents as &$agent) {
            $agent['id'] = (int)$agent['id'];
            $agent['admin_id'] = (int)$agent['admin_id'];
            $agent['max_concurrent'] = (int)$agent['max_concurrent'];
            $agent['calls_today'] = (int)$agent['calls_today'];
            $agent['orders_today'] = (int)$agent['orders_today'];
            $agent['active_calls'] = (int)$agent['active_calls'];
            $agent['active_drafts'] = (int)$agent['active_drafts'];
            // PostgreSQL TEXT[] comes as {val1,val2} string
            if (is_string($agent['skills'])) {
                $agent['skills'] = array_filter(
                    explode(',', trim($agent['skills'], '{}'))
                );
            }
        }
        unset($agent);

        response(true, ['agents' => $agents, 'current_admin_id' => $admin_id]);
    }

    // =================== POST: Agent actions ===================
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        $input = getInput();
        $action = trim($input['action'] ?? '');

        // ── Update status ──
        if ($action === 'update_status') {
            $newStatus = trim($input['status'] ?? '');
            $validStatuses = ['online', 'busy', 'break', 'offline'];
            if (!in_array($newStatus, $validStatuses, true)) {
                response(false, null, "Status invalido. Valores: " . implode(', ', $validStatuses), 400);
            }

            // If agent_id is provided, update that agent (admin managing others)
            // Otherwise, find agent by current admin_id (self-update)
            $targetAgentId = (int)($input['agent_id'] ?? 0);

            if ($targetAgentId) {
                $stmt = $db->prepare("SELECT id, display_name FROM om_callcenter_agents WHERE id = ?");
                $stmt->execute([$targetAgentId]);
            } else {
                $stmt = $db->prepare("SELECT id, display_name FROM om_callcenter_agents WHERE admin_id = ?");
                $stmt->execute([$admin_id]);
            }
            $agent = $stmt->fetch();

            if (!$agent) {
                response(false, null, "Agente nao encontrado", 404);
            }

            $stmt = $db->prepare("
                UPDATE om_callcenter_agents
                SET status = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$newStatus, $agent['id']]);

            response(true, [
                'agent_id' => (int)$agent['id'],
                'status' => $newStatus,
            ], "Status atualizado para '{$newStatus}'");
        }

        // ── Create new agent ──
        if ($action === 'create') {
            $agentAdminId = (int)($input['admin_id'] ?? $admin_id);
            $displayName = strip_tags(trim($input['display_name'] ?? ''));
            $extension = strip_tags(trim($input['extension'] ?? ''));
            $skills = $input['skills'] ?? [];
            $maxConcurrent = max(1, min(10, (int)($input['max_concurrent'] ?? 3)));

            if (!$agentAdminId) response(false, null, "admin_id obrigatorio", 400);
            if (!$displayName) response(false, null, "display_name obrigatorio", 400);

            // Check if agent already exists for this admin
            $stmt = $db->prepare("SELECT id FROM om_callcenter_agents WHERE admin_id = ?");
            $stmt->execute([$agentAdminId]);
            if ($stmt->fetch()) {
                response(false, null, "Ja existe um agente para este admin_id", 409);
            }

            // Convert skills array to PostgreSQL TEXT[]
            $skillsPg = '{' . implode(',', array_map(function ($s) {
                return '"' . str_replace('"', '\\"', strip_tags(trim($s))) . '"';
            }, is_array($skills) ? $skills : [])) . '}';

            $stmt = $db->prepare("
                INSERT INTO om_callcenter_agents (admin_id, display_name, extension, skills, max_concurrent, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                RETURNING id
            ");
            $stmt->execute([$agentAdminId, $displayName, $extension, $skillsPg, $maxConcurrent]);
            $row = $stmt->fetch();

            om_audit()->log(
                'callcenter_agent_create',
                'callcenter_agent',
                (int)$row['id'],
                null,
                ['admin_id' => $agentAdminId, 'display_name' => $displayName],
                "Agente '{$displayName}' criado para admin #{$agentAdminId}"
            );

            response(true, [
                'id' => (int)$row['id'],
                'admin_id' => $agentAdminId,
                'display_name' => $displayName,
                'extension' => $extension,
            ], "Agente criado com sucesso");
        }

        // ── Update agent ──
        if ($action === 'update') {
            $agentId = (int)($input['agent_id'] ?? $input['id'] ?? 0);
            if (!$agentId) response(false, null, "id do agente obrigatorio", 400);

            // Verify agent exists
            $stmt = $db->prepare("SELECT id, display_name FROM om_callcenter_agents WHERE id = ?");
            $stmt->execute([$agentId]);
            $agent = $stmt->fetch();
            if (!$agent) response(false, null, "Agente nao encontrado", 404);

            $updates = [];
            $params = [];

            if (isset($input['display_name']) && trim($input['display_name']) !== '') {
                $updates[] = "display_name = ?";
                $params[] = strip_tags(trim($input['display_name']));
            }
            if (isset($input['extension'])) {
                $updates[] = "extension = ?";
                $params[] = strip_tags(trim($input['extension']));
            }
            if (isset($input['skills']) && is_array($input['skills'])) {
                $skillsPg = '{' . implode(',', array_map(function ($s) {
                    return '"' . str_replace('"', '\\"', strip_tags(trim($s))) . '"';
                }, $input['skills'])) . '}';
                $updates[] = "skills = ?";
                $params[] = $skillsPg;
            }
            if (isset($input['max_concurrent'])) {
                $updates[] = "max_concurrent = ?";
                $params[] = max(1, min(10, (int)$input['max_concurrent']));
            }

            if (empty($updates)) {
                response(false, null, "Nenhum campo para atualizar", 400);
            }

            $updates[] = "updated_at = NOW()";
            $params[] = $agentId;

            $sql = "UPDATE om_callcenter_agents SET " . implode(', ', $updates) . " WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);

            om_audit()->log(
                'callcenter_agent_update',
                'callcenter_agent',
                $agentId,
                null,
                array_intersect_key($input, array_flip(['display_name', 'extension', 'skills', 'max_concurrent'])),
                "Agente #{$agentId} atualizado"
            );

            response(true, ['id' => $agentId], "Agente atualizado com sucesso");
        }

        response(false, null, "Acao invalida. Valores: update_status, create, update", 400);
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[admin/callcenter/agents] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
