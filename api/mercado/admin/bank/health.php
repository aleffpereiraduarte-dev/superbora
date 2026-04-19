<?php
/**
 * GET /admin/bank/health.php
 *
 * Bank P&L + real-time operational metrics for the admin dashboard.
 *
 * Response.data:
 *   - metrics: counters returned by SuperBoraBankBrain::getHealthMetrics()
 *   - policy:  current autonomous policy knobs
 *   - trends:
 *       liquidity_30d:      [{ date, total_wallet, total_credit_used, total_rendimento }]
 *       issuances_30d:      [{ date, count }]
 *       limit_changes_30d:  [{ date, increases, decreases }]
 *   - alerts:  real-time alerts (high NPL, large overdue, fraud spikes)
 */

require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../helpers/bank-brain.php";
require_once dirname(__DIR__, 4) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        response(false, null, 'Metodo nao permitido', 405);
    }
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    om_auth()->requireAdmin();

    $brain = new SuperBoraBankBrain($db);
    $metrics = $brain->getHealthMetrics();
    $policy  = $brain->getPolicy();

    // Liquidity trend
    $liquidity = $db->query("
        SELECT snap_date, total_wallet_balance, total_rendimento, total_credit_used, total_credit_granted
        FROM om_bank_liquidity_snapshots
        WHERE snap_date >= CURRENT_DATE - INTERVAL '30 days'
        ORDER BY snap_date
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Issuances trend
    $issuances = $db->query("
        SELECT created_at::date AS date, COUNT(*) AS count
        FROM om_credit_card_events
        WHERE event_type IN ('offer_created','offer_accepted')
          AND created_at >= NOW() - INTERVAL '30 days'
        GROUP BY created_at::date
        ORDER BY date
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Limit changes trend
    $limits = $db->query("
        SELECT created_at::date AS date,
               COUNT(*) FILTER (WHERE (payload->>'new_limit')::numeric > (payload->>'old_limit')::numeric) AS increases,
               COUNT(*) FILTER (WHERE (payload->>'new_limit')::numeric < (payload->>'old_limit')::numeric) AS decreases
        FROM om_credit_card_events
        WHERE event_type = 'limit_adjusted'
          AND created_at >= NOW() - INTERVAL '30 days'
        GROUP BY created_at::date
        ORDER BY date
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Alerts
    $alerts = [];
    if ($metrics['npl_rate'] > 15) {
        $alerts[] = ['level' => 'danger', 'msg' => "NPL rate {$metrics['npl_rate']}% acima de 15%"];
    } elseif ($metrics['npl_rate'] > 8) {
        $alerts[] = ['level' => 'warning', 'msg' => "NPL rate {$metrics['npl_rate']}% elevado"];
    }
    if ($metrics['overdue_amount'] > 10000) {
        $alerts[] = ['level' => 'warning',
            'msg' => 'Valor atrasado R$ ' . number_format($metrics['overdue_amount'], 2, ',', '.')];
    }
    if ($metrics['fraud_blocks_today'] >= 5) {
        $alerts[] = ['level' => 'warning',
            'msg' => "{$metrics['fraud_blocks_today']} bloqueios automaticos hoje"];
    }
    if ($metrics['utilization_rate'] > 80) {
        $alerts[] = ['level' => 'info', 'msg' => "Utilizacao media de limite em {$metrics['utilization_rate']}%"];
    }

    // Revenue projection
    $projectedMonthly = $metrics['revenue_30d_interest'] + $metrics['revenue_30d_fees'];
    $costOfCapital = round($metrics['total_credit_used'] * 0.009, 2);
    $netMargin = $projectedMonthly - $costOfCapital;

    response(true, [
        'metrics' => array_merge($metrics, [
            'projected_monthly_revenue' => round($projectedMonthly, 2),
            'cost_of_capital'           => $costOfCapital,
            'net_margin'                => round($netMargin, 2),
        ]),
        'policy' => $policy,
        'trends' => [
            'liquidity_30d'     => array_map(function ($r) {
                return [
                    'date' => $r['snap_date'],
                    'total_wallet' => (float)$r['total_wallet_balance'],
                    'total_rendimento' => (float)$r['total_rendimento'],
                    'total_credit_used' => (float)$r['total_credit_used'],
                    'total_credit_granted' => (float)$r['total_credit_granted'],
                ];
            }, $liquidity),
            'issuances_30d'     => array_map(function ($r) {
                return ['date' => $r['date'], 'count' => (int)$r['count']];
            }, $issuances),
            'limit_changes_30d' => array_map(function ($r) {
                return ['date' => $r['date'], 'increases' => (int)$r['increases'], 'decreases' => (int)$r['decreases']];
            }, $limits),
        ],
        'alerts' => $alerts,
    ]);
} catch (Exception $e) {
    error_log('[admin-bank-health] ' . $e->getMessage());
    response(false, null, 'Erro ao carregar bank health', 500);
}
