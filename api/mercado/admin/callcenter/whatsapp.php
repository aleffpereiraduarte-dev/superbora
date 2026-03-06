<?php
/**
 * /api/mercado/admin/callcenter/whatsapp.php
 * WhatsApp conversation management for call center
 *
 * GET                  — List conversations with filters
 * GET ?id=X            — Get conversation messages
 * POST action=send     — Send message via Z-API
 * POST action=assign   — Assign conversation to current agent
 * POST action=close    — Close conversation
 */

require_once __DIR__ . '/../../config/database.php';
require_once dirname(__DIR__, 4) . '/includes/classes/OmAuth.php';
require_once dirname(__DIR__, 4) . '/includes/classes/OmAudit.php';
require_once __DIR__ . '/../../helpers/zapi-whatsapp.php';
require_once __DIR__ . '/../../helpers/ws-callcenter-broadcast.php';

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();
    $adminId = (int)$payload['uid'];

    // Get agent
    $agentStmt = $db->prepare("SELECT id, display_name FROM om_callcenter_agents WHERE admin_id = ? LIMIT 1");
    $agentStmt->execute([$adminId]);
    $agent = $agentStmt->fetch();
    $agentId = $agent ? (int)$agent['id'] : null;
    $agentName = $agent ? ($agent['display_name'] ?? 'Admin') : 'Admin';

    $method = $_SERVER['REQUEST_METHOD'];

    // ════════════════════════════════════════════════════════════════════
    // GET — List conversations or get messages
    // ════════════════════════════════════════════════════════════════════
    if ($method === 'GET') {

        // Get messages for a specific conversation
        if (isset($_GET['id'])) {
            $convId = (int)$_GET['id'];
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));
            $offset = ($page - 1) * $limit;

            // Get conversation info
            $convStmt = $db->prepare("
                SELECT w.*, a.display_name as agent_name
                FROM om_callcenter_whatsapp w
                LEFT JOIN om_callcenter_agents a ON a.id = w.agent_id
                WHERE w.id = ?
            ");
            $convStmt->execute([$convId]);
            $conversation = $convStmt->fetch();

            if (!$conversation) {
                response(false, null, 'Conversa nao encontrada', 404);
            }

            // Get messages
            $msgStmt = $db->prepare("
                SELECT id, direction, sender_type, message, message_type, media_url, ai_suggested, created_at
                FROM om_callcenter_wa_messages
                WHERE conversation_id = ?
                ORDER BY created_at ASC
                LIMIT ? OFFSET ?
            ");
            $msgStmt->execute([$convId, $limit, $offset]);
            $messages = $msgStmt->fetchAll();

            foreach ($messages as &$msg) {
                $msg['id'] = (int)$msg['id'];
                $msg['ai_suggested'] = (bool)$msg['ai_suggested'];
            }
            unset($msg);

            // Count total messages
            $countStmt = $db->prepare("SELECT COUNT(*) as total FROM om_callcenter_wa_messages WHERE conversation_id = ?");
            $countStmt->execute([$convId]);
            $totalMessages = (int)$countStmt->fetch()['total'];

            // Mark as read if assigned to this agent
            if ($agentId && (int)($conversation['agent_id'] ?? 0) === $agentId) {
                $db->prepare("UPDATE om_callcenter_whatsapp SET unread_count = 0 WHERE id = ?")->execute([$convId]);
            }

            $conversation['id'] = (int)$conversation['id'];
            $conversation['customer_id'] = $conversation['customer_id'] ? (int)$conversation['customer_id'] : null;
            $conversation['agent_id'] = $conversation['agent_id'] ? (int)$conversation['agent_id'] : null;
            $conversation['unread_count'] = (int)$conversation['unread_count'];

            response(true, [
                'conversation' => $conversation,
                'messages' => $messages,
                'total_messages' => $totalMessages,
                'page' => $page,
                'limit' => $limit,
            ]);
        }

        // List conversations
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 30)));
        $offset = ($page - 1) * $limit;

        $conditions = [];
        $params = [];

        // Status filter
        if (!empty($_GET['status'])) {
            $allowed = ['bot', 'waiting', 'assigned', 'closed'];
            if (in_array($_GET['status'], $allowed, true)) {
                $conditions[] = "w.status = ?";
                $params[] = $_GET['status'];
            }
        }

        // Agent filter
        if (!empty($_GET['agent_id'])) {
            $conditions[] = "w.agent_id = ?";
            $params[] = (int)$_GET['agent_id'];
        }

        // My conversations only
        if (!empty($_GET['mine']) && $agentId) {
            $conditions[] = "w.agent_id = ?";
            $params[] = $agentId;
        }

        // Search
        if (!empty($_GET['search'])) {
            $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $_GET['search']);
            $conditions[] = "(w.phone ILIKE ? OR w.customer_name ILIKE ?)";
            $params[] = '%' . $escaped . '%';
            $params[] = '%' . $escaped . '%';
        }

        // Unread only
        if (!empty($_GET['unread'])) {
            $conditions[] = "w.unread_count > 0";
        }

        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        // Count total
        $countStmt = $db->prepare("SELECT COUNT(*) as total FROM om_callcenter_whatsapp w {$where}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetch()['total'];

        // Fetch conversations with last message preview
        $listParams = array_merge($params, [$limit, $offset]);
        $sql = "
            SELECT w.id, w.phone, w.customer_id, w.customer_name, w.agent_id,
                   w.status, w.unread_count, w.last_message_at, w.created_at,
                   a.display_name as agent_name,
                   (SELECT message FROM om_callcenter_wa_messages WHERE conversation_id = w.id ORDER BY created_at DESC LIMIT 1) as last_message
            FROM om_callcenter_whatsapp w
            LEFT JOIN om_callcenter_agents a ON a.id = w.agent_id
            {$where}
            ORDER BY
                CASE WHEN w.status = 'waiting' THEN 0 WHEN w.status = 'bot' THEN 1 WHEN w.status = 'assigned' THEN 2 ELSE 3 END,
                w.last_message_at DESC NULLS LAST
            LIMIT ? OFFSET ?
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute($listParams);
        $conversations = $stmt->fetchAll();

        foreach ($conversations as &$conv) {
            $conv['id'] = (int)$conv['id'];
            $conv['customer_id'] = $conv['customer_id'] ? (int)$conv['customer_id'] : null;
            $conv['agent_id'] = $conv['agent_id'] ? (int)$conv['agent_id'] : null;
            $conv['unread_count'] = (int)$conv['unread_count'];
            $conv['last_message'] = $conv['last_message'] ? mb_substr($conv['last_message'], 0, 100) : null;
        }
        unset($conv);

        response(true, [
            'conversations' => $conversations,
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

        if (!$agentId) {
            response(false, null, 'Agente nao encontrado. Configure seu perfil primeiro.', 403);
        }

        // ── Send Message ────────────────────────────────────────────────
        if ($action === 'send') {
            $convId = (int)($input['conversation_id'] ?? 0);
            $message = trim($input['message'] ?? '');

            if (!$convId || empty($message)) {
                response(false, null, 'conversation_id e message obrigatorios', 400);
            }

            // Get conversation phone
            $convStmt = $db->prepare("SELECT id, phone, status FROM om_callcenter_whatsapp WHERE id = ?");
            $convStmt->execute([$convId]);
            $conv = $convStmt->fetch();

            if (!$conv) {
                response(false, null, 'Conversa nao encontrada', 404);
            }

            // Send via Z-API
            $result = sendWhatsApp($conv['phone'], $message);

            if (!$result['success']) {
                response(false, null, 'Falha ao enviar: ' . ($result['message'] ?? 'erro desconhecido'), 502);
            }

            // Store outbound message
            $db->prepare("
                INSERT INTO om_callcenter_wa_messages
                    (conversation_id, direction, sender_type, message, message_type)
                VALUES (?, 'outbound', 'agent', ?, 'text')
            ")->execute([$convId, $message]);

            // Update conversation
            $db->prepare("
                UPDATE om_callcenter_whatsapp
                SET last_message_at = NOW(),
                    status = CASE WHEN status = 'waiting' THEN 'assigned' ELSE status END,
                    agent_id = COALESCE(agent_id, ?)
                WHERE id = ?
            ")->execute([$agentId, $convId]);

            // Broadcast
            ccBroadcastDashboard('whatsapp_sent', [
                'conversation_id' => $convId,
                'agent_id' => $agentId,
                'message' => mb_substr($message, 0, 100),
            ]);

            response(true, null, 'Mensagem enviada');
        }

        // ── Assign Conversation ─────────────────────────────────────────
        if ($action === 'assign') {
            $convId = (int)($input['conversation_id'] ?? 0);

            if (!$convId) {
                response(false, null, 'conversation_id obrigatorio', 400);
            }

            $stmt = $db->prepare("
                UPDATE om_callcenter_whatsapp
                SET agent_id = ?, status = 'assigned', unread_count = 0
                WHERE id = ?
                RETURNING id, phone, customer_name
            ");
            $stmt->execute([$agentId, $convId]);
            $updated = $stmt->fetch();

            if (!$updated) {
                response(false, null, 'Conversa nao encontrada', 404);
            }

            ccBroadcastDashboard('whatsapp_assigned', [
                'conversation_id' => $convId,
                'agent_id' => $agentId,
                'agent_name' => $agentName,
            ]);

            error_log("[callcenter/whatsapp] Conversation {$convId} assigned to agent {$agentId}");

            response(true, ['conversation_id' => $convId, 'agent_id' => $agentId], 'Conversa atribuida');
        }

        // ── Close Conversation ──────────────────────────────────────────
        if ($action === 'close') {
            $convId = (int)($input['conversation_id'] ?? 0);

            if (!$convId) {
                response(false, null, 'conversation_id obrigatorio', 400);
            }

            $stmt = $db->prepare("
                UPDATE om_callcenter_whatsapp
                SET status = 'closed'
                WHERE id = ?
                RETURNING id
            ");
            $stmt->execute([$convId]);
            $updated = $stmt->fetch();

            if (!$updated) {
                response(false, null, 'Conversa nao encontrada', 404);
            }

            ccBroadcastDashboard('whatsapp_closed', [
                'conversation_id' => $convId,
                'agent_id' => $agentId,
            ]);

            error_log("[callcenter/whatsapp] Conversation {$convId} closed by agent {$agentId}");

            response(true, ['conversation_id' => $convId], 'Conversa encerrada');
        }

        response(false, null, 'Acao invalida', 400);
    }

    response(false, null, 'Method not allowed', 405);

} catch (Exception $e) {
    error_log("[callcenter/whatsapp] Error: " . $e->getMessage());
    response(false, null, 'Erro interno', 500);
}
