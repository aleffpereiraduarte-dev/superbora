<?php
/**
 * /api/mercado/admin/callcenter/outbound-calls.php
 *
 * Outbound Call Management — Admin API
 *
 * Initiates, schedules, and manages outbound AI calls.
 * Uses the helper library at /helpers/outbound-calls.php.
 *
 * GET views:
 *   ?view=calls         — List outbound calls with filters/pagination
 *   ?view=call&id=X     — Single call detail
 *   ?view=campaigns     — List campaigns
 *   ?view=campaign&id=X — Campaign detail with stats
 *   ?view=queue         — Scheduled call queue (pending)
 *   ?view=targets       — Smart reengagement targets
 *   ?view=opt_outs      — List opted-out phones
 *   ?view=stats         — Outbound call statistics
 *
 * POST actions:
 *   action=call             — Initiate single outbound call
 *   action=schedule         — Schedule a call for later
 *   action=campaign         — Create bulk campaign
 *   action=process_queue    — Process scheduled calls now
 *   action=cancel_scheduled — Cancel a scheduled call
 *   action=cancel_campaign  — Cancel remaining campaign calls
 *   action=opt_out          — Manually add phone to opt-out
 *   action=remove_opt_out   — Remove phone from opt-out
 */
require_once __DIR__ . '/../../config/database.php';
require_once dirname(__DIR__, 4) . '/includes/classes/OmAuth.php';
require_once dirname(__DIR__, 4) . '/includes/classes/OmAudit.php';
require_once __DIR__ . '/../../helpers/outbound-calls.php';

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();
    $admin_id = (int)$payload['uid'];

    // =================== GET: List / Detail views ===================
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {

        $view = trim($_GET['view'] ?? 'calls');

        // ── List outbound calls with filters ──
        if ($view === 'calls') {
            $page     = max(1, (int)($_GET['page'] ?? 1));
            $perPage  = max(1, min(100, (int)($_GET['per_page'] ?? 20)));
            $offset   = ($page - 1) * $perPage;
            $status   = trim($_GET['status'] ?? '');
            $type     = trim($_GET['type'] ?? '');
            $dateFrom = trim($_GET['date_from'] ?? '');
            $dateTo   = trim($_GET['date_to'] ?? '');
            $search   = trim($_GET['search'] ?? '');

            $where  = [];
            $params = [];

            if ($status !== '') {
                $where[]  = "oc.status = ?";
                $params[] = $status;
            }
            if ($type !== '') {
                $where[]  = "oc.call_type = ?";
                $params[] = $type;
            }
            if ($dateFrom !== '') {
                $where[]  = "oc.created_at >= ?";
                $params[] = $dateFrom;
            }
            if ($dateTo !== '') {
                $where[]  = "oc.created_at <= ? ::date + INTERVAL '1 day'";
                $params[] = $dateTo;
            }
            if ($search !== '') {
                $where[]  = "(oc.phone ILIKE ? OR oc.customer_name ILIKE ?)";
                $like     = '%' . $search . '%';
                $params[] = $like;
                $params[] = $like;
            }

            $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

            // Total count
            $countStmt = $db->prepare("SELECT COUNT(*) FROM om_outbound_calls oc {$whereSql}");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            // Fetch calls
            $params[] = $perPage;
            $params[] = $offset;

            $stmt = $db->prepare("
                SELECT
                    oc.id, oc.twilio_call_sid, oc.phone, oc.customer_id, oc.customer_name,
                    oc.call_type, oc.call_data, oc.status, oc.outcome, oc.outcome_data,
                    oc.duration_seconds, oc.campaign_id, oc.attempts,
                    oc.last_attempt_at, oc.created_at, oc.updated_at
                FROM om_outbound_calls oc
                {$whereSql}
                ORDER BY oc.created_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute($params);
            $calls = $stmt->fetchAll();

            foreach ($calls as &$call) {
                $call['id']               = (int)$call['id'];
                $call['customer_id']      = $call['customer_id'] ? (int)$call['customer_id'] : null;
                $call['duration_seconds']  = $call['duration_seconds'] !== null ? (int)$call['duration_seconds'] : null;
                $call['campaign_id']      = $call['campaign_id'] ? (int)$call['campaign_id'] : null;
                $call['attempts']         = (int)$call['attempts'];
                $call['call_data']        = json_decode($call['call_data'] ?: '{}', true);
                $call['outcome_data']     = json_decode($call['outcome_data'] ?: '{}', true);
            }
            unset($call);

            response(true, [
                'calls' => $calls,
                'total' => $total,
                'page'  => $page,
                'per_page' => $perPage,
                'pages' => (int)ceil($total / $perPage),
            ]);
        }

        // ── Single call detail ──
        if ($view === 'call') {
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) response(false, null, "id obrigatorio", 400);

            $stmt = $db->prepare("
                SELECT
                    oc.id, oc.twilio_call_sid, oc.phone, oc.customer_id, oc.customer_name,
                    oc.call_type, oc.call_data, oc.status, oc.outcome, oc.outcome_data,
                    oc.duration_seconds, oc.campaign_id, oc.recording_url, oc.attempts,
                    oc.last_attempt_at, oc.created_at, oc.updated_at
                FROM om_outbound_calls oc
                WHERE oc.id = ?
            ");
            $stmt->execute([$id]);
            $call = $stmt->fetch();

            if (!$call) response(false, null, "Ligacao nao encontrada", 404);

            $call['id']              = (int)$call['id'];
            $call['customer_id']     = $call['customer_id'] ? (int)$call['customer_id'] : null;
            $call['duration_seconds'] = $call['duration_seconds'] !== null ? (int)$call['duration_seconds'] : null;
            $call['campaign_id']     = $call['campaign_id'] ? (int)$call['campaign_id'] : null;
            $call['attempts']        = (int)$call['attempts'];
            $call['call_data']       = json_decode($call['call_data'] ?: '{}', true);
            $call['outcome_data']    = json_decode($call['outcome_data'] ?: '{}', true);

            // Linked callcenter call record
            $linkedCall = null;
            if (!empty($call['twilio_call_sid'])) {
                $lStmt = $db->prepare("
                    SELECT id, agent_id, status, started_at, ended_at, duration_seconds,
                           ai_transcript, ai_summary
                    FROM om_callcenter_calls
                    WHERE twilio_call_sid = ?
                    LIMIT 1
                ");
                $lStmt->execute([$call['twilio_call_sid']]);
                $linkedCall = $lStmt->fetch() ?: null;
                if ($linkedCall) {
                    $linkedCall['id']               = (int)$linkedCall['id'];
                    $linkedCall['agent_id']          = $linkedCall['agent_id'] ? (int)$linkedCall['agent_id'] : null;
                    $linkedCall['duration_seconds']  = $linkedCall['duration_seconds'] !== null ? (int)$linkedCall['duration_seconds'] : null;
                }
            }

            response(true, [
                'call'        => $call,
                'linked_call' => $linkedCall,
            ]);
        }

        // ── List campaigns ──
        if ($view === 'campaigns') {
            $stmt = $db->query("
                SELECT
                    id, name, call_type, call_data,
                    total_targets, calls_made, calls_answered, calls_opted_out, calls_ordered,
                    status, created_at, completed_at
                FROM om_outbound_campaigns
                ORDER BY created_at DESC
            ");
            $campaigns = $stmt->fetchAll();

            foreach ($campaigns as &$camp) {
                $camp['id']              = (int)$camp['id'];
                $camp['total_targets']   = (int)$camp['total_targets'];
                $camp['calls_made']      = (int)$camp['calls_made'];
                $camp['calls_answered']  = (int)$camp['calls_answered'];
                $camp['calls_opted_out'] = (int)$camp['calls_opted_out'];
                $camp['calls_ordered']   = (int)$camp['calls_ordered'];
                $camp['call_data']       = json_decode($camp['call_data'] ?: '{}', true);
            }
            unset($camp);

            response(true, ['campaigns' => $campaigns]);
        }

        // ── Campaign detail with call breakdown ──
        if ($view === 'campaign') {
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) response(false, null, "id obrigatorio", 400);

            $stmt = $db->prepare("
                SELECT
                    id, name, call_type, call_data,
                    total_targets, calls_made, calls_answered, calls_opted_out, calls_ordered,
                    status, created_at, completed_at
                FROM om_outbound_campaigns
                WHERE id = ?
            ");
            $stmt->execute([$id]);
            $campaign = $stmt->fetch();

            if (!$campaign) response(false, null, "Campanha nao encontrada", 404);

            $campaign['id']              = (int)$campaign['id'];
            $campaign['total_targets']   = (int)$campaign['total_targets'];
            $campaign['calls_made']      = (int)$campaign['calls_made'];
            $campaign['calls_answered']  = (int)$campaign['calls_answered'];
            $campaign['calls_opted_out'] = (int)$campaign['calls_opted_out'];
            $campaign['calls_ordered']   = (int)$campaign['calls_ordered'];
            $campaign['call_data']       = json_decode($campaign['call_data'] ?: '{}', true);

            // Campaign calls
            $cStmt = $db->prepare("
                SELECT
                    id, phone, customer_name, status, outcome,
                    duration_seconds, created_at
                FROM om_outbound_calls
                WHERE campaign_id = ?
                ORDER BY created_at DESC
            ");
            $cStmt->execute([$id]);
            $calls = $cStmt->fetchAll();

            foreach ($calls as &$c) {
                $c['id']               = (int)$c['id'];
                $c['duration_seconds'] = $c['duration_seconds'] !== null ? (int)$c['duration_seconds'] : null;
            }
            unset($c);

            // Pending queue items for this campaign
            $qStmt = $db->prepare("
                SELECT id, phone, customer_name, scheduled_at, status
                FROM om_outbound_call_queue
                WHERE campaign_id = ? AND status = 'pending'
                ORDER BY scheduled_at ASC
            ");
            $qStmt->execute([$id]);
            $pending = $qStmt->fetchAll();

            foreach ($pending as &$p) {
                $p['id'] = (int)$p['id'];
            }
            unset($p);

            response(true, [
                'campaign'      => $campaign,
                'calls'         => $calls,
                'pending_queue' => $pending,
            ]);
        }

        // ── Scheduled call queue ──
        if ($view === 'queue') {
            $stmt = $db->query("
                SELECT
                    q.id, q.phone, q.customer_id, q.customer_name,
                    q.call_type, q.call_data, q.campaign_id,
                    q.scheduled_at, q.priority, q.status,
                    q.error_message, q.created_at, q.processed_at,
                    camp.name AS campaign_name
                FROM om_outbound_call_queue q
                LEFT JOIN om_outbound_campaigns camp ON camp.id = q.campaign_id
                WHERE q.status IN ('pending', 'processing')
                ORDER BY q.priority ASC, q.scheduled_at ASC
            ");
            $queue = $stmt->fetchAll();

            foreach ($queue as &$item) {
                $item['id']          = (int)$item['id'];
                $item['customer_id'] = $item['customer_id'] ? (int)$item['customer_id'] : null;
                $item['campaign_id'] = $item['campaign_id'] ? (int)$item['campaign_id'] : null;
                $item['priority']    = (int)$item['priority'];
                $item['call_data']   = json_decode($item['call_data'] ?: '{}', true);
            }
            unset($item);

            response(true, ['queue' => $queue]);
        }

        // ── Smart reengagement targets ──
        if ($view === 'targets') {
            $limit = max(1, min(100, (int)($_GET['limit'] ?? 50)));
            $targets = findReengagementTargets($db, $limit);
            response(true, ['targets' => $targets, 'total' => count($targets)]);
        }

        // ── List opt-outs ──
        if ($view === 'opt_outs') {
            $stmt = $db->query("
                SELECT id, phone, customer_id, reason, opted_out_at
                FROM om_outbound_opt_outs
                ORDER BY opted_out_at DESC
            ");
            $optOuts = $stmt->fetchAll();

            foreach ($optOuts as &$o) {
                $o['id']          = (int)$o['id'];
                $o['customer_id'] = $o['customer_id'] ? (int)$o['customer_id'] : null;
            }
            unset($o);

            response(true, ['opt_outs' => $optOuts]);
        }

        // ── Outbound call statistics ──
        if ($view === 'stats') {
            $today     = date('Y-m-d');
            $weekStart = date('Y-m-d', strtotime('monday this week'));
            $monthStart = date('Y-m-01');

            // Today's stats
            $todayStmt = $db->prepare("
                SELECT
                    COUNT(*)                                                              AS calls_made,
                    COUNT(*) FILTER (WHERE status = 'answered' OR status = 'completed')   AS calls_answered,
                    COUNT(*) FILTER (WHERE outcome = 'opt_out')                           AS calls_opted_out,
                    COUNT(*) FILTER (WHERE outcome = 'ordered')                           AS orders_placed
                FROM om_outbound_calls
                WHERE created_at::date = ?
            ");
            $todayStmt->execute([$today]);
            $todayStats = $todayStmt->fetch();

            // This week
            $weekStmt = $db->prepare("
                SELECT
                    COUNT(*)                                                              AS calls_made,
                    COUNT(*) FILTER (WHERE status = 'answered' OR status = 'completed')   AS calls_answered,
                    COUNT(*) FILTER (WHERE outcome = 'opt_out')                           AS calls_opted_out,
                    COUNT(*) FILTER (WHERE outcome = 'ordered')                           AS orders_placed
                FROM om_outbound_calls
                WHERE created_at::date >= ?
            ");
            $weekStmt->execute([$weekStart]);
            $weekStats = $weekStmt->fetch();

            // This month
            $monthStmt = $db->prepare("
                SELECT
                    COUNT(*)                                                              AS calls_made,
                    COUNT(*) FILTER (WHERE status = 'answered' OR status = 'completed')   AS calls_answered,
                    COUNT(*) FILTER (WHERE outcome = 'opt_out')                           AS calls_opted_out,
                    COUNT(*) FILTER (WHERE outcome = 'ordered')                           AS orders_placed
                FROM om_outbound_calls
                WHERE created_at::date >= ?
            ");
            $monthStmt->execute([$monthStart]);
            $monthStats = $monthStmt->fetch();

            // Outcome breakdown (all time — or last 30 days for relevance)
            $outcomeStmt = $db->query("
                SELECT status, COUNT(*) AS cnt
                FROM om_outbound_calls
                WHERE created_at >= NOW() - INTERVAL '30 days'
                GROUP BY status
                ORDER BY cnt DESC
            ");
            $outcomeBreakdown = [];
            foreach ($outcomeStmt->fetchAll() as $row) {
                $outcomeBreakdown[$row['status']] = (int)$row['cnt'];
            }

            // Best time of day (hour with highest answer rate, last 30 days)
            $bestTimeStmt = $db->query("
                SELECT
                    EXTRACT(HOUR FROM created_at)::int AS hour,
                    COUNT(*)                           AS total,
                    COUNT(*) FILTER (WHERE status IN ('answered', 'completed')) AS answered,
                    CASE WHEN COUNT(*) > 0
                        THEN ROUND(100.0 * COUNT(*) FILTER (WHERE status IN ('answered', 'completed')) / COUNT(*), 1)
                        ELSE 0
                    END AS answer_rate
                FROM om_outbound_calls
                WHERE created_at >= NOW() - INTERVAL '30 days'
                GROUP BY EXTRACT(HOUR FROM created_at)::int
                HAVING COUNT(*) >= 3
                ORDER BY answer_rate DESC
                LIMIT 1
            ");
            $bestTime = $bestTimeStmt->fetch();

            $castInt = function ($row) {
                return [
                    'calls_made'    => (int)($row['calls_made'] ?? 0),
                    'calls_answered' => (int)($row['calls_answered'] ?? 0),
                    'calls_opted_out' => (int)($row['calls_opted_out'] ?? 0),
                    'orders_placed' => (int)($row['orders_placed'] ?? 0),
                ];
            };

            response(true, [
                'today'              => $castInt($todayStats),
                'this_week'          => $castInt($weekStats),
                'this_month'         => $castInt($monthStats),
                'outcome_breakdown'  => $outcomeBreakdown,
                'best_time_of_day'   => $bestTime ? [
                    'hour'        => (int)$bestTime['hour'],
                    'total'       => (int)$bestTime['total'],
                    'answered'    => (int)$bestTime['answered'],
                    'answer_rate' => (float)$bestTime['answer_rate'],
                ] : null,
            ]);
        }

        response(false, null, "View invalida. Valores: calls, call, campaigns, campaign, queue, targets, opt_outs, stats", 400);
    }

    // =================== POST: Outbound actions ===================
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        $input  = getInput();
        $action = trim($input['action'] ?? '');

        // ── Initiate single outbound call ──
        if ($action === 'call') {
            $phone = trim($input['phone'] ?? '');
            $type  = trim($input['type'] ?? '');
            $data  = $input['data'] ?? [];

            if (!$phone) response(false, null, "phone obrigatorio", 400);
            if (!$type)  response(false, null, "type obrigatorio", 400);

            $validTypes = ['order_confirm', 'delivery_update', 'reengagement', 'survey', 'promo', 'custom'];
            if (!in_array($type, $validTypes, true)) {
                response(false, null, "type invalido. Valores: " . implode(', ', $validTypes), 400);
            }

            // Validate calling hours (9h-21h Sao Paulo)
            if (!isWithinCallingHours()) {
                response(false, null, "Fora do horario de ligacoes (9h-21h)", 400);
            }

            // Check opt-out before calling helper
            $formattedPhone = formatPhoneForTwilio($phone);
            if ($formattedPhone && isPhoneOptedOut($db, $formattedPhone)) {
                response(false, null, "Numero optou por nao receber ligacoes", 400);
            }

            if (!is_array($data)) {
                $data = json_decode($data, true) ?: [];
            }

            $result = initiateOutboundCall($db, $phone, $type, $data);

            if (!$result['success']) {
                response(false, null, $result['error'] ?? 'Erro ao iniciar ligacao', 400);
            }

            om_audit()->log(
                'outbound_call_initiate',
                'outbound_call',
                $result['outbound_id'] ?? 0,
                null,
                ['phone' => substr($phone, 0, 6) . '***', 'type' => $type],
                "Ligacao outbound iniciada: tipo={$type}"
            );

            response(true, [
                'call_sid' => $result['call_sid'] ?? null,
                'call_id'  => $result['outbound_id'] ?? null,
            ], "Ligacao iniciada com sucesso");
        }

        // ── Schedule a call for later ──
        if ($action === 'schedule') {
            $phone       = trim($input['phone'] ?? '');
            $type        = trim($input['type'] ?? '');
            $scheduledAt = trim($input['scheduled_at'] ?? '');
            $data        = $input['data'] ?? [];
            $priority    = max(1, min(10, (int)($input['priority'] ?? 5)));

            if (!$phone)       response(false, null, "phone obrigatorio", 400);
            if (!$type)        response(false, null, "type obrigatorio", 400);
            if (!$scheduledAt) response(false, null, "scheduled_at obrigatorio", 400);

            $validTypes = ['order_confirm', 'delivery_update', 'reengagement', 'survey', 'promo', 'custom'];
            if (!in_array($type, $validTypes, true)) {
                response(false, null, "type invalido. Valores: " . implode(', ', $validTypes), 400);
            }

            // Validate scheduled_at is in the future
            $scheduledTs = strtotime($scheduledAt);
            if ($scheduledTs === false || $scheduledTs < time()) {
                response(false, null, "scheduled_at deve ser uma data futura valida", 400);
            }

            if (!is_array($data)) {
                $data = json_decode($data, true) ?: [];
            }

            $result = scheduleOutboundCall($db, $phone, $type, $data, $scheduledAt, $priority);

            if (!$result['success']) {
                response(false, null, $result['error'] ?? 'Erro ao agendar ligacao', 400);
            }

            om_audit()->log(
                'outbound_call_schedule',
                'outbound_call_queue',
                $result['queue_id'] ?? 0,
                null,
                ['phone' => substr($phone, 0, 6) . '***', 'type' => $type, 'scheduled_at' => $scheduledAt],
                "Ligacao agendada: tipo={$type} para={$scheduledAt}"
            );

            response(true, [
                'queue_id'     => $result['queue_id'] ?? null,
                'scheduled_at' => $scheduledAt,
            ], "Ligacao agendada com sucesso");
        }

        // ── Create bulk campaign ──
        if ($action === 'campaign') {
            $name   = trim($input['name'] ?? '');
            $type   = trim($input['type'] ?? '');
            $phones = $input['phones'] ?? [];
            $data   = $input['data'] ?? [];

            if (!$name)          response(false, null, "name obrigatorio", 400);
            if (!$type)          response(false, null, "type obrigatorio", 400);
            if (empty($phones))  response(false, null, "phones obrigatorio (array)", 400);
            if (!is_array($phones)) response(false, null, "phones deve ser um array", 400);

            $validTypes = ['order_confirm', 'delivery_update', 'reengagement', 'survey', 'promo', 'custom'];
            if (!in_array($type, $validTypes, true)) {
                response(false, null, "type invalido. Valores: " . implode(', ', $validTypes), 400);
            }

            if (!is_array($data)) {
                $data = json_decode($data, true) ?: [];
            }

            $result = bulkOutboundCampaign($db, $name, $phones, $type, $data);

            if (!$result['success']) {
                response(false, null, $result['error'] ?? 'Erro ao criar campanha', 400);
            }

            om_audit()->log(
                'outbound_campaign_create',
                'outbound_campaign',
                $result['campaign_id'] ?? 0,
                null,
                ['name' => $name, 'type' => $type, 'queued' => $result['queued'], 'skipped' => $result['skipped']],
                "Campanha '{$name}' criada: {$result['queued']} agendadas, {$result['skipped']} ignoradas"
            );

            response(true, [
                'campaign_id' => $result['campaign_id'] ?? null,
                'queued'      => $result['queued'] ?? 0,
                'skipped'     => $result['skipped'] ?? 0,
            ], "Campanha criada com sucesso");
        }

        // ── Process scheduled calls now ──
        if ($action === 'process_queue') {
            $batchSize = max(1, min(50, (int)($input['batch_size'] ?? 10)));

            $result = processScheduledCalls($db, $batchSize);

            om_audit()->log(
                'outbound_queue_process',
                'outbound_call_queue',
                0,
                null,
                $result,
                "Fila processada: {$result['processed']} processadas, {$result['succeeded']} sucesso, {$result['failed']} falhas"
            );

            response(true, $result, "Fila processada");
        }

        // ── Cancel a scheduled call ──
        if ($action === 'cancel_scheduled') {
            $queueId = (int)($input['queue_id'] ?? 0);
            if (!$queueId) response(false, null, "queue_id obrigatorio", 400);

            $stmt = $db->prepare("
                DELETE FROM om_outbound_call_queue
                WHERE id = ? AND status = 'pending'
            ");
            $stmt->execute([$queueId]);

            if ($stmt->rowCount() === 0) {
                response(false, null, "Item nao encontrado ou ja processado", 404);
            }

            om_audit()->log(
                'outbound_scheduled_cancel',
                'outbound_call_queue',
                $queueId,
                null,
                null,
                "Ligacao agendada #{$queueId} cancelada"
            );

            response(true, ['queue_id' => $queueId], "Ligacao agendada cancelada");
        }

        // ── Cancel remaining campaign calls ──
        if ($action === 'cancel_campaign') {
            $campaignId = (int)($input['campaign_id'] ?? 0);
            if (!$campaignId) response(false, null, "campaign_id obrigatorio", 400);

            // Verify campaign exists
            $stmt = $db->prepare("SELECT id, name FROM om_outbound_campaigns WHERE id = ?");
            $stmt->execute([$campaignId]);
            $campaign = $stmt->fetch();
            if (!$campaign) response(false, null, "Campanha nao encontrada", 404);

            // Cancel pending queue items
            $stmt = $db->prepare("
                UPDATE om_outbound_call_queue
                SET status = 'cancelled', processed_at = NOW()
                WHERE campaign_id = ? AND status = 'pending'
            ");
            $stmt->execute([$campaignId]);
            $cancelled = $stmt->rowCount();

            // Update campaign status
            $db->prepare("
                UPDATE om_outbound_campaigns
                SET status = 'cancelled', completed_at = NOW()
                WHERE id = ?
            ")->execute([$campaignId]);

            om_audit()->log(
                'outbound_campaign_cancel',
                'outbound_campaign',
                $campaignId,
                null,
                ['cancelled_count' => $cancelled],
                "Campanha '{$campaign['name']}' cancelada: {$cancelled} ligacoes pendentes canceladas"
            );

            response(true, [
                'campaign_id' => $campaignId,
                'cancelled'   => $cancelled,
            ], "Campanha cancelada");
        }

        // ── Manually add phone to opt-out ──
        if ($action === 'opt_out') {
            $phone  = trim($input['phone'] ?? '');
            $reason = trim($input['reason'] ?? 'Adicionado manualmente pelo admin');

            if (!$phone) response(false, null, "phone obrigatorio", 400);

            $formatted = formatPhoneForTwilio($phone);
            if (empty($formatted)) {
                response(false, null, "Numero de telefone invalido", 400);
            }

            // Look up customer for linking
            $customerInfo = lookupCustomerByPhone($db, $formatted);
            $customerId   = $customerInfo['customer_id'] ?? null;

            recordOptOut($db, $formatted, $customerId, $reason);

            om_audit()->log(
                'outbound_opt_out_add',
                'outbound_opt_out',
                0,
                null,
                ['phone' => substr($formatted, 0, 6) . '***', 'reason' => $reason],
                "Opt-out adicionado: " . substr($formatted, 0, 6) . '***'
            );

            response(true, ['phone' => $formatted], "Numero adicionado a lista de opt-out");
        }

        // ── Remove phone from opt-out ──
        if ($action === 'remove_opt_out') {
            $phone = trim($input['phone'] ?? '');
            if (!$phone) response(false, null, "phone obrigatorio", 400);

            $formatted = formatPhoneForTwilio($phone);
            if (empty($formatted)) {
                response(false, null, "Numero de telefone invalido", 400);
            }

            // Try deleting with the formatted phone and common variants
            $digits = preg_replace('/\D/', '', $formatted);
            $variants = [$formatted, $digits];
            if (strlen($digits) === 13 && str_starts_with($digits, '55')) {
                $variants[] = '+' . $digits;
                $variants[] = substr($digits, 2);
            }

            $placeholders = implode(',', array_fill(0, count($variants), '?'));
            $stmt = $db->prepare("DELETE FROM om_outbound_opt_outs WHERE phone IN ({$placeholders})");
            $stmt->execute($variants);

            if ($stmt->rowCount() === 0) {
                response(false, null, "Numero nao encontrado na lista de opt-out", 404);
            }

            om_audit()->log(
                'outbound_opt_out_remove',
                'outbound_opt_out',
                0,
                null,
                ['phone' => substr($formatted, 0, 6) . '***'],
                "Opt-out removido: " . substr($formatted, 0, 6) . '***'
            );

            response(true, ['phone' => $formatted], "Numero removido da lista de opt-out");
        }

        response(false, null, "Acao invalida. Valores: call, schedule, campaign, process_queue, cancel_scheduled, cancel_campaign, opt_out, remove_opt_out", 400);
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[admin/callcenter/outbound-calls] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
