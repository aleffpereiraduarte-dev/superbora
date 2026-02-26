<?php
/**
 * GET/POST /api/mercado/admin/ai-tickets.php
 * Admin visibility into AI support conversations (Claude chat)
 * Tables: om_ai_support_tickets, om_ai_support_messages
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();
    $adminId = (int)$payload['uid'];
    $adminName = sanitizeOutput($payload['name'] ?? 'Admin');

    $method = $_SERVER['REQUEST_METHOD'];

    // Check if AI tables exist (filter by current database schema to avoid cross-schema matches)
    $tableCheck = $db->prepare("
        SELECT EXISTS (
            SELECT 1 FROM information_schema.tables
            WHERE table_schema = current_schema() AND table_name = ?
        )
    ");
    $tableCheck->execute(['om_ai_support_tickets']);
    if (!$tableCheck->fetchColumn()) {
        response(true, [
            'tickets' => [],
            'stats' => ['total' => 0, 'open' => 0, 'escalated' => 0, 'closed' => 0, 'avg_confidence' => 0],
            'pagination' => ['page' => 1, 'limit' => 20, 'total' => 0, 'pages' => 0],
            'message' => 'Tabela om_ai_support_tickets nao existe ainda'
        ]);
        // response() calls exit, but explicit return for static analysis clarity
    }

    // GET
    if ($method === 'GET') {
        $view = $_GET['view'] ?? 'list';
        $ticketId = (int)($_GET['id'] ?? 0);

        // Stats
        if ($view === 'stats') {
            $stmt = $db->query("
                SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open,
                    SUM(CASE WHEN status = 'escalated' THEN 1 ELSE 0 END) as escalated,
                    SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed,
                    ROUND(AVG(COALESCE(ai_confidence, 0))::numeric, 2) as avg_confidence
                FROM om_ai_support_tickets
            ");
            $stats = $stmt->fetch();

            // Per day (last 14 days)
            $stmtChart = $db->query("
                SELECT DATE(created_at) as dia, COUNT(*) as total,
                    SUM(CASE WHEN status = 'escalated' THEN 1 ELSE 0 END) as escalated
                FROM om_ai_support_tickets
                WHERE created_at > NOW() - INTERVAL '14 days'
                GROUP BY DATE(created_at)
                ORDER BY dia
            ");
            $stats['chart'] = $stmtChart->fetchAll();

            // Top intents
            $stmtIntents = $db->query("
                SELECT COALESCE(category, 'general') as intent, COUNT(*) as total
                FROM om_ai_support_tickets
                WHERE created_at > NOW() - INTERVAL '30 days'
                GROUP BY category
                ORDER BY total DESC
                LIMIT 8
            ");
            $stats['top_intents'] = $stmtIntents->fetchAll();

            response(true, ['stats' => $stats]);
        }

        // Detail
        if ($ticketId) {
            $stmt = $db->prepare("
                SELECT t.ticket_id, t.customer_id, t.order_id, t.status, t.category,
                    t.priority, t.ai_confidence, t.resolved_by, t.created_at, t.updated_at, t.closed_at,
                    c.name as customer_name, c.email as customer_email, c.phone as customer_phone
                FROM om_ai_support_tickets t
                LEFT JOIN om_market_customers c ON c.customer_id = t.customer_id
                WHERE t.ticket_id = ?
            ");
            $stmt->execute([$ticketId]);
            $ticket = $stmt->fetch();
            if (!$ticket) {
                response(false, null, "Conversa IA nao encontrada", 404);
            }

            // Messages
            $stmtMsg = $db->prepare("
                SELECT message_id, ticket_id, role, content, metadata, created_at
                FROM om_ai_support_messages
                WHERE ticket_id = ?
                ORDER BY created_at ASC
            ");
            $stmtMsg->execute([$ticketId]);
            $messages = $stmtMsg->fetchAll();

            // Order info if linked
            $order = null;
            if (!empty($ticket['order_id'])) {
                $stmtOrd = $db->prepare("
                    SELECT o.order_id, o.status, o.total, o.date_added,
                        p.name as partner_name
                    FROM om_market_orders o
                    LEFT JOIN om_market_partners p ON p.partner_id = o.partner_id
                    WHERE o.order_id = ?
                ");
                $stmtOrd->execute([$ticket['order_id']]);
                $order = $stmtOrd->fetch();
            }

            response(true, [
                'ticket' => $ticket,
                'messages' => $messages,
                'order' => $order
            ]);
        }

        // List
        $validStatuses = ['open', 'escalated', 'closed'];
        $status = $_GET['status'] ?? null;
        if ($status && !in_array($status, $validStatuses, true)) {
            response(false, null, "Status invalido. Valores aceitos: " . implode(', ', $validStatuses), 400);
        }

        $search = trim(mb_substr($_GET['search'] ?? '', 0, 200));
        $period = $_GET['period'] ?? '30d';
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(100, max(10, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $where = "1=1";
        $params = [];

        if ($status) {
            $where .= " AND t.status = ?";
            $params[] = $status;
        }

        // INTERVAL: whitelist-only values, parameterized via explicit map (no user input in SQL)
        $periodMap = ['7d' => 7, '30d' => 30, '90d' => 90, 'all' => null];
        $intervalDays = array_key_exists($period, $periodMap) ? $periodMap[$period] : $periodMap['30d'];
        if ($intervalDays !== null) {
            $where .= " AND t.created_at > NOW() - CAST(? || ' days' AS INTERVAL)";
            $params[] = $intervalDays;
        }

        if ($search) {
            // Escape LIKE wildcards in user input to prevent wildcard injection
            $escapedSearch = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
            $where .= " AND (c.name ILIKE ? ESCAPE '\\' OR c.email ILIKE ? ESCAPE '\\' OR CAST(t.ticket_id AS TEXT) LIKE ? ESCAPE '\\')";
            $params[] = "%{$escapedSearch}%";
            $params[] = "%{$escapedSearch}%";
            $params[] = "%{$escapedSearch}%";
        }

        // Count
        $stmtCount = $db->prepare("
            SELECT COUNT(*) FROM om_ai_support_tickets t
            LEFT JOIN om_market_customers c ON c.customer_id = t.customer_id
            WHERE $where
        ");
        $stmtCount->execute($params);
        $total = (int)$stmtCount->fetchColumn();

        // List with message count — parameterize LIMIT/OFFSET
        $listParams = $params;
        $listParams[] = $limit;
        $listParams[] = $offset;
        $stmt = $db->prepare("
            SELECT t.ticket_id, t.customer_id, t.order_id, t.status, t.category,
                t.priority, t.ai_confidence, t.resolved_by, t.created_at, t.updated_at, t.closed_at,
                c.name as customer_name, c.email as customer_email,
                (SELECT COUNT(*) FROM om_ai_support_messages WHERE ticket_id = t.ticket_id) as message_count
            FROM om_ai_support_tickets t
            LEFT JOIN om_market_customers c ON c.customer_id = t.customer_id
            WHERE $where
            ORDER BY
                CASE t.status WHEN 'escalated' THEN 1 WHEN 'open' THEN 2 ELSE 3 END,
                t.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($listParams);
        $tickets = $stmt->fetchAll();

        response(true, [
            'tickets' => $tickets,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => $total > 0 ? ceil($total / $limit) : 0
            ]
        ]);
    }

    // POST
    if ($method === 'POST') {
        $input = getInput();
        $action = $input['action'] ?? '';
        $ticketId = (int)($input['ticket_id'] ?? 0);

        if (!$ticketId) {
            response(false, null, "ticket_id obrigatorio", 400);
        }

        // Validate action upfront
        $validActions = ['close', 'respond', 'escalate_to_ticket'];
        if (!in_array($action, $validActions, true)) {
            response(false, null, "Acao invalida. Valores aceitos: " . implode(', ', $validActions), 400);
        }

        // Verify exists
        $stmt = $db->prepare("SELECT ticket_id, customer_id, order_id, status, category, priority, ai_confidence, resolved_by, created_at, updated_at, closed_at FROM om_ai_support_tickets WHERE ticket_id = ?");
        $stmt->execute([$ticketId]);
        $ticket = $stmt->fetch();
        if (!$ticket) {
            response(false, null, "Conversa IA nao encontrada", 404);
        }

        if ($action === 'close') {
            $db->prepare("UPDATE om_ai_support_tickets SET status = 'closed', updated_at = NOW() WHERE ticket_id = ?")
                ->execute([$ticketId]);
            response(true, ['message' => 'Conversa IA fechada']);
        }

        if ($action === 'respond') {
            $message = trim($input['message'] ?? '');
            if (empty($message)) {
                response(false, null, "Mensagem obrigatoria", 400);
            }
            if (mb_strlen($message) > 5000) {
                response(false, null, "Mensagem excede o limite de 5000 caracteres", 400);
            }

            $db->prepare("
                INSERT INTO om_ai_support_messages (ticket_id, role, content, metadata, created_at)
                VALUES (?, 'assistant', ?, ?::jsonb, NOW())
            ")->execute([
                $ticketId,
                "[Admin " . $adminName . "]: " . $message,
                json_encode(['source' => 'admin', 'admin_id' => $adminId])
            ]);

            $db->prepare("UPDATE om_ai_support_tickets SET updated_at = NOW() WHERE ticket_id = ?")
                ->execute([$ticketId]);

            response(true, ['message' => 'Resposta enviada']);
        }

        if ($action === 'escalate_to_ticket') {
            $db->beginTransaction();

            // Get conversation summary
            $stmtMsgs = $db->prepare("
                SELECT role, content FROM om_ai_support_messages
                WHERE ticket_id = ? ORDER BY created_at ASC LIMIT 20
            ");
            $stmtMsgs->execute([$ticketId]);
            $msgs = $stmtMsgs->fetchAll();

            $summary = "Escalado do Chat IA #$ticketId\n\n";
            foreach ($msgs as $m) {
                $role = $m['role'] === 'user' ? 'Cliente' : 'IA';
                $summary .= "[$role]: " . $m['content'] . "\n\n";
            }

            // Get customer name
            $customerName = '';
            if ($ticket['customer_id']) {
                $stmtC = $db->prepare("SELECT name FROM om_market_customers WHERE customer_id = ?");
                $stmtC->execute([$ticket['customer_id']]);
                $customerName = $stmtC->fetchColumn() ?: '';
            }

            // Create support ticket — use cryptographically secure random for ticket number
            $ticketNumber = 'TK-' . strtoupper(bin2hex(random_bytes(4)));
            $stmtNew = $db->prepare("
                INSERT INTO om_support_tickets (ticket_number, entidade_tipo, entidade_id, entidade_nome, assunto, categoria, status, prioridade, referencia_tipo, referencia_id, created_at, updated_at)
                VALUES (?, 'customer', ?, ?, ?, COALESCE(?, 'geral'), 'aberto', 'alta', 'ai_ticket', ?, NOW(), NOW())
                RETURNING id
            ");
            $stmtNew->execute([
                $ticketNumber,
                $ticket['customer_id'],
                $customerName,
                "Escalado do Chat IA #{$ticketId}",
                $ticket['category'],
                $ticketId
            ]);
            $newTicketId = (int)$stmtNew->fetchColumn();

            // Add conversation as first message
            $db->prepare("
                INSERT INTO om_support_messages (ticket_id, remetente_tipo, remetente_id, remetente_nome, mensagem, created_at)
                VALUES (?, 'system', ?, ?, ?, NOW())
            ")->execute([$newTicketId, $adminId, $adminName, $summary]);

            // Mark AI ticket as closed
            $db->prepare("UPDATE om_ai_support_tickets SET status = 'closed', updated_at = NOW() WHERE ticket_id = ?")
                ->execute([$ticketId]);

            $db->commit();
            response(true, ['message' => "Escalado para ticket #$newTicketId", 'new_ticket_id' => $newTicketId]);
        }

        // Unreachable due to upfront action validation, but defensive fallback
        response(false, null, "Acao invalida", 400);
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log("[admin/ai-tickets] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
