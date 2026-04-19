<?php
/**
 * GET /admin/card-support/ai-analysis.php
 *
 * Score distribution + AI evaluation stats.
 *
 * Response.data = {
 *   score_distribution: [ { bucket, count } ],
 *   evaluations_summary: { total, approved, declined, override_rate, accept_rate_today, accept_rate_lastweek },
 *   factor_contribution: [ { factor, weight, avg_score } ]
 * }
 */

require_once __DIR__ . "/_common.php";

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        response(false, null, 'Metodo nao permitido', 405);
    }

    $ctx = bootstrapCardSupport();
    $db = $ctx['db'];

    // Score distribution - histogram
    $buckets = [];
    try {
        $rows = $db->query("
            SELECT width_bucket(COALESCE(score, 0), 0, 1000, 10) AS bucket,
                   COUNT(*) AS cnt
            FROM om_credit_cards
            WHERE score IS NOT NULL
            GROUP BY 1
            ORDER BY 1
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $bucketStart = ((int)$r['bucket'] - 1) * 100;
            $buckets[] = [
                'bucket' => $bucketStart . '-' . ($bucketStart + 99),
                'count'  => (int)$r['cnt'],
            ];
        }
    } catch (Exception $e) { /* ignore */ }

    // Evaluations summary (if table exists)
    $evaluationsSummary = [
        'total'      => 0,
        'approved'   => 0,
        'declined'   => 0,
        'override_rate'        => 0,
        'accept_rate_today'    => 0,
        'accept_rate_lastweek' => 0,
    ];
    try {
        $row = $db->query("
            SELECT
                COUNT(*)                                                                AS total,
                COUNT(*) FILTER (WHERE final_decision = 'approved')                      AS approved,
                COUNT(*) FILTER (WHERE final_decision = 'declined')                      AS declined,
                COUNT(*) FILTER (WHERE ai_decision != final_decision)                    AS overrides,
                COUNT(*) FILTER (WHERE evaluated_at >= CURRENT_DATE)                     AS today_total,
                COUNT(*) FILTER (WHERE evaluated_at >= CURRENT_DATE AND ai_decision = 'approved') AS today_approved,
                COUNT(*) FILTER (WHERE evaluated_at BETWEEN NOW() - INTERVAL '14 days' AND NOW() - INTERVAL '7 days') AS prevweek_total,
                COUNT(*) FILTER (WHERE evaluated_at BETWEEN NOW() - INTERVAL '14 days' AND NOW() - INTERVAL '7 days' AND ai_decision = 'approved') AS prevweek_approved
            FROM om_credit_evaluations
        ")->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $total = (int)$row['total'];
            $overrides = (int)$row['overrides'];
            $evaluationsSummary = [
                'total'                => $total,
                'approved'             => (int)$row['approved'],
                'declined'             => (int)$row['declined'],
                'override_rate'        => $total > 0 ? round($overrides / $total * 100, 1) : 0,
                'accept_rate_today'    => (int)$row['today_total'] > 0 ? round((int)$row['today_approved'] / (int)$row['today_total'] * 100, 1) : 0,
                'accept_rate_lastweek' => (int)$row['prevweek_total'] > 0 ? round((int)$row['prevweek_approved'] / (int)$row['prevweek_total'] * 100, 1) : 0,
            ];
        }
    } catch (Exception $e) { /* table may not exist */ }

    // Factor contribution (approximation using card features)
    $factors = [];
    try {
        $avgByScore = $db->query("
            SELECT
                COALESCE(AVG(COALESCE(declared_income,0)) FILTER (WHERE score >= 700), 0) AS income_high,
                COALESCE(AVG(COALESCE(declared_income,0)) FILTER (WHERE score < 500), 0)  AS income_low,
                COALESCE(AVG(used_limit / NULLIF(credit_limit,0)) FILTER (WHERE score >= 700), 0)  AS util_high,
                COALESCE(AVG(used_limit / NULLIF(credit_limit,0)) FILTER (WHERE score < 500), 0)   AS util_low
            FROM om_credit_cards
        ")->fetch(PDO::FETCH_ASSOC);
        $factors = [
            ['factor' => 'Renda declarada',      'weight' => 40, 'high_avg' => round((float)$avgByScore['income_high']), 'low_avg' => round((float)$avgByScore['income_low'])],
            ['factor' => 'Historico de crédito', 'weight' => 25, 'high_avg' => 0, 'low_avg' => 0],
            ['factor' => 'Utilização atual',     'weight' => 20, 'high_avg' => round((float)$avgByScore['util_high'] * 100, 1), 'low_avg' => round((float)$avgByScore['util_low'] * 100, 1)],
            ['factor' => 'Inadimplência',        'weight' => 15, 'high_avg' => 0, 'low_avg' => 0],
        ];
    } catch (Exception $e) { /* ignore */ }

    // Approval rate by day (last 14 days)
    $dailyApprovals = [];
    try {
        $rows = $db->query("
            SELECT to_char(date_trunc('day', evaluated_at), 'YYYY-MM-DD') AS d,
                   COUNT(*) AS total,
                   COUNT(*) FILTER (WHERE final_decision = 'approved') AS approved
            FROM om_credit_evaluations
            WHERE evaluated_at >= NOW() - INTERVAL '14 days'
            GROUP BY 1
            ORDER BY 1
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $dailyApprovals[] = [
                'date'     => $r['d'],
                'total'    => (int)$r['total'],
                'approved' => (int)$r['approved'],
                'rate'     => (int)$r['total'] > 0 ? round((int)$r['approved'] / (int)$r['total'] * 100, 1) : 0,
            ];
        }
    } catch (Exception $e) { /* ignore */ }

    response(true, [
        'score_distribution'  => $buckets,
        'evaluations_summary' => $evaluationsSummary,
        'factor_contribution' => $factors,
        'daily_approvals'     => $dailyApprovals,
    ]);
} catch (Exception $e) {
    error_log('[card-support-ai-analysis] ' . $e->getMessage());
    response(false, null, 'Erro na analise IA: ' . $e->getMessage(), 500);
}
