<?php
/**
 * /api/mercado/admin/callcenter-analytics.php
 *
 * Comprehensive Call Center Analytics — KPIs, call history, AI performance,
 * customer insights, and live monitoring.
 *
 * GET ?section=overview&period=today|week|month|custom&start_date=&end_date=
 * GET ?section=calls&period=...&status=&direction=&page=&limit=
 * GET ?section=ai_performance&period=...
 * GET ?section=customers&period=...
 * GET ?section=live
 */
require_once __DIR__ . '/../config/database.php';
require_once dirname(__DIR__, 3) . '/includes/classes/OmAuth.php';
require_once dirname(__DIR__, 3) . '/includes/classes/OmAudit.php';

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        response(false, null, "Metodo nao permitido", 405);
    }

    $section = trim($_GET['section'] ?? 'overview');
    $period = trim($_GET['period'] ?? 'today');

    // Resolve date range from period
    $today = date('Y-m-d');
    switch ($period) {
        case 'today':
            $startDate = $today;
            $endDate = $today;
            break;
        case 'week':
            $startDate = date('Y-m-d', strtotime('-7 days'));
            $endDate = $today;
            break;
        case 'month':
            $startDate = date('Y-m-d', strtotime('-30 days'));
            $endDate = $today;
            break;
        case 'custom':
            $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
            $endDate = $_GET['end_date'] ?? $today;
            break;
        default:
            $startDate = $today;
            $endDate = $today;
    }

    // Validate dates
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        response(false, null, "Formato de data invalido. Use YYYY-MM-DD", 400);
    }

    // ══════════════════════════════════════════════════════════════
    // SECTION: OVERVIEW
    // ══════════════════════════════════════════════════════════════
    if ($section === 'overview') {

        // --- KPI Cards ---
        $callKpis = $db->prepare("
            SELECT
                COUNT(*) AS total_calls,
                COUNT(*) FILTER (WHERE status = 'completed') AS completed_calls,
                COUNT(*) FILTER (WHERE status = 'missed') AS missed_calls,
                COUNT(*) FILTER (WHERE status = 'ai_handling') AS ai_handled,
                COUNT(*) FILTER (WHERE status IN ('completed','ai_handling')) AS resolved_calls,
                COALESCE(AVG(duration_seconds) FILTER (WHERE duration_seconds > 0), 0)::int AS avg_duration,
                COALESCE(AVG(wait_time_seconds) FILTER (WHERE wait_time_seconds IS NOT NULL), 0)::int AS avg_wait
            FROM om_callcenter_calls
            WHERE created_at::date >= ? AND created_at::date <= ?
        ");
        $callKpis->execute([$startDate, $endDate]);
        $kpis = $callKpis->fetch();

        // AI resolution rate
        $totalResolvable = (int)$kpis['resolved_calls'];
        $aiHandled = (int)$kpis['ai_handled'];
        $aiResolutionRate = $totalResolvable > 0 ? round(($aiHandled / $totalResolvable) * 100, 1) : 0;

        // Average conversation turns (from ai_context if available)
        $turnStmt = $db->prepare("
            SELECT COALESCE(AVG(
                CASE WHEN ai_summary IS NOT NULL AND ai_summary != '' THEN
                    GREATEST(1, (LENGTH(ai_summary) - LENGTH(REPLACE(ai_summary, E'\\n', ''))) + 1)
                ELSE 2
                END
            ), 0)::numeric(5,1) AS avg_turns
            FROM om_callcenter_calls
            WHERE status IN ('completed','ai_handling')
            AND created_at::date >= ? AND created_at::date <= ?
        ");
        $turnStmt->execute([$startDate, $endDate]);
        $avgTurns = (float)$turnStmt->fetch()['avg_turns'];

        // Orders placed via phone
        $orderStmt = $db->prepare("
            SELECT
                COUNT(*) AS total_orders,
                COALESCE(SUM(total), 0) AS total_revenue
            FROM om_market_orders
            WHERE source = 'callcenter'
            AND date_added::date >= ? AND date_added::date <= ?
        ");
        $orderStmt->execute([$startDate, $endDate]);
        $orders = $orderStmt->fetch();

        // CSAT
        $csatStmt = $db->prepare("
            SELECT
                CASE WHEN SUM(csat_count) > 0
                    THEN ROUND(SUM(csat_sum) / SUM(csat_count), 1)
                    ELSE NULL
                END AS avg_csat,
                SUM(csat_count) AS csat_responses
            FROM om_callcenter_metrics
            WHERE date >= ? AND date <= ?
        ");
        $csatStmt->execute([$startDate, $endDate]);
        $csat = $csatStmt->fetch();

        // --- Calls by Hour (bar chart) ---
        $hourlyStmt = $db->prepare("
            SELECT EXTRACT(HOUR FROM created_at)::int AS hour, COUNT(*) AS count
            FROM om_callcenter_calls
            WHERE created_at::date >= ? AND created_at::date <= ?
            GROUP BY hour
            ORDER BY hour
        ");
        $hourlyStmt->execute([$startDate, $endDate]);
        $hourlyRaw = $hourlyStmt->fetchAll();
        $callsByHour = array_fill(0, 24, 0);
        foreach ($hourlyRaw as $row) {
            $callsByHour[(int)$row['hour']] = (int)$row['count'];
        }

        // --- AI vs Agent Resolution (pie) ---
        $resolutionStmt = $db->prepare("
            SELECT
                COUNT(*) FILTER (WHERE status = 'ai_handling') AS ai_resolved,
                COUNT(*) FILTER (WHERE status = 'completed' AND agent_id IS NOT NULL) AS agent_resolved,
                COUNT(*) FILTER (WHERE status = 'missed') AS missed,
                COUNT(*) FILTER (WHERE status = 'voicemail') AS voicemail
            FROM om_callcenter_calls
            WHERE created_at::date >= ? AND created_at::date <= ?
        ");
        $resolutionStmt->execute([$startDate, $endDate]);
        $resolution = $resolutionStmt->fetch();

        // --- Top Reasons (from ai_tags) ---
        $tagsStmt = $db->prepare("
            SELECT tag, COUNT(*) AS count
            FROM om_callcenter_calls, UNNEST(ai_tags) AS tag
            WHERE created_at::date >= ? AND created_at::date <= ?
            AND ai_tags IS NOT NULL AND array_length(ai_tags, 1) > 0
            GROUP BY tag
            ORDER BY count DESC
            LIMIT 10
        ");
        $tagsStmt->execute([$startDate, $endDate]);
        $topReasons = $tagsStmt->fetchAll();
        foreach ($topReasons as &$r) {
            $r['count'] = (int)$r['count'];
        }
        unset($r);

        // --- Sentiment Distribution ---
        $sentimentStmt = $db->prepare("
            SELECT
                COALESCE(ai_sentiment, 'unknown') AS sentiment,
                COUNT(*) AS count
            FROM om_callcenter_calls
            WHERE created_at::date >= ? AND created_at::date <= ?
            GROUP BY ai_sentiment
            ORDER BY count DESC
        ");
        $sentimentStmt->execute([$startDate, $endDate]);
        $sentiments = $sentimentStmt->fetchAll();
        foreach ($sentiments as &$s) {
            $s['count'] = (int)$s['count'];
        }
        unset($s);

        // --- Weekly Trend (last 7 or period days) ---
        $trendStmt = $db->prepare("
            SELECT
                created_at::date AS day,
                COUNT(*) AS total_calls,
                COUNT(*) FILTER (WHERE status = 'completed') AS completed,
                COUNT(*) FILTER (WHERE status = 'ai_handling') AS ai_handled,
                COUNT(*) FILTER (WHERE status = 'missed') AS missed
            FROM om_callcenter_calls
            WHERE created_at::date >= ? AND created_at::date <= ?
            GROUP BY day
            ORDER BY day ASC
        ");
        $trendStmt->execute([$startDate, $endDate]);
        $dailyTrend = $trendStmt->fetchAll();
        foreach ($dailyTrend as &$d) {
            $d['total_calls'] = (int)$d['total_calls'];
            $d['completed'] = (int)$d['completed'];
            $d['ai_handled'] = (int)$d['ai_handled'];
            $d['missed'] = (int)$d['missed'];
        }
        unset($d);

        // --- Orders by Store (top stores) ---
        $storeStmt = $db->prepare("
            SELECT store_identified AS store, COUNT(*) AS count
            FROM om_callcenter_calls
            WHERE store_identified IS NOT NULL AND store_identified != ''
            AND created_at::date >= ? AND created_at::date <= ?
            GROUP BY store_identified
            ORDER BY count DESC
            LIMIT 8
        ");
        $storeStmt->execute([$startDate, $endDate]);
        $topStores = $storeStmt->fetchAll();
        foreach ($topStores as &$ts) {
            $ts['count'] = (int)$ts['count'];
        }
        unset($ts);

        response(true, [
            'kpis' => [
                'total_calls' => (int)$kpis['total_calls'],
                'completed_calls' => (int)$kpis['completed_calls'],
                'missed_calls' => (int)$kpis['missed_calls'],
                'ai_handled' => $aiHandled,
                'ai_resolution_rate' => $aiResolutionRate,
                'avg_turns' => $avgTurns,
                'avg_duration_seconds' => (int)$kpis['avg_duration'],
                'avg_wait_seconds' => (int)$kpis['avg_wait'],
                'total_orders' => (int)$orders['total_orders'],
                'total_revenue' => round((float)$orders['total_revenue'], 2),
                'avg_csat' => $csat['avg_csat'] !== null ? (float)$csat['avg_csat'] : null,
                'csat_responses' => (int)($csat['csat_responses'] ?? 0),
            ],
            'charts' => [
                'calls_by_hour' => $callsByHour,
                'resolution' => [
                    'ai_resolved' => (int)$resolution['ai_resolved'],
                    'agent_resolved' => (int)$resolution['agent_resolved'],
                    'missed' => (int)$resolution['missed'],
                    'voicemail' => (int)$resolution['voicemail'],
                ],
                'top_reasons' => $topReasons,
                'sentiments' => $sentiments,
                'daily_trend' => $dailyTrend,
                'top_stores' => $topStores,
            ],
            'period' => ['start' => $startDate, 'end' => $endDate, 'label' => $period],
        ]);
    }

    // ══════════════════════════════════════════════════════════════
    // SECTION: CALLS (history)
    // ══════════════════════════════════════════════════════════════
    if ($section === 'calls') {

        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 30)));
        $offset = ($page - 1) * $limit;

        $conditions = ["c.created_at::date >= ?", "c.created_at::date <= ?"];
        $params = [$startDate, $endDate];

        if (!empty($_GET['status'])) {
            $allowed = ['queued','ringing','ai_handling','in_progress','on_hold','completed','missed','voicemail','callback'];
            if (in_array($_GET['status'], $allowed, true)) {
                $conditions[] = "c.status = ?";
                $params[] = $_GET['status'];
            }
        }

        if (!empty($_GET['direction'])) {
            if (in_array($_GET['direction'], ['inbound','outbound'], true)) {
                $conditions[] = "c.direction = ?";
                $params[] = $_GET['direction'];
            }
        }

        if (!empty($_GET['outcome'])) {
            if ($_GET['outcome'] === 'order_placed') {
                $conditions[] = "c.order_id IS NOT NULL";
            } elseif ($_GET['outcome'] === 'no_order') {
                $conditions[] = "c.order_id IS NULL";
            } elseif ($_GET['outcome'] === 'ai_resolved') {
                $conditions[] = "c.status = 'ai_handling'";
            }
        }

        if (!empty($_GET['store'])) {
            $conditions[] = "c.store_identified ILIKE ?";
            $params[] = '%' . str_replace(['%','_'], ['\\%','\\_'], $_GET['store']) . '%';
        }

        if (!empty($_GET['search'])) {
            $escaped = str_replace(['%','_'], ['\\%','\\_'], $_GET['search']);
            $conditions[] = "(c.customer_phone ILIKE ? OR c.customer_name ILIKE ?)";
            $searchParam = '%' . $escaped . '%';
            $params[] = $searchParam;
            $params[] = $searchParam;
        }

        $where = 'WHERE ' . implode(' AND ', $conditions);

        // Count
        $countStmt = $db->prepare("SELECT COUNT(*) FROM om_callcenter_calls c {$where}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        // Fetch
        $params[] = $limit;
        $params[] = $offset;
        $stmt = $db->prepare("
            SELECT c.id, c.customer_phone, c.customer_name, c.customer_id,
                   c.agent_id, c.direction, c.status, c.duration_seconds,
                   c.ai_summary, c.ai_sentiment, c.ai_tags, c.notes,
                   c.order_id, c.store_identified, c.transcription,
                   c.callback_requested, c.wait_time_seconds,
                   c.started_at, c.answered_at, c.ended_at, c.created_at,
                   a.display_name AS agent_name
            FROM om_callcenter_calls c
            LEFT JOIN om_callcenter_agents a ON a.id = c.agent_id
            {$where}
            ORDER BY c.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($params);
        $calls = $stmt->fetchAll();

        foreach ($calls as &$call) {
            $call['id'] = (int)$call['id'];
            $call['customer_id'] = $call['customer_id'] ? (int)$call['customer_id'] : null;
            $call['agent_id'] = $call['agent_id'] ? (int)$call['agent_id'] : null;
            $call['duration_seconds'] = $call['duration_seconds'] ? (int)$call['duration_seconds'] : null;
            $call['order_id'] = $call['order_id'] ? (int)$call['order_id'] : null;
            $call['wait_time_seconds'] = $call['wait_time_seconds'] ? (int)$call['wait_time_seconds'] : null;
            $call['callback_requested'] = (bool)$call['callback_requested'];
            // Parse ai_tags from PG array to JSON array
            if ($call['ai_tags'] && is_string($call['ai_tags'])) {
                $call['ai_tags'] = str_replace(['{','}'], '', $call['ai_tags']);
                $call['ai_tags'] = $call['ai_tags'] ? explode(',', $call['ai_tags']) : [];
            }
        }
        unset($call);

        response(true, [
            'calls' => $calls,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => (int)ceil($total / $limit),
        ]);
    }

    // ══════════════════════════════════════════════════════════════
    // SECTION: AI PERFORMANCE
    // ══════════════════════════════════════════════════════════════
    if ($section === 'ai_performance') {

        // Success funnel
        $funnelStmt = $db->prepare("
            SELECT
                COUNT(*) AS total_calls,
                COUNT(*) FILTER (WHERE store_identified IS NOT NULL AND store_identified != '') AS store_identified,
                COUNT(*) FILTER (WHERE order_id IS NOT NULL) AS order_placed,
                COUNT(*) FILTER (WHERE status = 'ai_handling') AS ai_handled,
                COUNT(*) FILTER (WHERE status = 'completed' AND agent_id IS NOT NULL) AS escalated_to_agent
            FROM om_callcenter_calls
            WHERE created_at::date >= ? AND created_at::date <= ?
        ");
        $funnelStmt->execute([$startDate, $endDate]);
        $funnel = $funnelStmt->fetch();

        // Items added estimation (from drafts linked to calls)
        $itemsStmt = $db->prepare("
            SELECT COUNT(*) AS drafts_with_items
            FROM om_callcenter_order_drafts d
            JOIN om_callcenter_calls c ON c.id = d.call_id
            WHERE d.items != '[]'::jsonb AND d.items IS NOT NULL
            AND c.created_at::date >= ? AND c.created_at::date <= ?
        ");
        $itemsStmt->execute([$startDate, $endDate]);
        $itemsDrafts = (int)$itemsStmt->fetch()['drafts_with_items'];

        // Common failure points (calls without orders by status)
        $failureStmt = $db->prepare("
            SELECT
                status,
                COUNT(*) AS count,
                ROUND(COUNT(*)::numeric / GREATEST(
                    (SELECT COUNT(*) FROM om_callcenter_calls
                     WHERE created_at::date >= ? AND created_at::date <= ?
                     AND order_id IS NULL), 1
                ) * 100, 1) AS pct
            FROM om_callcenter_calls
            WHERE order_id IS NULL
            AND created_at::date >= ? AND created_at::date <= ?
            GROUP BY status
            ORDER BY count DESC
        ");
        $failureStmt->execute([$startDate, $endDate, $startDate, $endDate]);
        $failurePoints = $failureStmt->fetchAll();
        foreach ($failurePoints as &$fp) {
            $fp['count'] = (int)$fp['count'];
            $fp['pct'] = (float)$fp['pct'];
        }
        unset($fp);

        // Sentiment analysis for AI-handled calls
        $aiSentimentStmt = $db->prepare("
            SELECT
                COALESCE(ai_sentiment, 'unknown') AS sentiment,
                COUNT(*) AS count
            FROM om_callcenter_calls
            WHERE status = 'ai_handling'
            AND created_at::date >= ? AND created_at::date <= ?
            GROUP BY ai_sentiment
            ORDER BY count DESC
        ");
        $aiSentimentStmt->execute([$startDate, $endDate]);
        $aiSentiments = $aiSentimentStmt->fetchAll();
        foreach ($aiSentiments as &$as) {
            $as['count'] = (int)$as['count'];
        }
        unset($as);

        // Average duration for AI vs Agent
        $durationCompStmt = $db->prepare("
            SELECT
                COALESCE(AVG(duration_seconds) FILTER (WHERE status = 'ai_handling' AND duration_seconds > 0), 0)::int AS ai_avg_duration,
                COALESCE(AVG(duration_seconds) FILTER (WHERE status = 'completed' AND agent_id IS NOT NULL AND duration_seconds > 0), 0)::int AS agent_avg_duration
            FROM om_callcenter_calls
            WHERE created_at::date >= ? AND created_at::date <= ?
        ");
        $durationCompStmt->execute([$startDate, $endDate]);
        $durationComp = $durationCompStmt->fetch();

        // Most common tags for AI calls
        $aiTagsStmt = $db->prepare("
            SELECT tag, COUNT(*) AS count
            FROM om_callcenter_calls, UNNEST(ai_tags) AS tag
            WHERE status = 'ai_handling'
            AND created_at::date >= ? AND created_at::date <= ?
            AND ai_tags IS NOT NULL AND array_length(ai_tags, 1) > 0
            GROUP BY tag
            ORDER BY count DESC
            LIMIT 10
        ");
        $aiTagsStmt->execute([$startDate, $endDate]);
        $aiIntents = $aiTagsStmt->fetchAll();
        foreach ($aiIntents as &$ai) {
            $ai['count'] = (int)$ai['count'];
        }
        unset($ai);

        // AI calls by hour
        $aiHourlyStmt = $db->prepare("
            SELECT EXTRACT(HOUR FROM created_at)::int AS hour, COUNT(*) AS count
            FROM om_callcenter_calls
            WHERE status = 'ai_handling'
            AND created_at::date >= ? AND created_at::date <= ?
            GROUP BY hour
            ORDER BY hour
        ");
        $aiHourlyStmt->execute([$startDate, $endDate]);
        $aiHourlyRaw = $aiHourlyStmt->fetchAll();
        $aiByHour = array_fill(0, 24, 0);
        foreach ($aiHourlyRaw as $row) {
            $aiByHour[(int)$row['hour']] = (int)$row['count'];
        }

        response(true, [
            'funnel' => [
                'total_calls' => (int)$funnel['total_calls'],
                'store_identified' => (int)$funnel['store_identified'],
                'items_added' => $itemsDrafts,
                'order_placed' => (int)$funnel['order_placed'],
                'ai_handled' => (int)$funnel['ai_handled'],
                'escalated_to_agent' => (int)$funnel['escalated_to_agent'],
            ],
            'failure_points' => $failurePoints,
            'ai_sentiments' => $aiSentiments,
            'duration_comparison' => [
                'ai_avg_seconds' => (int)$durationComp['ai_avg_duration'],
                'agent_avg_seconds' => (int)$durationComp['agent_avg_duration'],
            ],
            'ai_intents' => $aiIntents,
            'ai_by_hour' => $aiByHour,
            'period' => ['start' => $startDate, 'end' => $endDate],
        ]);
    }

    // ══════════════════════════════════════════════════════════════
    // SECTION: CUSTOMERS
    // ══════════════════════════════════════════════════════════════
    if ($section === 'customers') {

        // Top callers
        $topCallersStmt = $db->prepare("
            SELECT
                customer_phone,
                MAX(customer_name) AS customer_name,
                MAX(customer_id) AS customer_id,
                COUNT(*) AS call_count,
                COUNT(*) FILTER (WHERE order_id IS NOT NULL) AS orders_placed,
                COALESCE(SUM(duration_seconds), 0) AS total_duration,
                MAX(created_at) AS last_call
            FROM om_callcenter_calls
            WHERE created_at::date >= ? AND created_at::date <= ?
            AND customer_phone IS NOT NULL AND customer_phone != ''
            GROUP BY customer_phone
            ORDER BY call_count DESC
            LIMIT 20
        ");
        $topCallersStmt->execute([$startDate, $endDate]);
        $topCallers = $topCallersStmt->fetchAll();
        foreach ($topCallers as &$tc) {
            $tc['call_count'] = (int)$tc['call_count'];
            $tc['orders_placed'] = (int)$tc['orders_placed'];
            $tc['total_duration'] = (int)$tc['total_duration'];
            $tc['customer_id'] = $tc['customer_id'] ? (int)$tc['customer_id'] : null;
        }
        unset($tc);

        // Repeat vs New (has customer_id = existing, no customer_id = new)
        $repeatStmt = $db->prepare("
            SELECT
                COUNT(*) FILTER (WHERE customer_id IS NOT NULL) AS returning_calls,
                COUNT(*) FILTER (WHERE customer_id IS NULL) AS new_calls,
                COUNT(DISTINCT customer_id) FILTER (WHERE customer_id IS NOT NULL) AS unique_returning,
                COUNT(DISTINCT customer_phone) FILTER (WHERE customer_id IS NULL AND customer_phone IS NOT NULL) AS unique_new
            FROM om_callcenter_calls
            WHERE created_at::date >= ? AND created_at::date <= ?
        ");
        $repeatStmt->execute([$startDate, $endDate]);
        $repeatData = $repeatStmt->fetch();

        // Avg order value by channel
        $aovStmt = $db->prepare("
            SELECT
                COALESCE(AVG(total) FILTER (WHERE source = 'callcenter'), 0) AS phone_aov,
                COALESCE(AVG(total) FILTER (WHERE source != 'callcenter' OR source IS NULL), 0) AS app_aov,
                COUNT(*) FILTER (WHERE source = 'callcenter') AS phone_orders,
                COUNT(*) FILTER (WHERE source != 'callcenter' OR source IS NULL) AS app_orders
            FROM om_market_orders
            WHERE date_added::date >= ? AND date_added::date <= ?
            AND status NOT IN ('cancelado','recusado')
        ");
        $aovStmt->execute([$startDate, $endDate]);
        $aov = $aovStmt->fetch();

        // Peak calling hours by day of week
        $peakStmt = $db->prepare("
            SELECT
                EXTRACT(DOW FROM created_at)::int AS dow,
                EXTRACT(HOUR FROM created_at)::int AS hour,
                COUNT(*) AS count
            FROM om_callcenter_calls
            WHERE created_at::date >= ? AND created_at::date <= ?
            GROUP BY dow, hour
            ORDER BY count DESC
            LIMIT 20
        ");
        $peakStmt->execute([$startDate, $endDate]);
        $peakHours = $peakStmt->fetchAll();
        foreach ($peakHours as &$ph) {
            $ph['dow'] = (int)$ph['dow'];
            $ph['hour'] = (int)$ph['hour'];
            $ph['count'] = (int)$ph['count'];
        }
        unset($ph);

        // Sentiment by type (AI vs Agent)
        $satCompStmt = $db->prepare("
            SELECT
                CASE WHEN agent_id IS NULL THEN 'ai' ELSE 'agent' END AS handler,
                COALESCE(ai_sentiment, 'unknown') AS sentiment,
                COUNT(*) AS count
            FROM om_callcenter_calls
            WHERE created_at::date >= ? AND created_at::date <= ?
            AND status IN ('completed','ai_handling')
            GROUP BY handler, ai_sentiment
            ORDER BY handler, count DESC
        ");
        $satCompStmt->execute([$startDate, $endDate]);
        $satisfactionComp = $satCompStmt->fetchAll();
        foreach ($satisfactionComp as &$sc) {
            $sc['count'] = (int)$sc['count'];
        }
        unset($sc);

        // Common complaints (negative sentiment tags)
        $complaintsStmt = $db->prepare("
            SELECT tag, COUNT(*) AS count
            FROM om_callcenter_calls, UNNEST(ai_tags) AS tag
            WHERE ai_sentiment IN ('negative','frustrated')
            AND created_at::date >= ? AND created_at::date <= ?
            AND ai_tags IS NOT NULL AND array_length(ai_tags, 1) > 0
            GROUP BY tag
            ORDER BY count DESC
            LIMIT 10
        ");
        $complaintsStmt->execute([$startDate, $endDate]);
        $complaints = $complaintsStmt->fetchAll();
        foreach ($complaints as &$comp) {
            $comp['count'] = (int)$comp['count'];
        }
        unset($comp);

        response(true, [
            'top_callers' => $topCallers,
            'repeat_vs_new' => [
                'returning_calls' => (int)$repeatData['returning_calls'],
                'new_calls' => (int)$repeatData['new_calls'],
                'unique_returning' => (int)$repeatData['unique_returning'],
                'unique_new' => (int)$repeatData['unique_new'],
            ],
            'avg_order_value' => [
                'phone' => round((float)$aov['phone_aov'], 2),
                'app' => round((float)$aov['app_aov'], 2),
                'phone_orders' => (int)$aov['phone_orders'],
                'app_orders' => (int)$aov['app_orders'],
            ],
            'peak_hours' => $peakHours,
            'satisfaction_comparison' => $satisfactionComp,
            'complaints' => $complaints,
            'period' => ['start' => $startDate, 'end' => $endDate],
        ]);
    }

    // ══════════════════════════════════════════════════════════════
    // SECTION: LIVE
    // ══════════════════════════════════════════════════════════════
    if ($section === 'live') {

        // Active calls
        $activeStmt = $db->query("
            SELECT c.id, c.customer_phone, c.customer_name, c.status, c.direction,
                   c.duration_seconds, c.started_at, c.store_identified,
                   a.display_name AS agent_name, a.status AS agent_status,
                   EXTRACT(EPOCH FROM (NOW() - c.started_at))::int AS elapsed_seconds
            FROM om_callcenter_calls c
            LEFT JOIN om_callcenter_agents a ON a.id = c.agent_id
            WHERE c.status IN ('in_progress','ai_handling','on_hold','ringing','queued')
            ORDER BY c.started_at ASC
        ");
        $activeCalls = $activeStmt->fetchAll();
        foreach ($activeCalls as &$ac) {
            $ac['id'] = (int)$ac['id'];
            $ac['elapsed_seconds'] = (int)$ac['elapsed_seconds'];
            $ac['duration_seconds'] = $ac['duration_seconds'] ? (int)$ac['duration_seconds'] : null;
        }
        unset($ac);

        // Queue
        $queueStmt = $db->query("
            SELECT q.id, q.customer_phone, q.customer_name, q.priority,
                   q.skill_required, q.estimated_wait_seconds, q.position_in_queue,
                   EXTRACT(EPOCH FROM (NOW() - q.queued_at))::int AS waiting_seconds,
                   q.queued_at
            FROM om_callcenter_queue q
            WHERE q.picked_at IS NULL AND q.abandoned_at IS NULL
            ORDER BY q.priority ASC, q.queued_at ASC
        ");
        $queue = $queueStmt->fetchAll();
        foreach ($queue as &$q) {
            $q['id'] = (int)$q['id'];
            $q['priority'] = (int)$q['priority'];
            $q['waiting_seconds'] = (int)$q['waiting_seconds'];
            $q['estimated_wait_seconds'] = $q['estimated_wait_seconds'] ? (int)$q['estimated_wait_seconds'] : null;
            $q['position_in_queue'] = $q['position_in_queue'] ? (int)$q['position_in_queue'] : null;
        }
        unset($q);

        // Agents
        $agentsStmt = $db->query("
            SELECT a.id, a.display_name, a.extension, a.status, a.max_concurrent,
                   (SELECT COUNT(*) FROM om_callcenter_calls c
                    WHERE c.agent_id = a.id AND c.status IN ('in_progress','on_hold')) AS active_calls,
                   (SELECT COUNT(*) FROM om_callcenter_whatsapp w
                    WHERE w.agent_id = a.id AND w.status = 'assigned') AS active_chats
            FROM om_callcenter_agents a
            ORDER BY
                CASE a.status WHEN 'online' THEN 0 WHEN 'busy' THEN 1 WHEN 'break' THEN 2 ELSE 3 END,
                a.display_name
        ");
        $agents = $agentsStmt->fetchAll();
        foreach ($agents as &$ag) {
            $ag['id'] = (int)$ag['id'];
            $ag['max_concurrent'] = (int)$ag['max_concurrent'];
            $ag['active_calls'] = (int)$ag['active_calls'];
            $ag['active_chats'] = (int)$ag['active_chats'];
        }
        unset($ag);

        // Summary counts
        $summary = [
            'active_calls' => count($activeCalls),
            'queue_depth' => count($queue),
            'agents_online' => count(array_filter($agents, fn($a) => $a['status'] === 'online')),
            'agents_busy' => count(array_filter($agents, fn($a) => $a['status'] === 'busy')),
            'agents_break' => count(array_filter($agents, fn($a) => $a['status'] === 'break')),
            'agents_offline' => count(array_filter($agents, fn($a) => $a['status'] === 'offline')),
            'agents_total' => count($agents),
        ];

        response(true, [
            'active_calls' => $activeCalls,
            'queue' => $queue,
            'agents' => $agents,
            'summary' => $summary,
            'timestamp' => date('c'),
        ]);
    }

    response(false, null, "Section invalida. Valores: overview, calls, ai_performance, customers, live", 400);

} catch (Exception $e) {
    error_log("[admin/callcenter-analytics] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
