<?php
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";
require_once __DIR__ . '/../helpers/notify.php';
require_once __DIR__ . '/../helpers/ws-customer-broadcast.php';

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();
    $admin_id = $payload["uid"];

    if ($_SERVER["REQUEST_METHOD"] === "GET") {
        $view = $_GET["view"] ?? "list";

        // SLA stats view
        if ($view === "sla") {
            // General ticket stats
            $stmt = $db->query("
                SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN status IN ('open','waiting','aberto','em_atendimento','aguardando_resposta') THEN 1 ELSE 0 END) as open,
                    SUM(CASE WHEN status IN ('resolved','resolvido') THEN 1 ELSE 0 END) as resolved,
                    SUM(CASE WHEN status IN ('closed','fechado') THEN 1 ELSE 0 END) as closed
                FROM om_support_tickets
            ");
            $stats = $stmt->fetch();

            // Average first response time (time between ticket creation and first admin message)
            // Uses window function instead of LATERAL join for performance
            $stmtFirstResp = $db->query("
                SELECT
                    ROUND(AVG(EXTRACT(EPOCH FROM (first_response - ticket_created)) / 3600)::numeric, 1) as avg_first_response_hours
                FROM (
                    SELECT t.id, t.created_at as ticket_created,
                        MIN(m.created_at) as first_response
                    FROM om_support_tickets t
                    INNER JOIN om_support_messages m ON m.ticket_id = t.id
                        AND m.remetente_tipo IN ('admin','support','bot')
                    WHERE t.created_at > NOW() - INTERVAL '30 days'
                    GROUP BY t.id, t.created_at
                ) sub
            ");
            $firstResp = $stmtFirstResp->fetch();
            $stats['avg_first_response_hours'] = (float)($firstResp['avg_first_response_hours'] ?? 0);

            // Average resolution time
            $stmtResol = $db->query("
                SELECT
                    ROUND(AVG(EXTRACT(EPOCH FROM (updated_at - created_at)) / 3600)::numeric, 1) as avg_resolution_hours,
                    COUNT(CASE WHEN EXTRACT(EPOCH FROM (updated_at - created_at)) < 86400 THEN 1 END) as resolved_24h,
                    COUNT(CASE WHEN EXTRACT(EPOCH FROM (updated_at - created_at)) < 259200 THEN 1 END) as resolved_72h,
                    COUNT(*) as total_resolved
                FROM om_support_tickets
                WHERE status IN ('resolved','closed','resolvido','fechado')
                AND created_at > NOW() - INTERVAL '30 days'
            ");
            $resol = $stmtResol->fetch();
            $stats['avg_resolution_hours'] = (float)($resol['avg_resolution_hours'] ?? 0);
            $totalResolved = (int)($resol['total_resolved'] ?? 0);
            $stats['resolved_24h_pct'] = $totalResolved > 0
                ? round(((int)$resol['resolved_24h'] / $totalResolved) * 100, 1)
                : 0;
            $stats['resolved_72h_pct'] = $totalResolved > 0
                ? round(((int)$resol['resolved_72h'] / $totalResolved) * 100, 1)
                : 0;

            // Tickets per day (last 14 days)
            $stmtChart = $db->query("
                SELECT DATE(created_at) as dia, COUNT(*) as total,
                    SUM(CASE WHEN status IN ('resolved','closed','resolvido','fechado') THEN 1 ELSE 0 END) as resolved
                FROM om_support_tickets
                WHERE created_at > NOW() - INTERVAL '14 days'
                GROUP BY DATE(created_at)
                ORDER BY dia
            ");
            $stats['chart'] = $stmtChart->fetchAll();

            // Top categories (last 30 days)
            $stmtCats = $db->query("
                SELECT COALESCE(categoria, 'geral') as category, COUNT(*) as total
                FROM om_support_tickets
                WHERE created_at > NOW() - INTERVAL '30 days'
                GROUP BY categoria
                ORDER BY total DESC
                LIMIT 8
            ");
            $stats['top_categories'] = $stmtCats->fetchAll();

            response(true, ['sla' => $stats]);
        }

        // Single ticket fetch by ID
        $ticketId = (int)($_GET["id"] ?? 0);
        if ($ticketId > 0 && empty($_GET["activity"])) {
            $stmt = $db->prepare("
                SELECT t.id, t.ticket_number, t.entidade_tipo, t.entidade_id, t.entidade_nome,
                       t.assunto AS subject, t.categoria AS category,
                       t.prioridade AS priority, t.status,
                       t.atendente_id, t.atendente_nome AS assigned_name,
                       t.created_at, t.updated_at,
                       t.pedido_id AS order_id
                FROM om_support_tickets t
                WHERE t.id = ?
            ");
            $stmt->execute([$ticketId]);
            $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$ticket) response(false, null, "Ticket nao encontrado", 404);

            // Enrich with customer data if entity is a customer
            if ($ticket['entidade_tipo'] === 'cliente' && $ticket['entidade_id']) {
                $stmtCust = $db->prepare("SELECT name, email, phone, created_at FROM om_customers WHERE customer_id = ?");
                $stmtCust->execute([(int)$ticket['entidade_id']]);
                $cust = $stmtCust->fetch(PDO::FETCH_ASSOC);
                if ($cust) {
                    $ticket['customer_name'] = $cust['name'] ?: $ticket['entidade_nome'];
                    $ticket['customer_email'] = $cust['email'];
                    $ticket['customer_phone'] = $cust['phone'];
                    $ticket['customer_since'] = $cust['created_at'];
                }

                // Order count
                $stmtOrders = $db->prepare("SELECT COUNT(*) FROM om_market_orders WHERE customer_id = ?");
                $stmtOrders->execute([(int)$ticket['entidade_id']]);
                $ticket['customer_orders_count'] = (int)$stmtOrders->fetchColumn();

                // Membership data
                try {
                    $stmtMember = $db->prepare("
                        SELECT m.plan, m.status, p.name as plan_name, p.priority_support
                        FROM om_customer_memberships m
                        LEFT JOIN om_membership_plans p ON p.slug = m.plan
                        WHERE m.customer_id = ?
                        AND m.status IN ('active', 'trialing')
                        AND (m.current_period_end > NOW() OR m.expires_at > NOW())
                        LIMIT 1
                    ");
                    $stmtMember->execute([(int)$ticket['entidade_id']]);
                    $membership = $stmtMember->fetch(PDO::FETCH_ASSOC);
                    if ($membership) {
                        $ticket['membership_plan'] = $membership['plan'];
                        $ticket['membership_plan_name'] = $membership['plan_name'];
                        $ticket['membership_support'] = $membership['priority_support'] ?? 'normal';
                    }
                } catch (Exception $e) {
                    // Non-fatal
                }
            }

            response(true, ['ticket' => $ticket]);
        }

        $status = $_GET["status"] ?? null;
        $priority = $_GET["priority"] ?? null;
        $page = max(1, (int)($_GET["page"] ?? 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;

        $where = ["1=1"];
        $params = [];
        if ($status) { $where[] = "t.status = ?"; $params[] = $status; }
        if ($priority) { $where[] = "t.prioridade = ?"; $params[] = $priority; }
        $where_sql = implode(" AND ", $where);

        $stmt = $db->prepare("SELECT COUNT(*) as total FROM om_support_tickets t WHERE {$where_sql}");
        $stmt->execute($params);
        $total = (int)$stmt->fetch()["total"];

        $stmt = $db->prepare("
            SELECT t.id, t.ticket_number, t.entidade_tipo, t.entidade_id, t.entidade_nome,
                   t.assunto AS subject, t.categoria AS category,
                   t.prioridade AS priority, t.status,
                   t.atendente_id, t.atendente_nome AS assigned_name,
                   t.created_at, t.updated_at
            FROM om_support_tickets t
            WHERE {$where_sql}
            ORDER BY CASE t.prioridade
                WHEN 'urgente' THEN 1
                WHEN 'alta' THEN 2
                WHEN 'normal' THEN 3
                WHEN 'baixa' THEN 4
                ELSE 5
            END, t.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $params[] = (int)$limit;
        $params[] = (int)$offset;
        $stmt->execute($params);
        $tickets = $stmt->fetchAll();

        response(true, [
            "tickets" => $tickets,
            "pagination" => ["page" => $page, "limit" => $limit, "total" => $total, "pages" => ceil($total / $limit)]
        ], "Tickets listados");

    } elseif ($_SERVER["REQUEST_METHOD"] === "POST") {
        $input = getInput();
        $ticket_id = (int)($input["ticket_id"] ?? 0);
        $new_status = $input["status"] ?? null;
        $assigned_to = isset($input["assigned_to"]) ? (int)$input["assigned_to"] : null;

        if (!$ticket_id) response(false, null, "ticket_id obrigatorio", 400);

        $updates = [];
        $update_params = [];

        if ($new_status) {
            $valid = ["open", "waiting", "resolved", "closed", "aberto", "em_atendimento", "aguardando_resposta", "resolvido", "fechado"];
            if (!in_array($new_status, $valid)) response(false, null, "Status invalido", 400);
            $updates[] = "status = ?";
            $update_params[] = $new_status;
        }
        if ($assigned_to !== null) {
            $updates[] = "atendente_id = ?";
            $update_params[] = $assigned_to;
        }
        if (empty($updates)) response(false, null, "Nada para atualizar", 400);

        $updates[] = "updated_at = NOW()";
        $update_params[] = $ticket_id;

        // Fetch ticket info before update (for notification)
        $stmtTicket = $db->prepare("SELECT id, entidade_tipo, entidade_id, assunto, status FROM om_support_tickets WHERE id = ?");
        $stmtTicket->execute([$ticket_id]);
        $ticket = $stmtTicket->fetch();

        $stmt = $db->prepare("UPDATE om_support_tickets SET " . implode(", ", $updates) . " WHERE id = ?");
        $stmt->execute($update_params);

        // Push + WebSocket notification to customer when ticket status changes
        if ($ticket && $ticket['entidade_tipo'] === 'customer' && $new_status) {
            try {
                $statusLabels = [
                    'resolved' => 'resolvido', 'resolvido' => 'resolvido',
                    'closed' => 'fechado', 'fechado' => 'fechado',
                    'em_atendimento' => 'em atendimento',
                    'aguardando_resposta' => 'aguardando sua resposta',
                    'waiting' => 'aguardando sua resposta',
                    'open' => 'reaberto', 'aberto' => 'reaberto',
                ];
                $statusLabel = $statusLabels[$new_status] ?? $new_status;

                notifyCustomer($db, (int)$ticket['entidade_id'],
                    'Atualizacao do ticket',
                    'Seu ticket "' . $ticket['assunto'] . '" foi ' . $statusLabel . '.',
                    '/mercado/',
                    ['type' => 'ticket_update', 'ticket_id' => $ticket_id]
                );
                wsBroadcastToCustomer((int)$ticket['entidade_id'], 'ticket_update', [
                    'ticket_id' => $ticket_id,
                    'status' => $new_status,
                ]);
            } catch (\Throwable $e) {
                error_log("[admin/tickets] Push/WS notification failed: " . $e->getMessage());
            }
        }

        response(true, ["ticket_id" => $ticket_id], "Ticket atualizado");
    } else {
        response(false, null, "Metodo nao permitido", 405);
    }
} catch (Exception $e) {
    error_log("[admin/tickets] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
