<?php
/**
 * Admin endpoint: AI Support analytics
 *
 * GET /api/mercado/admin/ai-analytics.php
 *
 * Returns:
 *   - actions count by type (today, 7d, 30d)
 *   - success vs failed
 *   - compensation total ($)
 *   - cancelations count
 *   - disputes count
 *   - escalation rate
 *   - estimated savings (vs human-only support)
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

$db = getDB();
OmAuth::getInstance()->setDb($db);
$payload = om_auth()->requireAdmin();
$adminId = $payload["uid"];

try {
    // Total actions by status, last 30 days
    $stmt = $db->query("
        SELECT
          action_name,
          COUNT(*) FILTER (WHERE success = TRUE) as success_count,
          COUNT(*) FILTER (WHERE success = FALSE) as failed_count,
          COUNT(*) as total_count
        FROM om_ai_action_log
        WHERE created_at > NOW() - INTERVAL '30 days'
        GROUP BY action_name
        ORDER BY total_count DESC
    ");
    $byAction = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Period totals
    $periods = [];
    foreach (['1 day' => 'today', '7 days' => 'week', '30 days' => 'month'] as $interval => $label) {
        $stmt = $db->prepare("
            SELECT
              COUNT(*) as total,
              COUNT(*) FILTER (WHERE success = TRUE) as success
            FROM om_ai_action_log
            WHERE created_at > NOW() - INTERVAL '{$interval}'
        ");
        $stmt->execute();
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        $periods[$label] = [
            'total' => (int)$r['total'],
            'success' => (int)$r['success'],
            'success_rate' => $r['total'] > 0 ? round(($r['success'] / $r['total']) * 100, 1) : 0,
        ];
    }

    // Compensation total ($) given out by AI
    $stmt = $db->query("
        SELECT
          COALESCE(SUM((result->>'amount')::numeric), 0) as total_amount,
          COUNT(*) as coupon_count
        FROM om_ai_action_log
        WHERE action_name IN ('issue_compensation', 'redeem_points')
          AND success = TRUE
          AND created_at > NOW() - INTERVAL '30 days'
    ");
    $comp = $stmt->fetch(PDO::FETCH_ASSOC);

    // Cancellations executed
    $stmt = $db->query("
        SELECT COUNT(*) as count
        FROM om_ai_action_log
        WHERE action_name = 'execute_cancel'
          AND success = TRUE
          AND created_at > NOW() - INTERVAL '30 days'
    ");
    $cancels = (int)$stmt->fetchColumn();

    // Disputes registered
    $stmt = $db->query("
        SELECT COUNT(*) as count
        FROM om_ai_action_log
        WHERE action_name = 'register_dispute'
          AND success = TRUE
          AND created_at > NOW() - INTERVAL '30 days'
    ");
    $disputes = (int)$stmt->fetchColumn();

    // Total tickets in last 30d (AI + bridged)
    $stmt = $db->query("
        SELECT COUNT(*) as count
        FROM om_ai_support_tickets
        WHERE created_at > NOW() - INTERVAL '30 days'
    ");
    $totalTickets = (int)$stmt->fetchColumn();

    // Escalated tickets
    $stmt = $db->query("
        SELECT COUNT(*) as count
        FROM om_ai_support_tickets
        WHERE status = 'escalated'
          AND created_at > NOW() - INTERVAL '30 days'
    ");
    $escalated = (int)$stmt->fetchColumn();

    $resolutionRate = $totalTickets > 0
        ? round((($totalTickets - $escalated) / $totalTickets) * 100, 1)
        : 0;

    // Estimated savings: each ticket the AI resolved saved ~5 min of human agent time
    // Average human agent cost in Brazil: R$ 25/hour = R$ 2.08/5min
    $resolvedByAI = $totalTickets - $escalated;
    $estimatedSavings = round($resolvedByAI * 2.08, 2);

    // Top denied reasons
    $stmt = $db->query("
        SELECT denied_reason, COUNT(*) as count
        FROM om_ai_action_log
        WHERE denied_reason IS NOT NULL
          AND created_at > NOW() - INTERVAL '30 days'
        GROUP BY denied_reason
        ORDER BY count DESC
        LIMIT 10
    ");
    $deniedReasons = $stmt->fetchAll(PDO::FETCH_ASSOC);

    response(true, [
        'periods' => $periods,
        'by_action' => $byAction,
        'compensation' => [
            'total_amount' => (float)$comp['total_amount'],
            'coupon_count' => (int)$comp['coupon_count'],
        ],
        'cancellations_executed' => $cancels,
        'disputes_registered' => $disputes,
        'tickets' => [
            'total' => $totalTickets,
            'escalated' => $escalated,
            'resolved_by_ai' => $resolvedByAI,
            'resolution_rate_pct' => $resolutionRate,
        ],
        'estimated_savings' => [
            'amount' => $estimatedSavings,
            'currency' => 'BRL',
            'method' => 'tickets_resolved_by_ai * R$2.08 (5min human cost)',
        ],
        'top_denied_reasons' => $deniedReasons,
    ], 'AI analytics');
} catch (\Throwable $e) {
    error_log('[admin/ai-analytics] error: ' . $e->getMessage());
    response(false, null, 'Erro ao carregar analytics', 500);
}
