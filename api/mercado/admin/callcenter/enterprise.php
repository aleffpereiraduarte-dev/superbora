<?php
/**
 * /api/mercado/admin/callcenter/enterprise.php
 *
 * Consolidated Enterprise Features — Admin API
 *
 * GET actions:
 *   ?action=quality_dashboard&period=7d     — AI Quality scores dashboard
 *   ?action=quality_detail&id=X             — Single conversation quality details
 *   ?action=clv_dashboard                   — Customer Lifetime Value dashboard
 *   ?action=clv_customer&customer_id=X      — Single customer CLV details
 *   ?action=proactive_dashboard&period=7d   — Proactive resolution dashboard
 *   ?action=lgpd_dashboard&period=30d       — LGPD compliance dashboard
 *   ?action=lgpd_search&phone=X             — Search customer data for LGPD request
 *   ?action=retry_dashboard&period=24h      — Retry/fallback dashboard
 *
 * POST actions:
 *   action=quality_review     — Admin reviews flagged conversation
 *   action=clv_recalculate    — Trigger CLV recalculation for all customers
 *   action=lgpd_delete        — Process data deletion request
 *   action=lgpd_export        — Export customer data (portability)
 */
require_once __DIR__ . '/../../config/database.php';
require_once dirname(__DIR__, 4) . '/includes/classes/OmAuth.php';
require_once dirname(__DIR__, 4) . '/includes/classes/OmAudit.php';

setCorsHeaders();

/**
 * Parse period string like '7d', '30d', '24h' into SQL interval.
 */
function parsePeriodToInterval(string $period): string
{
    if (preg_match('/^(\d+)d$/', $period, $m)) {
        return (int)$m[1] . ' days';
    }
    if (preg_match('/^(\d+)h$/', $period, $m)) {
        return (int)$m[1] . ' hours';
    }
    return '7 days';
}

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();
    $adminId = (int)$payload['uid'];

    $method = $_SERVER['REQUEST_METHOD'];

    // ════════════════════════════════════════════════════════════════════
    // GET actions
    // ════════════════════════════════════════════════════════════════════
    if ($method === 'GET') {
        $action = trim($_GET['action'] ?? '');

        if (!$action) {
            response(false, null, "Informe action: quality_dashboard, quality_detail, clv_dashboard, clv_customer, proactive_dashboard, lgpd_dashboard, lgpd_search, retry_dashboard", 400);
        }

        // ══════════ QUALITY DASHBOARD ══════════
        if ($action === 'quality_dashboard') {
            $period = trim($_GET['period'] ?? '7d');
            $interval = parsePeriodToInterval($period);

            // Average scores by dimension (radar chart data)
            $dimensions = $db->prepare("
                SELECT
                    ROUND(AVG(greeting_score), 1) AS greeting,
                    ROUND(AVG(understanding_score), 1) AS understanding,
                    ROUND(AVG(accuracy_score), 1) AS accuracy,
                    ROUND(AVG(upsell_score), 1) AS upsell,
                    ROUND(AVG(tone_score), 1) AS tone,
                    ROUND(AVG(resolution_score), 1) AS resolution,
                    ROUND(AVG(efficiency_score), 1) AS efficiency,
                    ROUND(AVG(overall_score), 1) AS overall,
                    COUNT(*) AS total_scored
                FROM om_ai_quality_scores
                WHERE created_at >= NOW() - INTERVAL '{$interval}'
            ");
            $dimensions->execute();
            $dims = $dimensions->fetch();

            // Score distribution (buckets: 0-20, 21-40, 41-60, 61-80, 81-100)
            $distStmt = $db->prepare("
                SELECT
                    COUNT(*) FILTER (WHERE overall_score BETWEEN 0 AND 20) AS bucket_0_20,
                    COUNT(*) FILTER (WHERE overall_score BETWEEN 21 AND 40) AS bucket_21_40,
                    COUNT(*) FILTER (WHERE overall_score BETWEEN 41 AND 60) AS bucket_41_60,
                    COUNT(*) FILTER (WHERE overall_score BETWEEN 61 AND 80) AS bucket_61_80,
                    COUNT(*) FILTER (WHERE overall_score BETWEEN 81 AND 100) AS bucket_81_100
                FROM om_ai_quality_scores
                WHERE created_at >= NOW() - INTERVAL '{$interval}'
            ");
            $distStmt->execute();
            $dist = $distStmt->fetch();

            // Flagged conversations
            $flaggedStmt = $db->prepare("
                SELECT id, conversation_type, conversation_id, overall_score,
                       issues_detected, conversion_result, created_at
                FROM om_ai_quality_scores
                WHERE flagged_for_review = TRUE
                  AND reviewed_by IS NULL
                  AND created_at >= NOW() - INTERVAL '{$interval}'
                ORDER BY created_at DESC
                LIMIT 20
            ");
            $flaggedStmt->execute();
            $flagged = $flaggedStmt->fetchAll();

            foreach ($flagged as &$f) {
                $f['id'] = (int)$f['id'];
                $f['conversation_id'] = (int)$f['conversation_id'];
                $f['overall_score'] = (int)$f['overall_score'];
                $f['issues_detected'] = json_decode($f['issues_detected'], true) ?: [];
            }
            unset($f);

            // Conversion funnel
            $funnelStmt = $db->prepare("
                SELECT
                    COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE conversion_result = 'order_placed') AS orders,
                    COUNT(*) FILTER (WHERE conversion_result = 'abandoned') AS abandoned,
                    COUNT(*) FILTER (WHERE conversion_result = 'transferred') AS transferred,
                    COUNT(*) FILTER (WHERE conversion_result = 'support_only') AS support_only
                FROM om_ai_quality_scores
                WHERE created_at >= NOW() - INTERVAL '{$interval}'
            ");
            $funnelStmt->execute();
            $funnel = $funnelStmt->fetch();

            // Daily trend
            $trendStmt = $db->prepare("
                SELECT
                    created_at::date AS date,
                    ROUND(AVG(overall_score), 1) AS avg_score,
                    COUNT(*) AS count
                FROM om_ai_quality_scores
                WHERE created_at >= NOW() - INTERVAL '{$interval}'
                GROUP BY created_at::date
                ORDER BY date
            ");
            $trendStmt->execute();
            $trend = $trendStmt->fetchAll();

            foreach ($trend as &$t) {
                $t['avg_score'] = (float)$t['avg_score'];
                $t['count'] = (int)$t['count'];
            }
            unset($t);

            response(true, [
                'period' => $period,
                'dimensions' => [
                    'greeting'      => (float)($dims['greeting'] ?? 0),
                    'understanding' => (float)($dims['understanding'] ?? 0),
                    'accuracy'      => (float)($dims['accuracy'] ?? 0),
                    'upsell'        => (float)($dims['upsell'] ?? 0),
                    'tone'          => (float)($dims['tone'] ?? 0),
                    'resolution'    => (float)($dims['resolution'] ?? 0),
                    'efficiency'    => (float)($dims['efficiency'] ?? 0),
                    'overall'       => (float)($dims['overall'] ?? 0),
                ],
                'total_scored' => (int)($dims['total_scored'] ?? 0),
                'distribution' => [
                    '0-20'   => (int)($dist['bucket_0_20'] ?? 0),
                    '21-40'  => (int)($dist['bucket_21_40'] ?? 0),
                    '41-60'  => (int)($dist['bucket_41_60'] ?? 0),
                    '61-80'  => (int)($dist['bucket_61_80'] ?? 0),
                    '81-100' => (int)($dist['bucket_81_100'] ?? 0),
                ],
                'flagged' => $flagged,
                'funnel' => [
                    'total'        => (int)$funnel['total'],
                    'orders'       => (int)$funnel['orders'],
                    'abandoned'    => (int)$funnel['abandoned'],
                    'transferred'  => (int)$funnel['transferred'],
                    'support_only' => (int)$funnel['support_only'],
                ],
                'daily_trend' => $trend,
            ]);
        }

        // ══════════ QUALITY DETAIL ══════════
        if ($action === 'quality_detail') {
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) {
                response(false, null, "Informe id do quality score", 400);
            }

            $stmt = $db->prepare("SELECT * FROM om_ai_quality_scores WHERE id = ?");
            $stmt->execute([$id]);
            $score = $stmt->fetch();

            if (!$score) {
                response(false, null, "Quality score nao encontrado", 404);
            }

            $score['id'] = (int)$score['id'];
            $score['conversation_id'] = (int)$score['conversation_id'];
            $score['overall_score'] = (int)$score['overall_score'];
            $score['greeting_score'] = (int)$score['greeting_score'];
            $score['understanding_score'] = (int)$score['understanding_score'];
            $score['accuracy_score'] = (int)$score['accuracy_score'];
            $score['upsell_score'] = (int)$score['upsell_score'];
            $score['tone_score'] = (int)$score['tone_score'];
            $score['resolution_score'] = (int)$score['resolution_score'];
            $score['efficiency_score'] = (int)$score['efficiency_score'];
            $score['missed_opportunities'] = json_decode($score['missed_opportunities'], true) ?: [];
            $score['issues_detected'] = json_decode($score['issues_detected'], true) ?: [];
            $score['sentiment_flow'] = json_decode($score['sentiment_flow'], true) ?: [];
            $score['order_value'] = round((float)$score['order_value'], 2);
            $score['turns_count'] = (int)$score['turns_count'];
            $score['duration_seconds'] = (int)$score['duration_seconds'];
            $score['flagged_for_review'] = (bool)$score['flagged_for_review'];

            response(true, ['quality_score' => $score]);
        }

        // ══════════ CLV DASHBOARD ══════════
        if ($action === 'clv_dashboard') {

            // Tier distribution
            $tierStmt = $db->query("
                SELECT tier, COUNT(*) AS count,
                       COALESCE(AVG(predicted_yearly_value), 0) AS avg_annual_value,
                       COALESCE(SUM(total_spent), 0) AS total_revenue
                FROM om_customer_clv
                GROUP BY tier
                ORDER BY CASE tier
                    WHEN 'vip' THEN 1
                    WHEN 'stable' THEN 2
                    WHEN 'growing' THEN 3
                    WHEN 'new' THEN 4
                    WHEN 'at_risk' THEN 5
                    WHEN 'churned' THEN 6
                    ELSE 7
                END
            ");
            $tiers = $tierStmt->fetchAll();

            foreach ($tiers as &$t) {
                $t['count'] = (int)$t['count'];
                $t['avg_annual_value'] = round((float)$t['avg_annual_value'], 2);
                $t['total_revenue'] = round((float)$t['total_revenue'], 2);
            }
            unset($t);

            // Top VIPs
            $vipStmt = $db->query("
                SELECT c.customer_id, c.phone, c.total_orders, c.total_spent,
                       c.avg_order_value, c.clv_score, c.tier,
                       c.predicted_yearly_value, c.days_since_last_order,
                       cu.name AS customer_name
                FROM om_customer_clv c
                LEFT JOIN om_customers cu ON cu.customer_id = c.customer_id
                WHERE c.tier = 'vip'
                ORDER BY c.clv_score DESC
                LIMIT 20
            ");
            $vips = $vipStmt->fetchAll();

            foreach ($vips as &$v) {
                $v['customer_id'] = (int)$v['customer_id'];
                $v['total_orders'] = (int)$v['total_orders'];
                $v['total_spent'] = round((float)$v['total_spent'], 2);
                $v['avg_order_value'] = round((float)$v['avg_order_value'], 2);
                $v['clv_score'] = (int)$v['clv_score'];
                $v['predicted_yearly_value'] = round((float)$v['predicted_yearly_value'], 2);
                $v['days_since_last_order'] = (int)$v['days_since_last_order'];
                $v['name'] = $v['customer_name'] ?? '';
                unset($v['customer_name']);
            }
            unset($v);

            // At-risk customers
            $atRiskStmt = $db->query("
                SELECT c.customer_id, c.phone, c.total_orders, c.total_spent,
                       c.churn_risk, c.days_since_last_order, c.clv_score,
                       c.predicted_yearly_value,
                       cu.name AS customer_name
                FROM om_customer_clv c
                LEFT JOIN om_customers cu ON cu.customer_id = c.customer_id
                WHERE c.tier = 'at_risk'
                ORDER BY c.churn_risk DESC
                LIMIT 20
            ");
            $atRisk = $atRiskStmt->fetchAll();

            foreach ($atRisk as &$r) {
                $r['customer_id'] = (int)$r['customer_id'];
                $r['total_orders'] = (int)$r['total_orders'];
                $r['total_spent'] = round((float)$r['total_spent'], 2);
                $r['churn_risk'] = round((float)$r['churn_risk'], 1);
                $r['days_since_last_order'] = (int)$r['days_since_last_order'];
                $r['clv_score'] = (int)$r['clv_score'];
                $r['predicted_yearly_value'] = round((float)$r['predicted_yearly_value'], 2);
                $r['name'] = $r['customer_name'] ?? '';
                unset($r['customer_name']);
            }
            unset($r);

            // Revenue projections: total predicted annual value by tier
            $projStmt = $db->query("
                SELECT
                    COALESCE(SUM(predicted_yearly_value), 0) AS projected_annual,
                    COALESCE(SUM(predicted_monthly_value), 0) AS projected_monthly,
                    COUNT(*) AS total_customers
                FROM om_customer_clv
            ");
            $proj = $projStmt->fetch();

            response(true, [
                'tiers'       => $tiers,
                'top_vips'    => $vips,
                'at_risk'     => $atRisk,
                'projections' => [
                    'annual_revenue'   => round((float)$proj['projected_annual'], 2),
                    'monthly_revenue'  => round((float)$proj['projected_monthly'], 2),
                    'total_customers'  => (int)$proj['total_customers'],
                ],
            ]);
        }

        // ══════════ CLV CUSTOMER DETAIL ══════════
        if ($action === 'clv_customer') {
            $customerId = (int)($_GET['customer_id'] ?? 0);
            if ($customerId <= 0) {
                response(false, null, "Informe customer_id", 400);
            }

            $stmt = $db->prepare("
                SELECT c.*, cu.name AS customer_name, cu.email
                FROM om_customer_clv c
                LEFT JOIN om_customers cu ON cu.customer_id = c.customer_id
                WHERE c.customer_id = ?
            ");
            $stmt->execute([$customerId]);
            $clv = $stmt->fetch();

            if (!$clv) {
                response(false, null, "CLV nao encontrado para este cliente", 404);
            }

            $clv['customer_id'] = (int)$clv['customer_id'];
            $clv['total_orders'] = (int)$clv['total_orders'];
            $clv['total_spent'] = round((float)$clv['total_spent'], 2);
            $clv['avg_order_value'] = round((float)$clv['avg_order_value'], 2);
            $clv['predicted_monthly_value'] = round((float)$clv['predicted_monthly_value'], 2);
            $clv['predicted_yearly_value'] = round((float)$clv['predicted_yearly_value'], 2);
            $clv['clv_score'] = (int)$clv['clv_score'];
            $clv['churn_risk'] = round((float)$clv['churn_risk'], 1);
            $clv['days_since_last_order'] = (int)$clv['days_since_last_order'];
            $clv['days_as_customer'] = (int)$clv['days_as_customer'];
            $clv['order_frequency_days'] = round((float)$clv['order_frequency_days'], 1);
            $clv['name'] = $clv['customer_name'] ?? '';

            // Recent orders
            $ordersStmt = $db->prepare("
                SELECT order_id, total, status, source, date_added
                FROM om_market_orders
                WHERE customer_id = ?
                ORDER BY date_added DESC
                LIMIT 10
            ");
            $ordersStmt->execute([$customerId]);
            $orders = $ordersStmt->fetchAll();

            foreach ($orders as &$o) {
                $o['id'] = (int)$o['order_id'];
                $o['total'] = round((float)$o['total'], 2);
            }
            unset($o);

            // Callcenter interactions
            $callsStmt = $db->prepare("
                SELECT id, status, ai_sentiment, duration_seconds, order_id, created_at
                FROM om_callcenter_calls
                WHERE customer_id = ?
                ORDER BY created_at DESC
                LIMIT 10
            ");
            $callsStmt->execute([$customerId]);
            $calls = $callsStmt->fetchAll();

            response(true, [
                'clv'           => $clv,
                'recent_orders' => $orders,
                'recent_calls'  => $calls,
            ]);
        }

        // ══════════ PROACTIVE DASHBOARD ══════════
        if ($action === 'proactive_dashboard') {
            $period = trim($_GET['period'] ?? '7d');
            $interval = parsePeriodToInterval($period);

            // Alerts by type
            $byTypeStmt = $db->prepare("
                SELECT
                    alert_type,
                    severity,
                    COUNT(*) AS count,
                    COUNT(*) FILTER (WHERE resolved = TRUE) AS resolved_count,
                    COALESCE(AVG(actual_delay_minutes), 0)::int AS avg_delay_minutes
                FROM om_proactive_alerts
                WHERE created_at >= NOW() - INTERVAL '{$interval}'
                GROUP BY alert_type, severity
                ORDER BY count DESC
            ");
            $byTypeStmt->execute();
            $byType = $byTypeStmt->fetchAll();

            foreach ($byType as &$t) {
                $t['count'] = (int)$t['count'];
                $t['resolved_count'] = (int)$t['resolved_count'];
                $t['avg_delay_minutes'] = (int)$t['avg_delay_minutes'];
            }
            unset($t);

            // Resolution stats
            $resolutionStmt = $db->prepare("
                SELECT
                    COUNT(*) AS total_alerts,
                    COUNT(*) FILTER (WHERE resolved = TRUE) AS total_resolved,
                    COUNT(*) FILTER (WHERE auto_action_taken IS NOT NULL) AS auto_actions,
                    COUNT(*) FILTER (WHERE customer_notified = TRUE) AS customers_notified,
                    COUNT(*) FILTER (WHERE partner_notified = TRUE) AS partners_notified,
                    COALESCE(AVG(EXTRACT(EPOCH FROM (resolved_at - detected_at)) / 60) FILTER (WHERE resolved = TRUE), 0)::int AS avg_resolution_minutes
                FROM om_proactive_alerts
                WHERE created_at >= NOW() - INTERVAL '{$interval}'
            ");
            $resolutionStmt->execute();
            $resolution = $resolutionStmt->fetch();

            // Compensations given
            $compStmt = $db->prepare("
                SELECT
                    compensation_type,
                    COUNT(*) AS count,
                    COALESCE(SUM(compensation_value), 0) AS total_value
                FROM om_proactive_alerts
                WHERE created_at >= NOW() - INTERVAL '{$interval}'
                  AND compensation_type IS NOT NULL AND compensation_type != 'none'
                GROUP BY compensation_type
                ORDER BY total_value DESC
            ");
            $compStmt->execute();
            $compensations = $compStmt->fetchAll();

            foreach ($compensations as &$c) {
                $c['count'] = (int)$c['count'];
                $c['total_value'] = round((float)$c['total_value'], 2);
            }
            unset($c);

            // Worst partners (most alerts)
            $worstStmt = $db->prepare("
                SELECT
                    o.partner_id,
                    p.company AS partner_name,
                    COUNT(pa.id) AS alert_count,
                    COALESCE(AVG(pa.actual_delay_minutes), 0)::int AS avg_delay
                FROM om_proactive_alerts pa
                JOIN om_market_orders o ON o.order_id = pa.order_id
                LEFT JOIN om_market_partners p ON p.partner_id = o.partner_id
                WHERE pa.created_at >= NOW() - INTERVAL '{$interval}'
                GROUP BY o.partner_id, p.company
                ORDER BY alert_count DESC
                LIMIT 10
            ");
            $worstStmt->execute();
            $worstPartners = $worstStmt->fetchAll();

            foreach ($worstPartners as &$wp) {
                $wp['partner_id'] = (int)$wp['partner_id'];
                $wp['alert_count'] = (int)$wp['alert_count'];
                $wp['avg_delay'] = (int)$wp['avg_delay'];
            }
            unset($wp);

            // Unresolved alerts
            $unresolvedStmt = $db->query("
                SELECT pa.id, pa.order_id, pa.alert_type, pa.severity,
                       pa.actual_delay_minutes, pa.detected_at,
                       o.customer_id
                FROM om_proactive_alerts pa
                LEFT JOIN om_market_orders o ON o.order_id = pa.order_id
                WHERE pa.resolved = FALSE
                ORDER BY CASE pa.severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END, pa.detected_at ASC
                LIMIT 20
            ");
            $unresolved = $unresolvedStmt->fetchAll();

            foreach ($unresolved as &$u) {
                $u['id'] = (int)$u['id'];
                $u['order_id'] = (int)$u['order_id'];
                $u['actual_delay_minutes'] = (int)$u['actual_delay_minutes'];
            }
            unset($u);

            response(true, [
                'period'           => $period,
                'alerts_by_type'   => $byType,
                'resolution' => [
                    'total'                  => (int)$resolution['total_alerts'],
                    'resolved'               => (int)$resolution['total_resolved'],
                    'resolution_rate'        => (int)$resolution['total_alerts'] > 0
                        ? round(((int)$resolution['total_resolved'] / (int)$resolution['total_alerts']) * 100, 1)
                        : 0,
                    'auto_actions'           => (int)$resolution['auto_actions'],
                    'customers_notified'     => (int)$resolution['customers_notified'],
                    'partners_notified'      => (int)$resolution['partners_notified'],
                    'avg_resolution_minutes' => (int)$resolution['avg_resolution_minutes'],
                ],
                'compensations'    => $compensations,
                'worst_partners'   => $worstPartners,
                'unresolved'       => $unresolved,
            ]);
        }

        // ══════════ LGPD DASHBOARD ══════════
        if ($action === 'lgpd_dashboard') {
            $period = trim($_GET['period'] ?? '30d');
            $interval = parsePeriodToInterval($period);

            // Audit events by type
            $eventStmt = $db->prepare("
                SELECT event_type, action, COUNT(*) AS count
                FROM om_audit_log
                WHERE created_at >= NOW() - INTERVAL '{$interval}'
                GROUP BY event_type, action
                ORDER BY count DESC
            ");
            $eventStmt->execute();
            $events = $eventStmt->fetchAll();

            foreach ($events as &$e) {
                $e['count'] = (int)$e['count'];
            }
            unset($e);

            // PII access stats
            $piiStmt = $db->prepare("
                SELECT
                    COUNT(*) AS total_pii_accesses,
                    COUNT(DISTINCT customer_id) AS unique_customers,
                    COUNT(DISTINCT actor_id) AS unique_actors
                FROM om_audit_log
                WHERE created_at >= NOW() - INTERVAL '{$interval}'
                  AND event_type = 'pii_access'
            ");
            $piiStmt->execute();
            $pii = $piiStmt->fetch();

            // Deletion requests
            $delStmt = $db->prepare("
                SELECT
                    status,
                    COUNT(*) AS count
                FROM om_data_deletion_requests
                WHERE created_at >= NOW() - INTERVAL '{$interval}'
                GROUP BY status
            ");
            $delStmt->execute();
            $deletions = $delStmt->fetchAll();

            $deletionMap = [];
            foreach ($deletions as $d) {
                $deletionMap[$d['status']] = (int)$d['count'];
            }

            // Consent stats
            $consentStmt = $db->query("
                SELECT
                    consent_type,
                    COUNT(*) FILTER (WHERE granted = TRUE) AS granted_count,
                    COUNT(*) FILTER (WHERE granted = FALSE OR revoked_at IS NOT NULL) AS revoked_count,
                    COUNT(*) AS total
                FROM om_customer_consent
                GROUP BY consent_type
                ORDER BY consent_type
            ");
            $consents = $consentStmt->fetchAll();

            foreach ($consents as &$c) {
                $c['granted_count'] = (int)$c['granted_count'];
                $c['revoked_count'] = (int)$c['revoked_count'];
                $c['total'] = (int)$c['total'];
            }
            unset($c);

            // Recent audit trail
            $recentStmt = $db->prepare("
                SELECT id, event_type, actor_type, actor_id, customer_phone,
                       entity_type, action, pii_fields_accessed, created_at
                FROM om_audit_log
                WHERE created_at >= NOW() - INTERVAL '{$interval}'
                ORDER BY created_at DESC
                LIMIT 50
            ");
            $recentStmt->execute();
            $recentAudit = $recentStmt->fetchAll();

            foreach ($recentAudit as &$a) {
                $a['id'] = (int)$a['id'];
                if (is_string($a['pii_fields_accessed'])) {
                    $a['pii_fields_accessed'] = str_replace(['{', '}'], '', $a['pii_fields_accessed']);
                    $a['pii_fields_accessed'] = $a['pii_fields_accessed'] ? explode(',', $a['pii_fields_accessed']) : [];
                }
            }
            unset($a);

            response(true, [
                'period'       => $period,
                'audit_events' => $events,
                'pii_access' => [
                    'total_accesses'   => (int)$pii['total_pii_accesses'],
                    'unique_customers' => (int)$pii['unique_customers'],
                    'unique_actors'    => (int)$pii['unique_actors'],
                ],
                'deletion_requests' => [
                    'pending'    => (int)($deletionMap['pending'] ?? 0),
                    'processing' => (int)($deletionMap['processing'] ?? 0),
                    'completed'  => (int)($deletionMap['completed'] ?? 0),
                    'failed'     => (int)($deletionMap['failed'] ?? 0),
                ],
                'consent_stats'  => $consents,
                'recent_audit'   => $recentAudit,
            ]);
        }

        // ══════════ LGPD SEARCH ══════════
        if ($action === 'lgpd_search') {
            $phone = trim($_GET['phone'] ?? '');
            if ($phone === '') {
                response(false, null, "Informe phone para busca LGPD", 400);
            }

            // Find customer
            $custStmt = $db->prepare("
                SELECT customer_id AS id, name, email, phone, created_at
                FROM om_customers
                WHERE phone = ? OR phone LIKE ?
                LIMIT 5
            ");
            $custStmt->execute([$phone, '%' . $phone]);
            $customers = $custStmt->fetchAll();

            // Find callcenter data
            $callsStmt = $db->prepare("
                SELECT COUNT(*) AS calls FROM om_callcenter_calls WHERE customer_phone = ?
            ");
            $callsStmt->execute([$phone]);
            $callCount = (int)$callsStmt->fetchColumn();

            $waStmt = $db->prepare("
                SELECT COUNT(*) AS convos FROM om_callcenter_whatsapp WHERE phone = ?
            ");
            $waStmt->execute([$phone]);
            $waCount = (int)$waStmt->fetchColumn();

            // Consent records
            $consentStmt = $db->prepare("
                SELECT consent_type, granted, granted_at, revoked_at, source
                FROM om_customer_consent
                WHERE customer_phone = ?
            ");
            $consentStmt->execute([$phone]);
            $consents = $consentStmt->fetchAll();

            // Audit trail
            $auditStmt = $db->prepare("
                SELECT event_type, action, entity_type, pii_fields_accessed, created_at
                FROM om_audit_log
                WHERE customer_phone = ?
                ORDER BY created_at DESC
                LIMIT 20
            ");
            $auditStmt->execute([$phone]);
            $audit = $auditStmt->fetchAll();

            // Deletion requests
            $delReqStmt = $db->prepare("
                SELECT id, status, source, tables_affected, completed_at, created_at
                FROM om_data_deletion_requests
                WHERE customer_phone = ?
                ORDER BY created_at DESC
            ");
            $delReqStmt->execute([$phone]);
            $delRequests = $delReqStmt->fetchAll();

            // Log the LGPD search as PII access
            $db->prepare("
                INSERT INTO om_audit_log (event_type, actor_type, actor_id, customer_phone, action, details, pii_fields_accessed, ip_address)
                VALUES ('pii_access', 'admin', ?, ?, 'read', ?, '{phone,name,email}', ?)
            ")->execute([
                (string)$adminId,
                $phone,
                json_encode(['action' => 'lgpd_search']),
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);

            response(true, [
                'phone'              => $phone,
                'customers'          => $customers,
                'data_summary' => [
                    'callcenter_calls'         => $callCount,
                    'whatsapp_conversations'   => $waCount,
                ],
                'consents'           => $consents,
                'audit_trail'        => $audit,
                'deletion_requests'  => $delRequests,
            ]);
        }

        // ══════════ RETRY DASHBOARD ══════════
        if ($action === 'retry_dashboard') {
            $period = trim($_GET['period'] ?? '24h');
            $interval = parsePeriodToInterval($period);

            // Error type breakdown
            $errorStmt = $db->prepare("
                SELECT
                    error_type,
                    COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE success = TRUE) AS recovered,
                    COUNT(*) FILTER (WHERE success = FALSE) AS failed,
                    COALESCE(AVG(response_time_ms), 0)::int AS avg_response_ms,
                    COALESCE(AVG(attempt_number), 0) AS avg_attempts
                FROM om_ai_retry_log
                WHERE created_at >= NOW() - INTERVAL '{$interval}'
                GROUP BY error_type
                ORDER BY total DESC
            ");
            $errorStmt->execute();
            $errors = $errorStmt->fetchAll();

            foreach ($errors as &$e) {
                $e['total'] = (int)$e['total'];
                $e['recovered'] = (int)$e['recovered'];
                $e['failed'] = (int)$e['failed'];
                $e['avg_response_ms'] = (int)$e['avg_response_ms'];
                $e['avg_attempts'] = round((float)$e['avg_attempts'], 1);
                $e['recovery_rate'] = $e['total'] > 0
                    ? round(($e['recovered'] / $e['total']) * 100, 1)
                    : 0;
            }
            unset($e);

            // Fallback strategy usage
            $fallbackStmt = $db->prepare("
                SELECT
                    fallback_used,
                    COUNT(*) AS count,
                    COUNT(*) FILTER (WHERE success = TRUE) AS success_count
                FROM om_ai_retry_log
                WHERE created_at >= NOW() - INTERVAL '{$interval}'
                  AND fallback_used IS NOT NULL
                GROUP BY fallback_used
                ORDER BY count DESC
            ");
            $fallbackStmt->execute();
            $fallbacks = $fallbackStmt->fetchAll();

            foreach ($fallbacks as &$f) {
                $f['count'] = (int)$f['count'];
                $f['success_count'] = (int)$f['success_count'];
                $f['success_rate'] = $f['count'] > 0
                    ? round(($f['success_count'] / $f['count']) * 100, 1)
                    : 0;
            }
            unset($f);

            // Totals
            $totalStmt = $db->prepare("
                SELECT
                    COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE success = TRUE) AS recovered,
                    COUNT(*) FILTER (WHERE success = FALSE) AS failed,
                    COALESCE(AVG(response_time_ms), 0)::int AS avg_response_ms,
                    COALESCE(MAX(attempt_number), 0) AS max_attempts
                FROM om_ai_retry_log
                WHERE created_at >= NOW() - INTERVAL '{$interval}'
            ");
            $totalStmt->execute();
            $totals = $totalStmt->fetch();

            // Hourly trend
            $hourlyStmt = $db->prepare("
                SELECT
                    date_trunc('hour', created_at) AS hour,
                    COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE success = FALSE) AS failures
                FROM om_ai_retry_log
                WHERE created_at >= NOW() - INTERVAL '{$interval}'
                GROUP BY date_trunc('hour', created_at)
                ORDER BY hour
            ");
            $hourlyStmt->execute();
            $hourly = $hourlyStmt->fetchAll();

            foreach ($hourly as &$h) {
                $h['total'] = (int)$h['total'];
                $h['failures'] = (int)$h['failures'];
            }
            unset($h);

            response(true, [
                'period'    => $period,
                'totals' => [
                    'total_events'    => (int)$totals['total'],
                    'recovered'       => (int)$totals['recovered'],
                    'failed'          => (int)$totals['failed'],
                    'recovery_rate'   => (int)$totals['total'] > 0
                        ? round(((int)$totals['recovered'] / (int)$totals['total']) * 100, 1)
                        : 0,
                    'avg_response_ms' => (int)$totals['avg_response_ms'],
                    'max_attempts'    => (int)$totals['max_attempts'],
                ],
                'by_error_type'    => $errors,
                'fallback_usage'   => $fallbacks,
                'hourly_trend'     => $hourly,
            ]);
        }

        response(false, null, "Action GET invalida. Valores: quality_dashboard, quality_detail, clv_dashboard, clv_customer, proactive_dashboard, lgpd_dashboard, lgpd_search, retry_dashboard", 400);
    }

    // ════════════════════════════════════════════════════════════════════
    // POST actions
    // ════════════════════════════════════════════════════════════════════
    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $action = $input['action'] ?? ($_POST['action'] ?? '');

        if (!$action) {
            response(false, null, "Informe action: quality_review, clv_recalculate, lgpd_delete, lgpd_export", 400);
        }

        // ── Quality review ──
        if ($action === 'quality_review') {
            $scoreId = (int)($input['score_id'] ?? 0);
            $reviewNotes = trim($input['review_notes'] ?? '');
            $adjustScore = isset($input['adjust_score']) ? (int)$input['adjust_score'] : null;

            if ($scoreId <= 0) {
                response(false, null, "score_id obrigatorio", 400);
            }
            if ($reviewNotes === '') {
                response(false, null, "review_notes obrigatorio", 400);
            }

            // Verify score exists
            $stmt = $db->prepare("SELECT id, overall_score FROM om_ai_quality_scores WHERE id = ?");
            $stmt->execute([$scoreId]);
            $score = $stmt->fetch();

            if (!$score) {
                response(false, null, "Quality score nao encontrado", 404);
            }

            $updates = [
                'reviewed_by = ?',
                'review_notes = ?',
                'flagged_for_review = FALSE',
            ];
            $params = [$adminId, $reviewNotes];

            if ($adjustScore !== null && $adjustScore >= 0 && $adjustScore <= 100) {
                $updates[] = 'overall_score = ?';
                $params[] = $adjustScore;
            }

            $params[] = $scoreId;

            $stmt = $db->prepare("
                UPDATE om_ai_quality_scores
                SET " . implode(', ', $updates) . "
                WHERE id = ?
            ");
            $stmt->execute($params);

            response(true, ['message' => 'Review registrado com sucesso']);
        }

        // ── CLV recalculate ──
        if ($action === 'clv_recalculate') {

            // Recalculate CLV for all customers with orders
            $db->beginTransaction();

            try {
                // Update existing CLV records from order data
                $db->exec("
                    INSERT INTO om_customer_clv (customer_id, phone, total_orders, total_spent, avg_order_value,
                        first_order_at, last_order_at, days_as_customer, days_since_last_order, last_calculated_at)
                    SELECT
                        o.customer_id,
                        c.phone,
                        COUNT(o.order_id),
                        COALESCE(SUM(o.total), 0),
                        COALESCE(AVG(o.total), 0),
                        MIN(o.date_added),
                        MAX(o.date_added),
                        GREATEST(EXTRACT(DAY FROM (NOW() - MIN(o.date_added)))::int, 0),
                        GREATEST(EXTRACT(DAY FROM (NOW() - MAX(o.date_added)))::int, 0),
                        NOW()
                    FROM om_market_orders o
                    JOIN om_customers c ON c.customer_id = o.customer_id
                    WHERE o.customer_id IS NOT NULL AND o.status NOT IN ('cancelado', 'recusado')
                    GROUP BY o.customer_id, c.phone
                    ON CONFLICT (customer_id) DO UPDATE SET
                        total_orders = EXCLUDED.total_orders,
                        total_spent = EXCLUDED.total_spent,
                        avg_order_value = EXCLUDED.avg_order_value,
                        first_order_at = EXCLUDED.first_order_at,
                        last_order_at = EXCLUDED.last_order_at,
                        days_as_customer = EXCLUDED.days_as_customer,
                        days_since_last_order = EXCLUDED.days_since_last_order,
                        last_calculated_at = NOW()
                ");

                // Calculate order frequency
                $db->exec("
                    UPDATE om_customer_clv
                    SET order_frequency_days = CASE
                        WHEN total_orders > 1 AND days_as_customer > 0
                        THEN ROUND(days_as_customer::numeric / (total_orders - 1), 2)
                        ELSE 0
                    END
                ");

                // Calculate predicted values
                $db->exec("
                    UPDATE om_customer_clv
                    SET predicted_monthly_value = CASE
                        WHEN order_frequency_days > 0
                        THEN ROUND((30.0 / order_frequency_days) * avg_order_value, 2)
                        WHEN days_as_customer > 0
                        THEN ROUND((total_spent / GREATEST(days_as_customer, 1)) * 30, 2)
                        ELSE avg_order_value
                    END,
                    predicted_yearly_value = CASE
                        WHEN order_frequency_days > 0
                        THEN ROUND((365.0 / order_frequency_days) * avg_order_value, 2)
                        WHEN days_as_customer > 0
                        THEN ROUND((total_spent / GREATEST(days_as_customer, 1)) * 365, 2)
                        ELSE avg_order_value * 12
                    END
                ");

                // Calculate CLV score (0-100) based on total_spent + frequency + recency
                $db->exec("
                    UPDATE om_customer_clv
                    SET clv_score = LEAST(100, GREATEST(0,
                        (LEAST(total_spent / 50.0, 40))::int +
                        (CASE WHEN order_frequency_days > 0 AND order_frequency_days < 60 THEN 30
                              WHEN order_frequency_days >= 60 AND order_frequency_days < 120 THEN 15
                              ELSE 5 END) +
                        (CASE WHEN days_since_last_order < 14 THEN 30
                              WHEN days_since_last_order < 30 THEN 20
                              WHEN days_since_last_order < 60 THEN 10
                              ELSE 0 END)
                    ))
                ");

                // Calculate churn risk
                $db->exec("
                    UPDATE om_customer_clv
                    SET churn_risk = LEAST(100, GREATEST(0,
                        CASE
                            WHEN days_since_last_order > 90 THEN 90
                            WHEN days_since_last_order > 60 THEN 70
                            WHEN days_since_last_order > 30 THEN 40
                            WHEN days_since_last_order > 14 THEN 20
                            ELSE 5
                        END +
                        CASE WHEN total_orders = 1 THEN 10 ELSE 0 END
                    ))
                ");

                // Set tiers
                $db->exec("
                    UPDATE om_customer_clv
                    SET tier = CASE
                        WHEN days_since_last_order > 90 THEN 'churned'
                        WHEN churn_risk >= 60 THEN 'at_risk'
                        WHEN clv_score >= 80 THEN 'vip'
                        WHEN clv_score >= 50 THEN 'stable'
                        WHEN total_orders >= 3 THEN 'growing'
                        ELSE 'new'
                    END
                ");

                $db->commit();

                // Count updated
                $count = (int)$db->query("SELECT COUNT(*) FROM om_customer_clv")->fetchColumn();

                response(true, [
                    'message'            => 'CLV recalculado com sucesso',
                    'customers_updated'  => $count,
                ]);

            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
        }

        // ── LGPD delete ──
        if ($action === 'lgpd_delete') {
            $phone = trim($input['customer_phone'] ?? $input['phone'] ?? '');
            $customerId = isset($input['customer_id']) ? (int)$input['customer_id'] : null;
            $reason = trim($input['reason'] ?? 'LGPD deletion request');

            if ($phone === '' && !$customerId) {
                response(false, null, "customer_phone ou customer_id obrigatorio", 400);
            }

            $db->beginTransaction();

            try {
                $tablesAffected = [];

                // 1. Anonymize callcenter calls
                $stmt = $db->prepare("
                    UPDATE om_callcenter_calls
                    SET customer_name = 'REMOVIDO', customer_phone = 'REMOVIDO',
                        transcription = NULL, ai_summary = NULL, recording_url = NULL, notes = NULL
                    WHERE customer_phone = ?
                ");
                $stmt->execute([$phone]);
                $tablesAffected[] = ['table' => 'om_callcenter_calls', 'rows_affected' => $stmt->rowCount()];

                // 2. Delete WhatsApp messages
                $waIds = $db->prepare("SELECT id FROM om_callcenter_whatsapp WHERE phone = ?");
                $waIds->execute([$phone]);
                $waIdList = $waIds->fetchAll(PDO::FETCH_COLUMN);

                if (!empty($waIdList)) {
                    $placeholders = implode(',', array_fill(0, count($waIdList), '?'));
                    $delMsg = $db->prepare("DELETE FROM om_callcenter_wa_messages WHERE conversation_id IN ({$placeholders})");
                    $delMsg->execute($waIdList);
                    $tablesAffected[] = ['table' => 'om_callcenter_wa_messages', 'rows_affected' => $delMsg->rowCount()];

                    $delWa = $db->prepare("DELETE FROM om_callcenter_whatsapp WHERE phone = ?");
                    $delWa->execute([$phone]);
                    $tablesAffected[] = ['table' => 'om_callcenter_whatsapp', 'rows_affected' => $delWa->rowCount()];
                }

                // 3. Delete AI call memory
                $stmt = $db->prepare("DELETE FROM om_ai_call_memory WHERE customer_phone = ?");
                $stmt->execute([$phone]);
                $tablesAffected[] = ['table' => 'om_ai_call_memory', 'rows_affected' => $stmt->rowCount()];

                // 4. Delete AB test assignments
                $stmt = $db->prepare("DELETE FROM om_ab_assignments WHERE customer_phone = ?");
                $stmt->execute([$phone]);
                $tablesAffected[] = ['table' => 'om_ab_assignments', 'rows_affected' => $stmt->rowCount()];

                // 5. Remove CLV record
                if ($customerId) {
                    $stmt = $db->prepare("DELETE FROM om_customer_clv WHERE customer_id = ?");
                    $stmt->execute([$customerId]);
                    $tablesAffected[] = ['table' => 'om_customer_clv', 'rows_affected' => $stmt->rowCount()];
                }

                // 6. Delete consent records
                $stmt = $db->prepare("DELETE FROM om_customer_consent WHERE customer_phone = ?");
                $stmt->execute([$phone]);
                $tablesAffected[] = ['table' => 'om_customer_consent', 'rows_affected' => $stmt->rowCount()];

                // Create deletion request record
                $stmt = $db->prepare("
                    INSERT INTO om_data_deletion_requests
                        (customer_id, customer_phone, source, status, tables_affected, completed_at, requested_by)
                    VALUES (?, ?, 'admin', 'completed', ?, NOW(), ?)
                    RETURNING id
                ");
                $stmt->execute([$customerId, $phone, json_encode($tablesAffected), (string)$adminId]);
                $requestId = (int)$stmt->fetchColumn();

                // Audit log
                $db->prepare("
                    INSERT INTO om_audit_log (event_type, actor_type, actor_id, customer_phone, customer_id, action, details, ip_address)
                    VALUES ('data_delete', 'admin', ?, ?, ?, 'delete', ?, ?)
                ")->execute([
                    (string)$adminId,
                    $phone,
                    $customerId,
                    json_encode(['reason' => $reason, 'tables_affected' => $tablesAffected]),
                    $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                ]);

                $db->commit();

                response(true, [
                    'request_id'      => $requestId,
                    'tables_affected' => $tablesAffected,
                    'message'         => 'Dados deletados com sucesso (LGPD)',
                ]);

            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
        }

        // ── LGPD export (data portability) ──
        if ($action === 'lgpd_export') {
            $phone = trim($input['customer_phone'] ?? $input['phone'] ?? '');
            $customerId = isset($input['customer_id']) ? (int)$input['customer_id'] : null;

            if ($phone === '' && !$customerId) {
                response(false, null, "customer_phone ou customer_id obrigatorio", 400);
            }

            $exportData = [
                'export_date' => date('c'),
                'requested_by' => 'admin:' . $adminId,
            ];

            // Customer profile
            if ($customerId) {
                $stmt = $db->prepare("
                    SELECT customer_id AS id, name, email, phone, created_at
                    FROM om_customers WHERE customer_id = ?
                ");
                $stmt->execute([$customerId]);
                $exportData['customer_profile'] = $stmt->fetch() ?: null;
            }

            // Orders
            $orderStmt = $db->prepare("
                SELECT order_id AS id, total, status, source, date_added
                FROM om_market_orders
                WHERE customer_id = ? OR customer_id IN (
                    SELECT customer_id FROM om_customers WHERE phone = ?
                )
                ORDER BY date_added DESC
            ");
            $orderStmt->execute([$customerId ?? 0, $phone]);
            $exportData['orders'] = $orderStmt->fetchAll();

            // Call history (anonymized recording URLs)
            $callStmt = $db->prepare("
                SELECT id, direction, status, duration_seconds, ai_summary, ai_sentiment,
                       store_identified, created_at
                FROM om_callcenter_calls
                WHERE customer_phone = ?
                ORDER BY created_at DESC
            ");
            $callStmt->execute([$phone]);
            $exportData['call_history'] = $callStmt->fetchAll();

            // WhatsApp conversations (messages only)
            $waStmt = $db->prepare("
                SELECT w.id, w.status, w.created_at,
                       (SELECT json_agg(json_build_object(
                           'direction', m.direction,
                           'sender_type', m.sender_type,
                           'message', m.message,
                           'created_at', m.created_at
                       ) ORDER BY m.created_at)
                       FROM om_callcenter_wa_messages m WHERE m.conversation_id = w.id) AS messages
                FROM om_callcenter_whatsapp w
                WHERE w.phone = ?
                ORDER BY w.created_at DESC
            ");
            $waStmt->execute([$phone]);
            $waData = $waStmt->fetchAll();
            foreach ($waData as &$w) {
                $w['messages'] = $w['messages'] ? json_decode($w['messages'], true) : [];
            }
            unset($w);
            $exportData['whatsapp_conversations'] = $waData;

            // Consent records
            $consentStmt = $db->prepare("
                SELECT consent_type, granted, granted_at, revoked_at, source
                FROM om_customer_consent WHERE customer_phone = ?
            ");
            $consentStmt->execute([$phone]);
            $exportData['consents'] = $consentStmt->fetchAll();

            // CLV data
            if ($customerId) {
                $clvStmt = $db->prepare("SELECT * FROM om_customer_clv WHERE customer_id = ?");
                $clvStmt->execute([$customerId]);
                $exportData['clv'] = $clvStmt->fetch() ?: null;
            }

            // Audit log: record the export
            $db->prepare("
                INSERT INTO om_audit_log (event_type, actor_type, actor_id, customer_phone, customer_id, action, details, ip_address)
                VALUES ('data_access', 'admin', ?, ?, ?, 'export', '{\"action\":\"lgpd_export\"}', ?)
            ")->execute([
                (string)$adminId,
                $phone,
                $customerId,
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);

            response(true, [
                'export' => $exportData,
                'message' => 'Dados exportados com sucesso (portabilidade LGPD)',
            ]);
        }

        response(false, null, "Action POST invalida. Valores: quality_review, clv_recalculate, lgpd_delete, lgpd_export", 400);
    }

    // Method not allowed
    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[admin/callcenter/enterprise] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
