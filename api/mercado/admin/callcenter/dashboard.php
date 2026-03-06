<?php
/**
 * /api/mercado/admin/callcenter/dashboard.php
 *
 * Call Center Dashboard — real-time stats, daily totals, historical metrics, agent performance.
 *
 * GET ?view=realtime: Live counts (agents, queue, calls, whatsapp, recent orders).
 * GET ?view=today: Today's aggregated totals.
 * GET ?view=history&from=YYYY-MM-DD&to=YYYY-MM-DD: Historical metrics.
 * GET ?view=agent_performance: Per-agent breakdown for today (or date range).
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

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        response(false, null, "Metodo nao permitido", 405);
    }

    $view = trim($_GET['view'] ?? '');

    if (!$view) {
        response(false, null, "Informe view: realtime, today, history, agent_performance", 400);
    }

    // =================== REALTIME ===================
    if ($view === 'realtime') {

        // Agents by status
        $stmt = $db->query("
            SELECT status, COUNT(*) AS count
            FROM om_callcenter_agents
            GROUP BY status
        ");
        $agentsByStatus = [];
        while ($row = $stmt->fetch()) {
            $agentsByStatus[$row['status']] = (int)$row['count'];
        }

        // Queue count (not yet picked, not abandoned)
        $queueCount = (int)$db->query("
            SELECT COUNT(*) FROM om_callcenter_queue
            WHERE picked_at IS NULL AND abandoned_at IS NULL
        ")->fetchColumn();

        // Active calls
        $activeCalls = (int)$db->query("
            SELECT COUNT(*) FROM om_callcenter_calls
            WHERE status IN ('in_progress', 'ai_handling')
        ")->fetchColumn();

        // Calls on hold
        $onHoldCalls = (int)$db->query("
            SELECT COUNT(*) FROM om_callcenter_calls
            WHERE status = 'on_hold'
        ")->fetchColumn();

        // Active WhatsApp conversations
        $activeWhatsapp = (int)$db->query("
            SELECT COUNT(*) FROM om_callcenter_whatsapp
            WHERE status IN ('bot', 'assigned')
        ")->fetchColumn();

        // Waiting WhatsApp (unassigned)
        $waitingWhatsapp = (int)$db->query("
            SELECT COUNT(*) FROM om_callcenter_whatsapp
            WHERE status = 'waiting'
        ")->fetchColumn();

        // Orders in last hour (from callcenter)
        $ordersLastHour = (int)$db->query("
            SELECT COUNT(*) FROM om_market_orders
            WHERE source = 'callcenter' AND date_added >= NOW() - INTERVAL '1 hour'
        ")->fetchColumn();

        // Active drafts (being built)
        $activeDrafts = (int)$db->query("
            SELECT COUNT(*) FROM om_callcenter_order_drafts
            WHERE status IN ('building', 'review', 'awaiting_payment')
        ")->fetchColumn();

        // Average wait time in queue (last 1 hour, only completed)
        $avgWait = $db->query("
            SELECT COALESCE(AVG(EXTRACT(EPOCH FROM (picked_at - queued_at))), 0)::int AS avg_seconds
            FROM om_callcenter_queue
            WHERE picked_at IS NOT NULL AND queued_at >= NOW() - INTERVAL '1 hour'
        ")->fetch();

        // Longest waiting caller
        $longestWait = $db->query("
            SELECT EXTRACT(EPOCH FROM (NOW() - queued_at))::int AS wait_seconds,
                   customer_name, customer_phone
            FROM om_callcenter_queue
            WHERE picked_at IS NULL AND abandoned_at IS NULL
            ORDER BY queued_at ASC
            LIMIT 1
        ")->fetch();

        response(true, [
            'agents' => [
                'online' => (int)($agentsByStatus['online'] ?? 0),
                'busy' => (int)($agentsByStatus['busy'] ?? 0),
                'break' => (int)($agentsByStatus['break'] ?? 0),
                'offline' => (int)($agentsByStatus['offline'] ?? 0),
                'total' => array_sum($agentsByStatus),
            ],
            'queue' => [
                'count' => $queueCount,
                'avg_wait_seconds' => (int)($avgWait['avg_seconds'] ?? 0),
                'longest_wait' => $longestWait ?: null,
            ],
            'calls' => [
                'active' => $activeCalls,
                'on_hold' => $onHoldCalls,
            ],
            'whatsapp' => [
                'active' => $activeWhatsapp,
                'waiting' => $waitingWhatsapp,
            ],
            'orders' => [
                'last_hour' => $ordersLastHour,
                'active_drafts' => $activeDrafts,
            ],
            'timestamp' => date('c'),
        ]);
    }

    // =================== TODAY ===================
    if ($view === 'today') {

        $today = date('Y-m-d');

        // Calls today
        $callStats = $db->prepare("
            SELECT
                COUNT(*) AS total_calls,
                COUNT(*) FILTER (WHERE status = 'completed') AS answered,
                COUNT(*) FILTER (WHERE status = 'missed') AS missed,
                COUNT(*) FILTER (WHERE status = 'ai_handling') AS ai_handled,
                COUNT(*) FILTER (WHERE status = 'voicemail') AS voicemail,
                COUNT(*) FILTER (WHERE callback_requested = TRUE) AS callbacks_requested,
                COUNT(*) FILTER (WHERE callback_completed_at IS NOT NULL) AS callbacks_completed,
                COALESCE(AVG(duration_seconds) FILTER (WHERE duration_seconds > 0), 0)::int AS avg_duration,
                COALESCE(AVG(wait_time_seconds) FILTER (WHERE wait_time_seconds IS NOT NULL), 0)::int AS avg_wait
            FROM om_callcenter_calls
            WHERE created_at::date = ?
        ");
        $callStats->execute([$today]);
        $calls = $callStats->fetch();

        // Draft/order stats today
        $draftStats = $db->prepare("
            SELECT
                COUNT(*) AS total_drafts,
                COUNT(*) FILTER (WHERE status = 'submitted') AS submitted,
                COUNT(*) FILTER (WHERE status = 'cancelled') AS cancelled,
                COUNT(*) FILTER (WHERE status IN ('building', 'review', 'awaiting_payment')) AS in_progress,
                COALESCE(SUM(total) FILTER (WHERE status = 'submitted'), 0) AS submitted_total_value
            FROM om_callcenter_order_drafts
            WHERE created_at::date = ?
        ");
        $draftStats->execute([$today]);
        $drafts = $draftStats->fetch();

        // WhatsApp stats today
        $waStats = $db->prepare("
            SELECT
                COUNT(*) AS total_conversations,
                COUNT(*) FILTER (WHERE status = 'closed') AS closed,
                COUNT(*) FILTER (WHERE status IN ('bot', 'assigned', 'waiting')) AS active
            FROM om_callcenter_whatsapp
            WHERE created_at::date = ?
        ");
        $waStats->execute([$today]);
        $whatsapp = $waStats->fetch();

        // Total callcenter orders (from om_market_orders)
        $orderStats = $db->prepare("
            SELECT
                COUNT(*) AS total_orders,
                COALESCE(SUM(total), 0) AS total_revenue
            FROM om_market_orders
            WHERE source = 'callcenter' AND date_added::date = ?
        ");
        $orderStats->execute([$today]);
        $orders = $orderStats->fetch();

        response(true, [
            'date' => $today,
            'calls' => [
                'total' => (int)$calls['total_calls'],
                'answered' => (int)$calls['answered'],
                'missed' => (int)$calls['missed'],
                'ai_handled' => (int)$calls['ai_handled'],
                'voicemail' => (int)$calls['voicemail'],
                'callbacks_requested' => (int)$calls['callbacks_requested'],
                'callbacks_completed' => (int)$calls['callbacks_completed'],
                'avg_duration_seconds' => (int)$calls['avg_duration'],
                'avg_wait_seconds' => (int)$calls['avg_wait'],
            ],
            'drafts' => [
                'total' => (int)$drafts['total_drafts'],
                'submitted' => (int)$drafts['submitted'],
                'cancelled' => (int)$drafts['cancelled'],
                'in_progress' => (int)$drafts['in_progress'],
                'submitted_value' => round((float)$drafts['submitted_total_value'], 2),
            ],
            'whatsapp' => [
                'total_conversations' => (int)$whatsapp['total_conversations'],
                'closed' => (int)$whatsapp['closed'],
                'active' => (int)$whatsapp['active'],
            ],
            'orders' => [
                'total' => (int)$orders['total_orders'],
                'revenue' => round((float)$orders['total_revenue'], 2),
            ],
        ]);
    }

    // =================== HISTORY ===================
    if ($view === 'history') {

        $from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
        $to = $_GET['to'] ?? date('Y-m-d');

        // Validate date format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            response(false, null, "Formato de data invalido. Use YYYY-MM-DD", 400);
        }

        // Aggregated daily metrics from om_callcenter_metrics
        $stmt = $db->prepare("
            SELECT
                date,
                SUM(total_calls) AS total_calls,
                SUM(answered_calls) AS answered_calls,
                SUM(missed_calls) AS missed_calls,
                SUM(ai_handled_calls) AS ai_handled_calls,
                SUM(ai_orders_placed) AS ai_orders_placed,
                SUM(agent_orders_placed) AS agent_orders_placed,
                CASE WHEN SUM(answered_calls) > 0
                    THEN (SUM(avg_handle_time_seconds * answered_calls) / SUM(answered_calls))::int
                    ELSE 0
                END AS avg_handle_time,
                CASE WHEN SUM(total_calls) > 0
                    THEN (SUM(avg_wait_time_seconds * total_calls) / SUM(total_calls))::int
                    ELSE 0
                END AS avg_wait_time,
                SUM(orders_total_value) AS orders_total_value,
                SUM(whatsapp_conversations) AS whatsapp_conversations,
                SUM(callbacks_requested) AS callbacks_requested,
                SUM(callbacks_completed) AS callbacks_completed,
                CASE WHEN SUM(csat_count) > 0
                    THEN ROUND(SUM(csat_sum) / SUM(csat_count), 1)
                    ELSE NULL
                END AS avg_csat
            FROM om_callcenter_metrics
            WHERE date >= ? AND date <= ?
            GROUP BY date
            ORDER BY date DESC
        ");
        $stmt->execute([$from, $to]);
        $metrics = $stmt->fetchAll();

        foreach ($metrics as &$m) {
            $m['total_calls'] = (int)$m['total_calls'];
            $m['answered_calls'] = (int)$m['answered_calls'];
            $m['missed_calls'] = (int)$m['missed_calls'];
            $m['ai_handled_calls'] = (int)$m['ai_handled_calls'];
            $m['ai_orders_placed'] = (int)$m['ai_orders_placed'];
            $m['agent_orders_placed'] = (int)$m['agent_orders_placed'];
            $m['avg_handle_time'] = (int)$m['avg_handle_time'];
            $m['avg_wait_time'] = (int)$m['avg_wait_time'];
            $m['orders_total_value'] = round((float)$m['orders_total_value'], 2);
            $m['whatsapp_conversations'] = (int)$m['whatsapp_conversations'];
            $m['callbacks_requested'] = (int)$m['callbacks_requested'];
            $m['callbacks_completed'] = (int)$m['callbacks_completed'];
            $m['avg_csat'] = $m['avg_csat'] !== null ? (float)$m['avg_csat'] : null;
        }
        unset($m);

        // Summary totals
        $summary = [
            'period' => ['from' => $from, 'to' => $to],
            'total_calls' => array_sum(array_column($metrics, 'total_calls')),
            'total_answered' => array_sum(array_column($metrics, 'answered_calls')),
            'total_missed' => array_sum(array_column($metrics, 'missed_calls')),
            'total_orders' => array_sum(array_column($metrics, 'agent_orders_placed')) + array_sum(array_column($metrics, 'ai_orders_placed')),
            'total_revenue' => round(array_sum(array_column($metrics, 'orders_total_value')), 2),
            'days_count' => count($metrics),
        ];

        response(true, ['metrics' => $metrics, 'summary' => $summary]);
    }

    // =================== AGENT PERFORMANCE ===================
    if ($view === 'agent_performance') {

        $from = $_GET['from'] ?? date('Y-m-d');
        $to = $_GET['to'] ?? date('Y-m-d');

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            response(false, null, "Formato de data invalido", 400);
        }

        $stmt = $db->prepare("
            SELECT
                a.id AS agent_id,
                a.display_name,
                a.status AS current_status,
                COALESCE(SUM(m.total_calls), 0) AS total_calls,
                COALESCE(SUM(m.answered_calls), 0) AS answered_calls,
                COALESCE(SUM(m.missed_calls), 0) AS missed_calls,
                COALESCE(SUM(m.ai_handled_calls), 0) AS ai_handled_calls,
                COALESCE(SUM(m.agent_orders_placed), 0) AS orders_placed,
                COALESCE(SUM(m.orders_total_value), 0) AS orders_value,
                CASE WHEN COALESCE(SUM(m.answered_calls), 0) > 0
                    THEN (SUM(m.avg_handle_time_seconds * m.answered_calls) / SUM(m.answered_calls))::int
                    ELSE 0
                END AS avg_handle_time,
                CASE WHEN COALESCE(SUM(m.csat_count), 0) > 0
                    THEN ROUND(SUM(m.csat_sum) / SUM(m.csat_count), 1)
                    ELSE NULL
                END AS avg_csat,
                COALESCE(SUM(m.whatsapp_conversations), 0) AS whatsapp_conversations
            FROM om_callcenter_agents a
            LEFT JOIN om_callcenter_metrics m ON m.agent_id = a.id AND m.date >= ? AND m.date <= ?
            GROUP BY a.id, a.display_name, a.status
            ORDER BY COALESCE(SUM(m.agent_orders_placed), 0) DESC, a.display_name ASC
        ");
        $stmt->execute([$from, $to]);
        $agents = $stmt->fetchAll();

        foreach ($agents as &$agent) {
            $agent['agent_id'] = (int)$agent['agent_id'];
            $agent['total_calls'] = (int)$agent['total_calls'];
            $agent['answered_calls'] = (int)$agent['answered_calls'];
            $agent['missed_calls'] = (int)$agent['missed_calls'];
            $agent['ai_handled_calls'] = (int)$agent['ai_handled_calls'];
            $agent['orders_placed'] = (int)$agent['orders_placed'];
            $agent['orders_value'] = round((float)$agent['orders_value'], 2);
            $agent['avg_handle_time'] = (int)$agent['avg_handle_time'];
            $agent['avg_csat'] = $agent['avg_csat'] !== null ? (float)$agent['avg_csat'] : null;
            $agent['whatsapp_conversations'] = (int)$agent['whatsapp_conversations'];

            // Answer rate
            $agent['answer_rate'] = $agent['total_calls'] > 0
                ? round(($agent['answered_calls'] / $agent['total_calls']) * 100, 1)
                : 0;
        }
        unset($agent);

        // Also add live call counts not in metrics yet
        if ($from === date('Y-m-d') && $to === date('Y-m-d')) {
            $today = date('Y-m-d');
            foreach ($agents as &$agent) {
                $stmt = $db->prepare("
                    SELECT COUNT(*) FROM om_callcenter_calls
                    WHERE agent_id = ? AND created_at::date = ?
                ");
                $stmt->execute([$agent['agent_id'], $today]);
                $liveCalls = (int)$stmt->fetchColumn();

                $stmt = $db->prepare("
                    SELECT COUNT(*) FROM om_callcenter_order_drafts
                    WHERE agent_id = ? AND status = 'submitted' AND created_at::date = ?
                ");
                $stmt->execute([$agent['agent_id'], $today]);
                $liveOrders = (int)$stmt->fetchColumn();

                $agent['live_calls_today'] = $liveCalls;
                $agent['live_orders_today'] = $liveOrders;
            }
            unset($agent);
        }

        response(true, [
            'period' => ['from' => $from, 'to' => $to],
            'agents' => $agents,
        ]);
    }

    response(false, null, "View invalida. Valores: realtime, today, history, agent_performance", 400);

} catch (Exception $e) {
    error_log("[admin/callcenter/dashboard] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
