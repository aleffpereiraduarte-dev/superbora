<?php
/**
 * /api/mercado/admin/callcenter/relatorio-rh.php
 *
 * Relatorio completo do agente para o RH — ponto, ligacoes, pedidos, atividades.
 *
 * GET ?agent_id=X&from=YYYY-MM-DD&to=YYYY-MM-DD  — Relatorio individual
 * GET ?all=true&from=&to=                          — Relatorio de todos os agentes
 * GET ?summary=true&month=YYYY-MM                  — Resumo mensal (folha)
 */
require_once __DIR__ . '/../../config/database.php';
require_once dirname(__DIR__, 4) . '/includes/classes/OmAuth.php';

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    $payload = om_auth()->requireAdmin();

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        response(false, null, 'Metodo nao permitido', 405);
    }

    $from = $_GET['from'] ?? date('Y-m-01');
    $to = $_GET['to'] ?? date('Y-m-d');
    $agentId = isset($_GET['agent_id']) ? (int)$_GET['agent_id'] : null;
    $all = !empty($_GET['all']);
    $summary = !empty($_GET['summary']);
    $month = $_GET['month'] ?? date('Y-m');

    // ════════════════════════════════════════════════════════════════
    // SUMMARY — Resumo mensal de todos os agentes (para folha/RH)
    // ════════════════════════════════════════════════════════════════
    if ($summary) {
        $year = (int)substr($month, 0, 4);
        $mon = (int)substr($month, 5, 2);
        $monthStart = "$month-01";
        $monthEnd = date('Y-m-t', strtotime($monthStart));

        $stmt = $db->prepare("
            SELECT
                a.id AS agent_id,
                a.display_name,
                a.status AS current_status,
                e.full_name AS rh_name,
                e.employee_number,
                e.department,
                e.position,
                e.admission_date,
                e.id AS rh_employee_id,

                -- Ponto: horas do mes via clock_records
                COALESCE(ponto.dias_trabalhados, 0) AS dias_trabalhados,
                COALESCE(ponto.horas_totais, 0) AS horas_totais,

                -- Ligacoes do mes
                COALESCE(calls.total_calls, 0) AS total_calls,
                COALESCE(calls.answered_calls, 0) AS answered_calls,
                COALESCE(calls.missed_calls, 0) AS missed_calls,
                COALESCE(calls.avg_duration, 0) AS avg_call_duration,
                COALESCE(calls.total_duration, 0) AS total_call_duration,

                -- Pedidos do mes
                COALESCE(orders.total_orders, 0) AS total_orders,
                COALESCE(orders.total_revenue, 0) AS total_revenue,

                -- WhatsApp do mes
                COALESCE(wa.total_conversations, 0) AS whatsapp_conversations,

                -- Metrics aggregated
                COALESCE(met.csat_avg, 0) AS csat_avg

            FROM om_callcenter_agents a
            LEFT JOIN om_rh_employees e ON e.id = a.rh_employee_id

            -- Ponto
            LEFT JOIN (
                SELECT
                    employee_id,
                    COUNT(DISTINCT record_time::date) AS dias_trabalhados,
                    ROUND(EXTRACT(EPOCH FROM SUM(
                        CASE WHEN record_type IN ('saida','intervalo') THEN record_time ELSE NULL END
                        - CASE WHEN record_type IN ('entrada','retorno') THEN record_time ELSE NULL END
                    )) / 3600.0, 2) AS horas_totais
                FROM (
                    SELECT employee_id, record_type, record_time,
                        LAG(record_time) OVER (PARTITION BY employee_id, record_time::date ORDER BY record_time) AS prev_time,
                        LAG(record_type) OVER (PARTITION BY employee_id, record_time::date ORDER BY record_time) AS prev_type
                    FROM om_rh_clock_records
                    WHERE record_time::date BETWEEN ?::date AND ?::date
                ) sub
                WHERE record_type IN ('saida','intervalo') AND prev_type IN ('entrada','retorno')
                GROUP BY employee_id
            ) ponto ON ponto.employee_id = a.rh_employee_id

            -- Ligacoes
            LEFT JOIN (
                SELECT
                    agent_id,
                    COUNT(*) AS total_calls,
                    COUNT(*) FILTER (WHERE status = 'completed') AS answered_calls,
                    COUNT(*) FILTER (WHERE status = 'missed') AS missed_calls,
                    ROUND(AVG(duration_seconds) FILTER (WHERE duration_seconds > 0)) AS avg_duration,
                    SUM(COALESCE(duration_seconds, 0)) AS total_duration
                FROM om_callcenter_calls
                WHERE created_at::date BETWEEN ?::date AND ?::date
                GROUP BY agent_id
            ) calls ON calls.agent_id = a.id

            -- Pedidos
            LEFT JOIN (
                SELECT
                    agent_id,
                    COUNT(*) AS total_orders,
                    COALESCE(SUM(total), 0) AS total_revenue
                FROM om_callcenter_order_drafts
                WHERE status = 'submitted'
                  AND created_at::date BETWEEN ?::date AND ?::date
                GROUP BY agent_id
            ) orders ON orders.agent_id = a.id

            -- WhatsApp
            LEFT JOIN (
                SELECT
                    agent_id,
                    COUNT(*) AS total_conversations
                FROM om_callcenter_whatsapp
                WHERE created_at::date BETWEEN ?::date AND ?::date
                  AND agent_id IS NOT NULL
                GROUP BY agent_id
            ) wa ON wa.agent_id = a.id

            -- CSAT
            LEFT JOIN (
                SELECT
                    agent_id,
                    ROUND(SUM(csat_sum) / NULLIF(SUM(csat_count), 0), 2) AS csat_avg
                FROM om_callcenter_metrics
                WHERE date BETWEEN ?::date AND ?::date
                GROUP BY agent_id
            ) met ON met.agent_id = a.id

            ORDER BY a.display_name
        ");
        $stmt->execute([
            $monthStart, $monthEnd,   // ponto
            $monthStart, $monthEnd,   // calls
            $monthStart, $monthEnd,   // orders
            $monthStart, $monthEnd,   // whatsapp
            $monthStart, $monthEnd,   // metrics
        ]);
        $agents = $stmt->fetchAll();

        // Format
        $result = [];
        $totals = ['agents' => 0, 'dias' => 0, 'horas' => 0, 'calls' => 0, 'orders' => 0, 'revenue' => 0];
        foreach ($agents as $ag) {
            $totals['agents']++;
            $totals['dias'] += (int)$ag['dias_trabalhados'];
            $totals['horas'] += (float)$ag['horas_totais'];
            $totals['calls'] += (int)$ag['total_calls'];
            $totals['orders'] += (int)$ag['total_orders'];
            $totals['revenue'] += (float)$ag['total_revenue'];

            $result[] = [
                'agent_id' => (int)$ag['agent_id'],
                'display_name' => $ag['display_name'],
                'current_status' => $ag['current_status'],
                'rh' => [
                    'employee_id' => $ag['rh_employee_id'] ? (int)$ag['rh_employee_id'] : null,
                    'name' => $ag['rh_name'],
                    'employee_number' => $ag['employee_number'],
                    'department' => $ag['department'],
                    'position' => $ag['position'],
                    'admission_date' => $ag['admission_date'],
                ],
                'ponto' => [
                    'dias_trabalhados' => (int)$ag['dias_trabalhados'],
                    'horas_totais' => round((float)$ag['horas_totais'], 2),
                ],
                'ligacoes' => [
                    'total' => (int)$ag['total_calls'],
                    'atendidas' => (int)$ag['answered_calls'],
                    'perdidas' => (int)$ag['missed_calls'],
                    'duracao_media_seg' => (int)$ag['avg_call_duration'],
                    'duracao_total_seg' => (int)$ag['total_call_duration'],
                ],
                'pedidos' => [
                    'total' => (int)$ag['total_orders'],
                    'receita' => round((float)$ag['total_revenue'], 2),
                ],
                'whatsapp' => (int)$ag['whatsapp_conversations'],
                'csat' => round((float)$ag['csat_avg'], 2),
            ];
        }

        response(true, [
            'month' => $month,
            'period' => ['from' => $monthStart, 'to' => $monthEnd],
            'totals' => $totals,
            'agents' => $result,
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    // ALL — Lista resumida de todos os agentes no periodo
    // ════════════════════════════════════════════════════════════════
    if ($all) {
        // Reuse summary logic with custom dates
        $_GET['summary'] = true;
        $_GET['month'] = substr($from, 0, 7);
        // Redirect internally — but just run similar query with from/to
        $stmt = $db->prepare("
            SELECT
                a.id AS agent_id,
                a.display_name,
                a.status AS current_status,
                e.full_name AS rh_name,
                e.employee_number,
                e.department,
                e.position,
                e.id AS rh_employee_id,
                COALESCE(c.total_calls, 0) AS total_calls,
                COALESCE(c.answered_calls, 0) AS answered_calls,
                COALESCE(o.total_orders, 0) AS total_orders,
                COALESCE(o.total_revenue, 0) AS total_revenue
            FROM om_callcenter_agents a
            LEFT JOIN om_rh_employees e ON e.id = a.rh_employee_id
            LEFT JOIN (
                SELECT agent_id, COUNT(*) AS total_calls,
                    COUNT(*) FILTER (WHERE status = 'completed') AS answered_calls
                FROM om_callcenter_calls
                WHERE created_at::date BETWEEN ?::date AND ?::date
                GROUP BY agent_id
            ) c ON c.agent_id = a.id
            LEFT JOIN (
                SELECT agent_id, COUNT(*) AS total_orders, COALESCE(SUM(total), 0) AS total_revenue
                FROM om_callcenter_order_drafts
                WHERE status = 'submitted' AND created_at::date BETWEEN ?::date AND ?::date
                GROUP BY agent_id
            ) o ON o.agent_id = a.id
            ORDER BY a.display_name
        ");
        $stmt->execute([$from, $to, $from, $to]);
        $agents = $stmt->fetchAll();

        $result = [];
        foreach ($agents as $ag) {
            $result[] = [
                'agent_id' => (int)$ag['agent_id'],
                'display_name' => $ag['display_name'],
                'rh_name' => $ag['rh_name'],
                'employee_number' => $ag['employee_number'],
                'department' => $ag['department'],
                'position' => $ag['position'],
                'current_status' => $ag['current_status'],
                'total_calls' => (int)$ag['total_calls'],
                'answered_calls' => (int)$ag['answered_calls'],
                'total_orders' => (int)$ag['total_orders'],
                'total_revenue' => round((float)$ag['total_revenue'], 2),
            ];
        }
        response(true, ['period' => ['from' => $from, 'to' => $to], 'agents' => $result]);
    }

    // ════════════════════════════════════════════════════════════════
    // INDIVIDUAL — Relatorio completo de um agente
    // ════════════════════════════════════════════════════════════════
    if (!$agentId) {
        response(false, null, 'Informe agent_id, all=true, ou summary=true', 400);
    }

    // Get agent + RH info
    $agStmt = $db->prepare("
        SELECT a.id, a.display_name, a.status, a.extension, a.skills, a.created_at AS agent_since,
               e.id AS rh_id, e.full_name, e.employee_number, e.department, e.position,
               e.admission_date, e.email, e.phone, e.cpf
        FROM om_callcenter_agents a
        LEFT JOIN om_rh_employees e ON e.id = a.rh_employee_id
        WHERE a.id = ?
    ");
    $agStmt->execute([$agentId]);
    $agent = $agStmt->fetch();

    if (!$agent) {
        response(false, null, 'Agente nao encontrado', 404);
    }

    $rhId = $agent['rh_id'] ? (int)$agent['rh_id'] : null;

    // 1. PONTO — Daily clock records
    $pontoData = [];
    if ($rhId) {
        $pontoStmt = $db->prepare("
            SELECT record_id, record_type, record_time, ip_address, location, notes, is_manual
            FROM om_rh_clock_records
            WHERE employee_id = ?
              AND record_time::date BETWEEN ?::date AND ?::date
            ORDER BY record_time ASC
        ");
        $pontoStmt->execute([$rhId, $from, $to]);
        $records = $pontoStmt->fetchAll();

        // Group by day and calculate hours
        $days = [];
        foreach ($records as $r) {
            $day = substr($r['record_time'], 0, 10);
            if (!isset($days[$day])) $days[$day] = ['date' => $day, 'records' => [], 'total_hours' => 0];
            $days[$day]['records'][] = [
                'id' => (int)$r['record_id'],
                'type' => $r['record_type'],
                'time' => $r['record_time'],
                'ip' => $r['ip_address'],
                'manual' => (bool)$r['is_manual'],
            ];
        }

        foreach ($days as &$day) {
            $totalSec = 0;
            $lastEntry = null;
            foreach ($day['records'] as $rec) {
                if (in_array($rec['type'], ['entrada', 'retorno'])) {
                    $lastEntry = strtotime($rec['time']);
                } elseif (in_array($rec['type'], ['saida', 'intervalo']) && $lastEntry) {
                    $totalSec += strtotime($rec['time']) - $lastEntry;
                    $lastEntry = null;
                }
            }
            if ($lastEntry) $totalSec += time() - $lastEntry;
            $day['total_hours'] = round($totalSec / 3600, 2);
        }
        unset($day);

        $totalHours = array_sum(array_column($days, 'total_hours'));
        $daysWorked = count(array_filter($days, fn($d) => $d['total_hours'] > 0));

        $pontoData = [
            'days' => array_values($days),
            'summary' => [
                'total_hours' => round($totalHours, 2),
                'days_worked' => $daysWorked,
                'avg_hours_per_day' => $daysWorked > 0 ? round($totalHours / $daysWorked, 2) : 0,
            ],
        ];
    }

    // 2. LIGACOES — Call history
    $callStmt = $db->prepare("
        SELECT id, customer_phone, customer_name, direction, status,
               duration_seconds, ai_summary, ai_sentiment, notes,
               started_at, ended_at, order_id
        FROM om_callcenter_calls
        WHERE agent_id = ?
          AND created_at::date BETWEEN ?::date AND ?::date
        ORDER BY created_at DESC
    ");
    $callStmt->execute([$agentId, $from, $to]);
    $calls = $callStmt->fetchAll();

    $callStats = [
        'total' => count($calls),
        'completed' => 0, 'missed' => 0, 'with_order' => 0,
        'total_duration' => 0, 'sentiments' => [],
    ];
    $callList = [];
    foreach ($calls as $c) {
        if ($c['status'] === 'completed') $callStats['completed']++;
        if ($c['status'] === 'missed') $callStats['missed']++;
        if ($c['order_id']) $callStats['with_order']++;
        $callStats['total_duration'] += (int)$c['duration_seconds'];
        if ($c['ai_sentiment']) {
            $callStats['sentiments'][$c['ai_sentiment']] = ($callStats['sentiments'][$c['ai_sentiment']] ?? 0) + 1;
        }
        $callList[] = [
            'id' => (int)$c['id'],
            'phone' => $c['customer_phone'],
            'customer' => $c['customer_name'],
            'direction' => $c['direction'],
            'status' => $c['status'],
            'duration' => (int)$c['duration_seconds'],
            'summary' => $c['ai_summary'],
            'sentiment' => $c['ai_sentiment'],
            'notes' => $c['notes'],
            'order_id' => $c['order_id'] ? (int)$c['order_id'] : null,
            'started_at' => $c['started_at'],
            'ended_at' => $c['ended_at'],
        ];
    }
    $callStats['avg_duration'] = $callStats['total'] > 0
        ? round($callStats['total_duration'] / $callStats['total'])
        : 0;

    // 3. PEDIDOS — Orders placed
    $orderStmt = $db->prepare("
        SELECT id, source, customer_name, customer_phone, partner_name,
               subtotal, delivery_fee, total, payment_method, status,
               created_at, updated_at
        FROM om_callcenter_order_drafts
        WHERE agent_id = ?
          AND created_at::date BETWEEN ?::date AND ?::date
        ORDER BY created_at DESC
    ");
    $orderStmt->execute([$agentId, $from, $to]);
    $orders = $orderStmt->fetchAll();

    $orderStats = ['total' => 0, 'submitted' => 0, 'cancelled' => 0, 'revenue' => 0];
    $orderList = [];
    foreach ($orders as $o) {
        $orderStats['total']++;
        if ($o['status'] === 'submitted') { $orderStats['submitted']++; $orderStats['revenue'] += (float)$o['total']; }
        if ($o['status'] === 'cancelled') $orderStats['cancelled']++;
        $orderList[] = [
            'id' => (int)$o['id'],
            'source' => $o['source'],
            'customer' => $o['customer_name'],
            'phone' => $o['customer_phone'],
            'store' => $o['partner_name'],
            'subtotal' => round((float)$o['subtotal'], 2),
            'delivery_fee' => round((float)$o['delivery_fee'], 2),
            'total' => round((float)$o['total'], 2),
            'payment' => $o['payment_method'],
            'status' => $o['status'],
            'created_at' => $o['created_at'],
        ];
    }
    $orderStats['revenue'] = round($orderStats['revenue'], 2);

    // 4. WHATSAPP — Conversations handled
    $waStmt = $db->prepare("
        SELECT id, phone, customer_name, status, unread_count, created_at, last_message_at
        FROM om_callcenter_whatsapp
        WHERE agent_id = ?
          AND created_at::date BETWEEN ?::date AND ?::date
        ORDER BY last_message_at DESC
    ");
    $waStmt->execute([$agentId, $from, $to]);
    $waConvos = $waStmt->fetchAll();

    $waList = [];
    foreach ($waConvos as $w) {
        $waList[] = [
            'id' => (int)$w['id'],
            'phone' => $w['phone'],
            'customer' => $w['customer_name'],
            'status' => $w['status'],
            'last_message' => $w['last_message_at'],
        ];
    }

    // 5. METRICS — Daily aggregated
    $metStmt = $db->prepare("
        SELECT date, total_calls, answered_calls, missed_calls,
               agent_orders_placed, avg_handle_time_seconds,
               orders_total_value, whatsapp_conversations,
               csat_sum, csat_count
        FROM om_callcenter_metrics
        WHERE agent_id = ?
          AND date BETWEEN ?::date AND ?::date
        ORDER BY date DESC
    ");
    $metStmt->execute([$agentId, $from, $to]);
    $metrics = $metStmt->fetchAll();

    $dailyMetrics = [];
    foreach ($metrics as $m) {
        $dailyMetrics[] = [
            'date' => $m['date'],
            'calls' => (int)$m['total_calls'],
            'answered' => (int)$m['answered_calls'],
            'missed' => (int)$m['missed_calls'],
            'orders' => (int)$m['agent_orders_placed'],
            'revenue' => round((float)$m['orders_total_value'], 2),
            'avg_handle_time' => (int)$m['avg_handle_time_seconds'],
            'whatsapp' => (int)$m['whatsapp_conversations'],
            'csat' => $m['csat_count'] > 0 ? round((float)$m['csat_sum'] / (int)$m['csat_count'], 2) : null,
        ];
    }

    // Build response
    response(true, [
        'agent' => [
            'id' => (int)$agent['id'],
            'display_name' => $agent['display_name'],
            'status' => $agent['status'],
            'extension' => $agent['extension'],
            'agent_since' => $agent['agent_since'],
        ],
        'rh' => $rhId ? [
            'employee_id' => $rhId,
            'full_name' => $agent['full_name'],
            'employee_number' => $agent['employee_number'],
            'department' => $agent['department'],
            'position' => $agent['position'],
            'admission_date' => $agent['admission_date'],
            'email' => $agent['email'],
            'phone' => $agent['phone'],
        ] : null,
        'period' => ['from' => $from, 'to' => $to],
        'ponto' => $pontoData,
        'ligacoes' => [
            'stats' => $callStats,
            'calls' => $callList,
        ],
        'pedidos' => [
            'stats' => $orderStats,
            'orders' => $orderList,
        ],
        'whatsapp' => [
            'total' => count($waList),
            'conversations' => $waList,
        ],
        'daily_metrics' => $dailyMetrics,
    ]);

} catch (\Throwable $e) {
    error_log("[callcenter/relatorio-rh] Error: " . $e->getMessage());
    response(false, null, 'Erro interno: ' . $e->getMessage(), 500);
}
