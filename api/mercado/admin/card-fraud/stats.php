<?php
/**
 * GET /admin/card-fraud/stats.php
 * Stats for the card fraud admin dashboard.
 *
 * Response.data:
 *   total_alerts, blocked_today, challenged_today, reviewed_today,
 *   blocked_24h, blocked_7d, pending_review,
 *   fraud_rate (last 7d blocked/total tx), avg_risk_score,
 *   false_positive_rate (admin_decision=approve / admin_override total),
 *   top_rules: [{rule, cnt}], by_action: [{action, cnt}],
 *   score_distribution: [{band, cnt}]
 */
require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../helpers/card-fraud-detector.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    om_auth()->requireAdmin();

    // Self-heal in case migration hasn't run
    new CardFraudDetector($db);

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        response(false, null, 'Metodo nao permitido', 405);
    }

    $totalAlerts = (int)$db->query("SELECT COUNT(*) FROM om_card_fraud_alerts")->fetchColumn();

    $today = $db->query("
        SELECT
            COUNT(*) FILTER (WHERE action_taken = 'block')     AS blocked_today,
            COUNT(*) FILTER (WHERE action_taken = 'challenge') AS challenged_today,
            COUNT(*) FILTER (WHERE action_taken = 'review')    AS reviewed_today,
            COUNT(*) FILTER (WHERE action_taken = 'approve')   AS approved_today
        FROM om_card_fraud_alerts
        WHERE created_at >= CURRENT_DATE
    ")->fetch(PDO::FETCH_ASSOC) ?: [];

    $windows = $db->query("
        SELECT
            COUNT(*) FILTER (WHERE action_taken = 'block' AND created_at >= NOW() - INTERVAL '24 hours') AS blocked_24h,
            COUNT(*) FILTER (WHERE action_taken = 'block' AND created_at >= NOW() - INTERVAL '7 days')   AS blocked_7d,
            COUNT(*) FILTER (WHERE status = 'pending')                                                    AS pending_review,
            COUNT(*) FILTER (WHERE status = 'escalated')                                                  AS escalated,
            COALESCE(ROUND(AVG(risk_score)::numeric, 1), 0)                                               AS avg_risk_score
        FROM om_card_fraud_alerts
    ")->fetch(PDO::FETCH_ASSOC) ?: [];

    // Fraud rate: (7d blocked) / (7d total tx)
    $totalTx7d = (int)$db->query("
        SELECT COUNT(*) FROM om_credit_card_transactions
        WHERE created_at >= NOW() - INTERVAL '7 days'
    ")->fetchColumn();
    $fraudRate = $totalTx7d > 0 ? round(100 * ($windows['blocked_7d'] ?? 0) / $totalTx7d, 2) : 0.0;

    // False positive rate: admin approved after block / (admin_override where action was block)
    $fp = $db->query("
        SELECT
            COUNT(*) FILTER (WHERE admin_override = true AND action_taken = 'block') AS total_overridden,
            COUNT(*) FILTER (WHERE admin_override = true AND action_taken = 'block' AND admin_decision = 'approve') AS overridden_approved
        FROM om_card_fraud_alerts
    ")->fetch(PDO::FETCH_ASSOC) ?: [];
    $fpRate = ((int)($fp['total_overridden'] ?? 0)) > 0
        ? round(100 * ($fp['overridden_approved'] ?? 0) / $fp['total_overridden'], 2)
        : 0.0;

    // By action
    $byActionRows = $db->query("
        SELECT action_taken, COUNT(*) AS cnt
        FROM om_card_fraud_alerts
        WHERE created_at >= NOW() - INTERVAL '30 days'
        GROUP BY action_taken
        ORDER BY cnt DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Top rules
    $topRules = [];
    try {
        $rows = $db->query("
            SELECT rule_elem->>'rule' AS rule_key, COUNT(*) AS cnt
            FROM om_card_fraud_alerts,
                 LATERAL jsonb_array_elements(rules_triggered) AS rule_elem
            WHERE created_at >= NOW() - INTERVAL '30 days'
            GROUP BY rule_elem->>'rule'
            ORDER BY cnt DESC
            LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);
        $topRules = $rows;
    } catch (Exception $e) {
        error_log('[admin-card-fraud stats] top rules: ' . $e->getMessage());
    }

    // Score distribution (last 30d)
    $dist = $db->query("
        SELECT
            CASE
                WHEN risk_score >= 80 THEN 'critico (80-100)'
                WHEN risk_score >= 60 THEN 'alto (60-79)'
                WHEN risk_score >= 30 THEN 'medio (30-59)'
                ELSE 'baixo (0-29)'
            END AS band,
            COUNT(*) AS cnt
        FROM om_card_fraud_alerts
        WHERE created_at >= NOW() - INTERVAL '30 days'
        GROUP BY band
        ORDER BY band
    ")->fetchAll(PDO::FETCH_ASSOC);

    response(true, [
        'total_alerts'        => $totalAlerts,
        'blocked_today'       => (int)($today['blocked_today']     ?? 0),
        'challenged_today'    => (int)($today['challenged_today']  ?? 0),
        'reviewed_today'      => (int)($today['reviewed_today']    ?? 0),
        'approved_today'      => (int)($today['approved_today']    ?? 0),
        'blocked_24h'         => (int)($windows['blocked_24h']     ?? 0),
        'blocked_7d'          => (int)($windows['blocked_7d']      ?? 0),
        'pending_review'      => (int)($windows['pending_review']  ?? 0),
        'escalated'           => (int)($windows['escalated']       ?? 0),
        'avg_risk_score'      => (float)($windows['avg_risk_score']?? 0),
        'fraud_rate_7d'       => $fraudRate,
        'false_positive_rate' => $fpRate,
        'by_action'           => $byActionRows,
        'top_rules'           => $topRules,
        'score_distribution'  => $dist,
    ]);
} catch (Exception $e) {
    error_log('[admin-card-fraud stats] ' . $e->getMessage());
    response(false, null, 'Erro ao carregar estatisticas', 500);
}
