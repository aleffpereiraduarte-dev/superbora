<?php
/**
 * /api/mercado/admin/callcenter/rh-dashboard.php
 *
 * Dashboard completo do Call Center para o RH
 * Ponto, ligacoes, rejeicoes, tempo medio, analise IA, mensagens
 *
 * GET ?view=overview&month=YYYY-MM           — Visao geral do call center
 * GET ?view=agents&month=YYYY-MM             — Lista de agentes com metricas
 * GET ?view=agent_detail&agent_id=X&month=   — Detalhe completo de um agente
 * GET ?view=ai_analysis&month=YYYY-MM        — Analise IA (Claude) da equipe
 * GET ?view=messages&agent_id=X              — Mensagens com um agente
 * GET ?view=live                             — Status ao vivo de todos
 * POST action=send_message                   — Enviar mensagem para agente
 * POST action=mark_read                      — Marcar mensagens como lidas
 */
require_once __DIR__ . '/../../config/database.php';
require_once dirname(__DIR__, 4) . '/includes/classes/OmAuth.php';

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    $payload = om_auth()->requireAdmin();
    $adminId = (int)$payload['uid'];
    $method = $_SERVER['REQUEST_METHOD'];

    // ════════════════════════════════════════════════════════════════
    // POST — Messages
    // ════════════════════════════════════════════════════════════════
    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $action = $input['action'] ?? '';

        if ($action === 'send_message') {
            $receiverId = (int)($input['receiver_id'] ?? 0);
            $message = trim($input['message'] ?? '');
            $messageType = $input['message_type'] ?? 'text';

            if (!$receiverId || !$message) {
                response(false, null, 'receiver_id e message sao obrigatorios', 400);
            }

            // Get receiver's rh_employee_id from agent
            $agentStmt = $db->prepare("SELECT rh_employee_id FROM om_callcenter_agents WHERE id = ?");
            $agentStmt->execute([$receiverId]);
            $agentData = $agentStmt->fetch();
            $rhRecId = $agentData ? (int)$agentData['rh_employee_id'] : $receiverId;

            $stmt = $db->prepare("
                INSERT INTO om_rh_chat_messages
                    (sender_id, sender_type, receiver_id, receiver_type, message, message_type, is_read, created_at)
                VALUES (?, 'rh_admin', ?, 'employee', ?, ?, 0, NOW())
                RETURNING message_id, created_at
            ");
            $stmt->execute([$adminId, $rhRecId, $message, $messageType]);
            $msg = $stmt->fetch();

            response(true, [
                'message_id' => (int)$msg['message_id'],
                'created_at' => $msg['created_at'],
            ]);
        }

        if ($action === 'mark_read') {
            $agentId = (int)($input['agent_id'] ?? 0);
            if (!$agentId) response(false, null, 'agent_id obrigatorio', 400);

            $agentStmt = $db->prepare("SELECT rh_employee_id FROM om_callcenter_agents WHERE id = ?");
            $agentStmt->execute([$agentId]);
            $agentData = $agentStmt->fetch();
            $rhId = $agentData ? (int)$agentData['rh_employee_id'] : $agentId;

            $db->prepare("
                UPDATE om_rh_chat_messages
                SET is_read = 1, read_at = NOW()
                WHERE sender_id = ? AND sender_type = 'employee'
                  AND receiver_type = 'rh_admin' AND is_read = 0
            ")->execute([$rhId]);

            response(true, ['marked' => true]);
        }

        response(false, null, 'Acao invalida', 400);
    }

    // ════════════════════════════════════════════════════════════════
    // GET
    // ════════════════════════════════════════════════════════════════
    $view = $_GET['view'] ?? '';
    $month = $_GET['month'] ?? date('Y-m');
    $monthStart = "$month-01";
    $monthEnd = date('Y-m-t', strtotime($monthStart));

    // ──────────── LIVE ────────────
    if ($view === 'live') {
        // Agents online with current status
        $stmt = $db->query("
            SELECT a.id, a.display_name, a.status, a.extension, a.updated_at,
                   e.full_name AS rh_name, e.department, e.position, e.id AS rh_id
            FROM om_callcenter_agents a
            LEFT JOIN om_rh_employees e ON e.id = a.rh_employee_id
            ORDER BY
                CASE a.status WHEN 'online' THEN 1 WHEN 'busy' THEN 2 WHEN 'break' THEN 3 ELSE 4 END,
                a.display_name
        ");
        $agents = $stmt->fetchAll();

        $result = [];
        foreach ($agents as $ag) {
            // Today's clock records
            $clockStmt = $db->prepare("
                SELECT record_type, record_time
                FROM om_rh_clock_records
                WHERE employee_id = ? AND record_time::date = CURRENT_DATE
                ORDER BY record_time ASC
            ");
            $clockStmt->execute([$ag['rh_id']]);
            $clockRecs = $clockStmt->fetchAll();

            $clockedIn = false;
            $firstEntry = null;
            $lastRecord = null;
            $totalSec = 0;
            $lastEntry = null;
            foreach ($clockRecs as $cr) {
                if (in_array($cr['record_type'], ['entrada', 'retorno'])) {
                    $clockedIn = true;
                    $lastEntry = strtotime($cr['record_time']);
                    if (!$firstEntry) $firstEntry = $cr['record_time'];
                } elseif (in_array($cr['record_type'], ['saida', 'intervalo']) && $lastEntry) {
                    $totalSec += strtotime($cr['record_time']) - $lastEntry;
                    $lastEntry = null;
                    if ($cr['record_type'] === 'saida') $clockedIn = false;
                }
                $lastRecord = $cr;
            }
            if ($lastEntry && $clockedIn) $totalSec += time() - $lastEntry;

            // Today's calls
            $callStmt = $db->prepare("
                SELECT COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE status = 'completed') AS completed,
                    COUNT(*) FILTER (WHERE status = 'missed') AS missed,
                    ROUND(AVG(duration_seconds) FILTER (WHERE duration_seconds > 0)) AS avg_dur
                FROM om_callcenter_calls
                WHERE agent_id = ? AND created_at::date = CURRENT_DATE
            ");
            $callStmt->execute([$ag['id']]);
            $callStats = $callStmt->fetch();

            // Unread messages from this employee
            $unreadStmt = $db->prepare("
                SELECT COUNT(*) FROM om_rh_chat_messages
                WHERE sender_id = ? AND sender_type = 'employee'
                  AND receiver_type = 'rh_admin' AND is_read = 0
            ");
            $unreadStmt->execute([$ag['rh_id']]);
            $unread = (int)$unreadStmt->fetchColumn();

            $result[] = [
                'agent_id' => (int)$ag['id'],
                'name' => $ag['rh_name'] ?: $ag['display_name'],
                'department' => $ag['department'],
                'position' => $ag['position'],
                'status' => $ag['status'],
                'extension' => $ag['extension'],
                'clocked_in' => $clockedIn,
                'first_entry' => $firstEntry ? substr($firstEntry, 11, 5) : null,
                'worked_hours' => round($totalSec / 3600, 2),
                'worked_display' => sprintf('%02d:%02d', floor($totalSec / 3600), floor(($totalSec % 3600) / 60)),
                'last_clock_type' => $lastRecord['record_type'] ?? null,
                'calls_today' => (int)$callStats['total'],
                'calls_completed' => (int)$callStats['completed'],
                'calls_missed' => (int)$callStats['missed'],
                'avg_call_duration' => (int)$callStats['avg_dur'],
                'unread_messages' => $unread,
            ];
        }

        // Queue stats
        $queueCount = (int)$db->query("
            SELECT COUNT(*) FROM om_callcenter_queue
            WHERE picked_at IS NULL AND abandoned_at IS NULL
        ")->fetchColumn();

        response(true, [
            'agents' => $result,
            'queue_count' => $queueCount,
            'timestamp' => date('Y-m-d H:i:s'),
            'counts' => [
                'online' => count(array_filter($result, fn($a) => $a['status'] === 'online')),
                'busy' => count(array_filter($result, fn($a) => $a['status'] === 'busy')),
                'break' => count(array_filter($result, fn($a) => $a['status'] === 'break')),
                'offline' => count(array_filter($result, fn($a) => $a['status'] === 'offline')),
                'clocked_in' => count(array_filter($result, fn($a) => $a['clocked_in'])),
            ],
        ]);
    }

    // ──────────── OVERVIEW ────────────
    if ($view === 'overview') {
        // Agent count
        $totalAgents = (int)$db->query("SELECT COUNT(*) FROM om_callcenter_agents")->fetchColumn();

        // Calls this month
        $callStmt = $db->prepare("
            SELECT
                COUNT(*) AS total,
                COUNT(*) FILTER (WHERE status = 'completed') AS completed,
                COUNT(*) FILTER (WHERE status = 'missed') AS missed,
                COUNT(*) FILTER (WHERE status = 'voicemail') AS voicemail,
                COUNT(*) FILTER (WHERE status = 'ai_handling') AS ai_handled,
                COUNT(*) FILTER (WHERE order_id IS NOT NULL) AS with_order,
                ROUND(AVG(duration_seconds) FILTER (WHERE duration_seconds > 0)) AS avg_duration,
                SUM(COALESCE(duration_seconds, 0)) AS total_duration,
                ROUND(AVG(wait_time_seconds) FILTER (WHERE wait_time_seconds > 0)) AS avg_wait
            FROM om_callcenter_calls
            WHERE created_at::date BETWEEN ?::date AND ?::date
        ");
        $callStmt->execute([$monthStart, $monthEnd]);
        $callTotals = $callStmt->fetch();

        // Orders this month
        $orderStmt = $db->prepare("
            SELECT COUNT(*) AS total, COALESCE(SUM(total), 0) AS revenue
            FROM om_callcenter_order_drafts
            WHERE status = 'submitted' AND created_at::date BETWEEN ?::date AND ?::date
        ");
        $orderStmt->execute([$monthStart, $monthEnd]);
        $orderTotals = $orderStmt->fetch();

        // Ponto summary
        $pontoStmt = $db->prepare("
            SELECT COUNT(DISTINCT employee_id) AS employees_worked,
                   COUNT(DISTINCT record_time::date) AS total_days
            FROM om_rh_clock_records
            WHERE record_time::date BETWEEN ?::date AND ?::date
              AND record_type = 'entrada'
              AND employee_id IN (SELECT rh_employee_id FROM om_callcenter_agents WHERE rh_employee_id IS NOT NULL)
        ");
        $pontoStmt->execute([$monthStart, $monthEnd]);
        $pontoTotals = $pontoStmt->fetch();

        // Calls per day (for chart)
        $dailyStmt = $db->prepare("
            SELECT created_at::date AS day,
                COUNT(*) AS total,
                COUNT(*) FILTER (WHERE status = 'completed') AS completed,
                COUNT(*) FILTER (WHERE status = 'missed') AS missed
            FROM om_callcenter_calls
            WHERE created_at::date BETWEEN ?::date AND ?::date
            GROUP BY created_at::date
            ORDER BY day
        ");
        $dailyStmt->execute([$monthStart, $monthEnd]);
        $dailyCalls = $dailyStmt->fetchAll();

        // Calls per hour (for heatmap)
        $hourlyStmt = $db->prepare("
            SELECT EXTRACT(HOUR FROM created_at) AS hour, COUNT(*) AS total
            FROM om_callcenter_calls
            WHERE created_at::date BETWEEN ?::date AND ?::date
            GROUP BY hour ORDER BY hour
        ");
        $hourlyStmt->execute([$monthStart, $monthEnd]);
        $hourlyCalls = [];
        while ($r = $hourlyStmt->fetch()) $hourlyCalls[(int)$r['hour']] = (int)$r['total'];

        // Sentiment distribution
        $sentStmt = $db->prepare("
            SELECT ai_sentiment, COUNT(*) AS cnt
            FROM om_callcenter_calls
            WHERE ai_sentiment IS NOT NULL AND created_at::date BETWEEN ?::date AND ?::date
            GROUP BY ai_sentiment
        ");
        $sentStmt->execute([$monthStart, $monthEnd]);
        $sentiments = [];
        while ($r = $sentStmt->fetch()) $sentiments[$r['ai_sentiment']] = (int)$r['cnt'];

        // CSAT average
        $csatStmt = $db->prepare("
            SELECT ROUND(SUM(csat_sum) / NULLIF(SUM(csat_count), 0), 2) AS avg
            FROM om_callcenter_metrics
            WHERE date BETWEEN ?::date AND ?::date
        ");
        $csatStmt->execute([$monthStart, $monthEnd]);
        $csatAvg = $csatStmt->fetch()['avg'] ?? 0;

        // Unread messages to RH
        $unreadTotal = (int)$db->query("
            SELECT COUNT(*) FROM om_rh_chat_messages
            WHERE sender_type = 'employee' AND receiver_type = 'rh_admin' AND is_read = 0
        ")->fetchColumn();

        response(true, [
            'month' => $month,
            'agents_total' => $totalAgents,
            'calls' => [
                'total' => (int)$callTotals['total'],
                'completed' => (int)$callTotals['completed'],
                'missed' => (int)$callTotals['missed'],
                'voicemail' => (int)$callTotals['voicemail'],
                'ai_handled' => (int)$callTotals['ai_handled'],
                'with_order' => (int)$callTotals['with_order'],
                'avg_duration_sec' => (int)$callTotals['avg_duration'],
                'total_duration_sec' => (int)$callTotals['total_duration'],
                'avg_wait_sec' => (int)$callTotals['avg_wait'],
                'answer_rate' => $callTotals['total'] > 0 ? round((int)$callTotals['completed'] / (int)$callTotals['total'] * 100, 1) : 0,
            ],
            'orders' => [
                'total' => (int)$orderTotals['total'],
                'revenue' => round((float)$orderTotals['revenue'], 2),
            ],
            'ponto' => [
                'employees_worked' => (int)$pontoTotals['employees_worked'],
                'total_entry_days' => (int)$pontoTotals['total_days'],
            ],
            'daily_calls' => array_map(fn($d) => [
                'date' => $d['day'],
                'total' => (int)$d['total'],
                'completed' => (int)$d['completed'],
                'missed' => (int)$d['missed'],
            ], $dailyCalls),
            'hourly_calls' => $hourlyCalls,
            'sentiments' => $sentiments,
            'csat_avg' => round((float)$csatAvg, 2),
            'unread_messages' => $unreadTotal,
        ]);
    }

    // ──────────── AGENTS ────────────
    if ($view === 'agents') {
        $stmt = $db->prepare("
            SELECT
                a.id AS agent_id, a.display_name, a.status, a.extension,
                e.full_name AS rh_name, e.employee_number, e.department, e.position,
                e.admission_date, e.id AS rh_id,

                COALESCE(c.total_calls, 0) AS total_calls,
                COALESCE(c.completed, 0) AS completed_calls,
                COALESCE(c.missed, 0) AS missed_calls,
                COALESCE(c.avg_dur, 0) AS avg_duration,
                COALESCE(c.total_dur, 0) AS total_duration,

                COALESCE(o.total_orders, 0) AS total_orders,
                COALESCE(o.revenue, 0) AS total_revenue,

                COALESCE(wa.convos, 0) AS whatsapp_convos

            FROM om_callcenter_agents a
            LEFT JOIN om_rh_employees e ON e.id = a.rh_employee_id

            LEFT JOIN (
                SELECT agent_id,
                    COUNT(*) AS total_calls,
                    COUNT(*) FILTER (WHERE status = 'completed') AS completed,
                    COUNT(*) FILTER (WHERE status = 'missed') AS missed,
                    ROUND(AVG(duration_seconds) FILTER (WHERE duration_seconds > 0)) AS avg_dur,
                    SUM(COALESCE(duration_seconds, 0)) AS total_dur
                FROM om_callcenter_calls
                WHERE created_at::date BETWEEN ?::date AND ?::date
                GROUP BY agent_id
            ) c ON c.agent_id = a.id

            LEFT JOIN (
                SELECT agent_id, COUNT(*) AS total_orders, COALESCE(SUM(total), 0) AS revenue
                FROM om_callcenter_order_drafts
                WHERE status = 'submitted' AND created_at::date BETWEEN ?::date AND ?::date
                GROUP BY agent_id
            ) o ON o.agent_id = a.id

            LEFT JOIN (
                SELECT agent_id, COUNT(*) AS convos
                FROM om_callcenter_whatsapp
                WHERE agent_id IS NOT NULL AND created_at::date BETWEEN ?::date AND ?::date
                GROUP BY agent_id
            ) wa ON wa.agent_id = a.id

            ORDER BY a.display_name
        ");
        $stmt->execute([$monthStart, $monthEnd, $monthStart, $monthEnd, $monthStart, $monthEnd]);
        $agents = $stmt->fetchAll();

        $result = [];
        foreach ($agents as $ag) {
            // Ponto hours
            $pontoHours = 0;
            $pontoDays = 0;
            if ($ag['rh_id']) {
                $pStmt = $db->prepare("
                    SELECT record_type, record_time
                    FROM om_rh_clock_records
                    WHERE employee_id = ? AND record_time::date BETWEEN ?::date AND ?::date
                    ORDER BY record_time ASC
                ");
                $pStmt->execute([$ag['rh_id'], $monthStart, $monthEnd]);
                $pRecs = $pStmt->fetchAll();
                $daysSet = [];
                $totalSec = 0;
                $lastEntry = null;
                foreach ($pRecs as $pr) {
                    if (in_array($pr['record_type'], ['entrada', 'retorno'])) {
                        $lastEntry = strtotime($pr['record_time']);
                        $daysSet[substr($pr['record_time'], 0, 10)] = true;
                    } elseif (in_array($pr['record_type'], ['saida', 'intervalo']) && $lastEntry) {
                        $totalSec += strtotime($pr['record_time']) - $lastEntry;
                        $lastEntry = null;
                    }
                }
                $pontoHours = round($totalSec / 3600, 2);
                $pontoDays = count($daysSet);
            }

            // Unread messages from this agent
            $unreadStmt = $db->prepare("
                SELECT COUNT(*) FROM om_rh_chat_messages
                WHERE sender_id = ? AND sender_type = 'employee'
                  AND receiver_type = 'rh_admin' AND is_read = 0
            ");
            $unreadStmt->execute([$ag['rh_id']]);
            $unread = (int)$unreadStmt->fetchColumn();

            $result[] = [
                'agent_id' => (int)$ag['agent_id'],
                'name' => $ag['rh_name'] ?: $ag['display_name'],
                'display_name' => $ag['display_name'],
                'employee_number' => $ag['employee_number'],
                'department' => $ag['department'],
                'position' => $ag['position'],
                'admission_date' => $ag['admission_date'],
                'status' => $ag['status'],
                'ponto_hours' => $pontoHours,
                'ponto_days' => $pontoDays,
                'total_calls' => (int)$ag['total_calls'],
                'completed_calls' => (int)$ag['completed_calls'],
                'missed_calls' => (int)$ag['missed_calls'],
                'avg_duration' => (int)$ag['avg_duration'],
                'total_duration' => (int)($ag['total_duration'] ?? 0),
                'total_orders' => (int)$ag['total_orders'],
                'total_revenue' => round((float)$ag['total_revenue'], 2),
                'whatsapp_convos' => (int)$ag['whatsapp_convos'],
                'unread_messages' => $unread,
            ];
        }

        response(true, ['month' => $month, 'agents' => $result]);
    }

    // ──────────── MESSAGES ────────────
    if ($view === 'messages') {
        $agentId = (int)($_GET['agent_id'] ?? 0);
        if (!$agentId) response(false, null, 'agent_id obrigatorio', 400);

        $agentStmt = $db->prepare("
            SELECT a.id, a.display_name, a.rh_employee_id,
                   e.full_name AS rh_name, e.department, e.position
            FROM om_callcenter_agents a
            LEFT JOIN om_rh_employees e ON e.id = a.rh_employee_id
            WHERE a.id = ?
        ");
        $agentStmt->execute([$agentId]);
        $agent = $agentStmt->fetch();
        if (!$agent) response(false, null, 'Agente nao encontrado', 404);

        $rhId = $agent['rh_employee_id'] ? (int)$agent['rh_employee_id'] : $agentId;

        // Get messages between RH and this employee (both directions)
        $msgStmt = $db->prepare("
            SELECT message_id, sender_id, sender_type, receiver_id, receiver_type,
                   message, message_type, is_read, read_at, created_at
            FROM om_rh_chat_messages
            WHERE (sender_id = ? AND sender_type = 'employee')
               OR (receiver_id = ? AND receiver_type = 'employee')
            ORDER BY created_at ASC
            LIMIT 200
        ");
        $msgStmt->execute([$rhId, $rhId]);
        $messages = $msgStmt->fetchAll();

        $msgList = [];
        foreach ($messages as $m) {
            $msgList[] = [
                'id' => (int)$m['message_id'],
                'from_rh' => $m['sender_type'] === 'rh_admin',
                'message' => $m['message'],
                'type' => $m['message_type'],
                'read' => (bool)$m['is_read'],
                'created_at' => $m['created_at'],
            ];
        }

        // Mark incoming as read
        $db->prepare("
            UPDATE om_rh_chat_messages
            SET is_read = 1, read_at = NOW()
            WHERE sender_id = ? AND sender_type = 'employee'
              AND receiver_type = 'rh_admin' AND is_read = 0
        ")->execute([$rhId]);

        response(true, [
            'agent' => [
                'id' => (int)$agent['id'],
                'name' => $agent['rh_name'] ?: $agent['display_name'],
                'department' => $agent['department'],
                'position' => $agent['position'],
            ],
            'messages' => $msgList,
        ]);
    }

    // ──────────── AI ANALYSIS ────────────
    if ($view === 'ai_analysis') {
        // Gather data for Claude analysis
        $agStmt = $db->prepare("
            SELECT a.id, a.display_name, e.full_name,
                COALESCE(c.total, 0) AS calls, COALESCE(c.completed, 0) AS completed,
                COALESCE(c.missed, 0) AS missed, COALESCE(c.avg_dur, 0) AS avg_dur,
                COALESCE(o.orders, 0) AS orders, COALESCE(o.rev, 0) AS revenue
            FROM om_callcenter_agents a
            LEFT JOIN om_rh_employees e ON e.id = a.rh_employee_id
            LEFT JOIN (
                SELECT agent_id, COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE status='completed') AS completed,
                    COUNT(*) FILTER (WHERE status='missed') AS missed,
                    ROUND(AVG(duration_seconds) FILTER (WHERE duration_seconds>0)) AS avg_dur
                FROM om_callcenter_calls WHERE created_at::date BETWEEN ?::date AND ?::date
                GROUP BY agent_id
            ) c ON c.agent_id = a.id
            LEFT JOIN (
                SELECT agent_id, COUNT(*) AS orders, COALESCE(SUM(total),0) AS rev
                FROM om_callcenter_order_drafts WHERE status='submitted' AND created_at::date BETWEEN ?::date AND ?::date
                GROUP BY agent_id
            ) o ON o.agent_id = a.id
            ORDER BY a.display_name
        ");
        $agStmt->execute([$monthStart, $monthEnd, $monthStart, $monthEnd]);
        $agentsData = $agStmt->fetchAll();

        // Build prompt
        $agentsSummary = "";
        foreach ($agentsData as $a) {
            $name = $a['full_name'] ?: $a['display_name'];
            $missRate = $a['calls'] > 0 ? round($a['missed'] / $a['calls'] * 100, 1) : 0;
            $agentsSummary .= "- {$name}: {$a['calls']} ligacoes, {$a['completed']} atendidas, {$a['missed']} perdidas ({$missRate}% perda), media {$a['avg_dur']}s/chamada, {$a['orders']} pedidos, R\${$a['revenue']} receita\n";
        }

        $totalCalls = array_sum(array_column($agentsData, 'calls'));
        $totalMissed = array_sum(array_column($agentsData, 'missed'));
        $totalOrders = array_sum(array_column($agentsData, 'orders'));

        $prompt = "Voce e o analista de RH do call center SuperBora. Analise os dados da equipe de suporte do mes {$month} e gere um relatorio executivo em portugues brasileiro.

DADOS DA EQUIPE ({$month}):
Total de agentes: " . count($agentsData) . "
Total de ligacoes: {$totalCalls}
Total perdidas: {$totalMissed}
Total pedidos: {$totalOrders}

POR AGENTE:
{$agentsSummary}

GERE O RELATORIO COM:
1. **Resumo Executivo** (3-4 frases sobre a performance geral)
2. **Destaques Positivos** (quem se destacou e por que)
3. **Pontos de Atencao** (quem precisa de acompanhamento, taxas de perda altas, tempos acima da media)
4. **Recomendacoes** (acoes praticas para o RH: treinamentos, feedbacks, escalas)
5. **Score Geral da Equipe** (0-100, com justificativa)

Seja objetivo e pratico. Use dados numericos. Foque em insights acionaveis.";

        // Call Claude
        $claudePath = dirname(__DIR__, 4) . '/api/mercado/helpers/claude-client.php';
        $analysis = null;
        if (file_exists($claudePath)) {
            require_once $claudePath;
            try {
                $claude = new ClaudeClient();
                $analysis = $claude->send($prompt, ['max_tokens' => 2000]);
            } catch (\Exception $e) {
                $analysis = "Erro ao gerar analise: " . $e->getMessage();
            }
        } else {
            $analysis = "ClaudeClient nao encontrado. Instale o helper em helpers/claude-client.php";
        }

        response(true, [
            'month' => $month,
            'analysis' => $analysis,
            'data_summary' => [
                'agents' => count($agentsData),
                'total_calls' => $totalCalls,
                'total_missed' => $totalMissed,
                'miss_rate' => $totalCalls > 0 ? round($totalMissed / $totalCalls * 100, 1) : 0,
                'total_orders' => $totalOrders,
            ],
        ]);
    }

    if (!$view) {
        response(false, null, 'Informe view: overview, agents, agent_detail, ai_analysis, messages, live', 400);
    }

} catch (\Throwable $e) {
    error_log("[callcenter/rh-dashboard] Error: " . $e->getMessage());
    response(false, null, 'Erro interno: ' . $e->getMessage(), 500);
}
