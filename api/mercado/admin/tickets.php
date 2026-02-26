<?php
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";

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
                    SUM(CASE WHEN status IN ('aberto','em_atendimento','aguardando_resposta') THEN 1 ELSE 0 END) as open,
                    SUM(CASE WHEN status = 'resolvido' THEN 1 ELSE 0 END) as resolved,
                    SUM(CASE WHEN status = 'fechado' THEN 1 ELSE 0 END) as closed
                FROM om_support_tickets
            ");
            $stats = $stmt->fetch();

            // Average first response time (time between ticket creation and first admin message)
            $stmtFirstResp = $db->query("
                SELECT
                    ROUND(AVG(EXTRACT(EPOCH FROM (m.created_at - t.created_at)) / 3600)::numeric, 1) as avg_first_response_hours
                FROM om_support_tickets t
                INNER JOIN LATERAL (
                    SELECT created_at FROM om_support_messages
                    WHERE ticket_id = t.id AND remetente_tipo IN ('admin','support','bot')
                    ORDER BY created_at ASC LIMIT 1
                ) m ON TRUE
                WHERE t.created_at > NOW() - INTERVAL '30 days'
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
                WHERE status IN ('resolvido','fechado')
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
                    SUM(CASE WHEN status IN ('resolvido','fechado') THEN 1 ELSE 0 END) as resolved
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
            $valid = ["aberto", "em_atendimento", "aguardando_resposta", "resolvido", "fechado"];
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

        $stmt = $db->prepare("UPDATE om_support_tickets SET " . implode(", ", $updates) . " WHERE id = ?");
        $stmt->execute($update_params);

        response(true, ["ticket_id" => $ticket_id], "Ticket atualizado");
    } else {
        response(false, null, "Metodo nao permitido", 405);
    }
} catch (Exception $e) {
    error_log("[admin/tickets] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
