<?php
/**
 * GET /admin/card-support/dashboard-overview.php
 *
 * Complete KPI + charts data for the card support homepage.
 *
 * Response.data = {
 *   kpis: {
 *     total_cards, active_cards, all_time_cards,
 *     total_limit_granted, avg_utilization,
 *     open_bills_amount, overdue_amount, overdue_percent,
 *     revenue_month, open_tickets
 *   },
 *   charts: {
 *     revenue_12m:       [{ month, interest, annual_fee, iof, total }, ...],
 *     emitted_12m:       [{ month, count }, ...],
 *     status_distribution: [{ status, count }, ...],
 *     delinquency_by_score:  [{ band, in_debt_pct, total }, ...],
 *     funnel:            [{ step, count }, ...]
 *   },
 *   alerts: {
 *     fraud_today:    [{...}],
 *     late_30_days:   [{...}],
 *     high_utilization:[{...}],
 *     disputes_open:  [{...}],
 *     limit_requests: [{...}]
 *   }
 * }
 */

require_once __DIR__ . "/_common.php";

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        response(false, null, 'Metodo nao permitido', 405);
    }

    $ctx = bootstrapCardSupport();
    $db = $ctx['db'];

    /* -------- KPIs -------- */
    $kpis = $db->query("
        SELECT
            COUNT(*)                                                   AS all_time_cards,
            COUNT(*) FILTER (WHERE status = 'active')                  AS active_cards,
            COUNT(*) FILTER (WHERE status IN ('active','blocked'))     AS total_cards,
            COALESCE(SUM(credit_limit) FILTER (WHERE status = 'active'), 0) AS total_limit_granted,
            COALESCE(SUM(used_limit)   FILTER (WHERE status = 'active'), 0) AS total_used,
            COUNT(*) FILTER (WHERE status = 'active' AND used_limit / NULLIF(credit_limit,0) >= 0.9) AS high_utilization_count
        FROM om_credit_cards
    ")->fetch(PDO::FETCH_ASSOC);

    $billKpis = $db->query("
        SELECT
            COALESCE(SUM(total_amount - paid_amount) FILTER (WHERE status = 'open'), 0)                AS open_bills_amount,
            COALESCE(SUM(total_amount - paid_amount) FILTER (WHERE due_date < CURRENT_DATE AND status != 'paid'), 0) AS overdue_amount,
            COUNT(*) FILTER (WHERE due_date < CURRENT_DATE AND status != 'paid')                       AS overdue_count,
            COUNT(*) FILTER (WHERE status IN ('open','partial'))                                        AS open_count
        FROM om_credit_card_bills
    ")->fetch(PDO::FETCH_ASSOC);

    // Revenue this month (interest + annual_fee + IOF estimated from paid bills)
    try {
        $revenueRow = $db->query("
            SELECT
                COALESCE(SUM(
                    GREATEST(total_amount - COALESCE(
                        (SELECT SUM(t.amount) FROM om_credit_card_transactions t WHERE t.bill_id = b.id AND t.status = 'approved'), 0
                    ), 0)
                ), 0) AS estimated_interest
            FROM om_credit_card_bills b
            WHERE b.paid_at >= date_trunc('month', CURRENT_DATE)
        ")->fetch(PDO::FETCH_ASSOC);
        $revenueMonth = (float)($revenueRow['estimated_interest'] ?? 0);
    } catch (Exception $e) {
        $revenueMonth = 0.0;
    }

    $openTickets = (int)$db->query("SELECT COUNT(*) FROM om_card_support_tickets WHERE status IN ('open','in_progress')")->fetchColumn();
    $urgentTickets = (int)$db->query("SELECT COUNT(*) FROM om_card_support_tickets WHERE priority = 'urgent' AND status NOT IN ('resolved','closed')")->fetchColumn();

    $totalLimit = (float)($kpis['total_limit_granted'] ?? 0);
    $totalUsed  = (float)($kpis['total_used']          ?? 0);
    $avgUtil    = $totalLimit > 0 ? round(($totalUsed / $totalLimit) * 100, 1) : 0;

    $overdueAmount = (float)($billKpis['overdue_amount'] ?? 0);
    $openAmount    = (float)($billKpis['open_bills_amount'] ?? 0);
    $overduePct    = $openAmount > 0 ? round(($overdueAmount / $openAmount) * 100, 1) : 0;

    /* -------- Charts -------- */

    // Revenue last 12 months (approximated via bill deltas)
    $revenue12m = [];
    try {
        $rows = $db->query("
            SELECT to_char(date_trunc('month', paid_at), 'YYYY-MM') AS month,
                   COALESCE(SUM(total_amount - COALESCE((
                       SELECT SUM(t.amount) FROM om_credit_card_transactions t
                       WHERE t.bill_id = b.id AND t.status = 'approved'
                   ), 0)), 0) AS interest,
                   COALESCE(SUM(total_amount) * 0.01, 0) AS iof,
                   0::numeric AS annual_fee
            FROM om_credit_card_bills b
            WHERE paid_at >= date_trunc('month', CURRENT_DATE) - INTERVAL '11 months'
            GROUP BY 1
            ORDER BY 1
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $interest = (float)($r['interest'] ?? 0);
            $iof      = (float)($r['iof']      ?? 0);
            $annual   = (float)($r['annual_fee'] ?? 0);
            $revenue12m[] = [
                'month'       => $r['month'],
                'interest'    => $interest,
                'iof'         => $iof,
                'annual_fee'  => $annual,
                'total'       => $interest + $iof + $annual,
            ];
        }
    } catch (Exception $e) { /* ignore */ }

    // Cards emitted per month
    $emitted12m = [];
    try {
        $rows = $db->query("
            SELECT to_char(date_trunc('month', created_at), 'YYYY-MM') AS month,
                   COUNT(*) AS count
            FROM om_credit_cards
            WHERE created_at >= date_trunc('month', CURRENT_DATE) - INTERVAL '11 months'
            GROUP BY 1
            ORDER BY 1
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $emitted12m[] = [
                'month' => $r['month'],
                'count' => (int)$r['count'],
            ];
        }
    } catch (Exception $e) { /* ignore */ }

    // Status distribution
    $statusDist = [];
    try {
        $rows = $db->query("
            SELECT status, COUNT(*) AS count
            FROM om_credit_cards
            GROUP BY status
            ORDER BY count DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $statusDist[] = ['status' => $r['status'], 'count' => (int)$r['count']];
        }
    } catch (Exception $e) { /* ignore */ }

    // Delinquency by score band
    $scoreBuckets = [];
    try {
        $rows = $db->query("
            SELECT
                CASE
                    WHEN cc.score IS NULL OR cc.score < 400 THEN 'Critico (<400)'
                    WHEN cc.score < 550 THEN 'Baixo (400-549)'
                    WHEN cc.score < 700 THEN 'Regular (550-699)'
                    WHEN cc.score < 850 THEN 'Bom (700-849)'
                    ELSE 'Excelente (850+)'
                END AS band,
                COUNT(*) AS total,
                COUNT(*) FILTER (WHERE EXISTS (
                    SELECT 1 FROM om_credit_card_bills b
                    WHERE b.card_id = cc.id
                      AND b.due_date < CURRENT_DATE
                      AND b.status != 'paid'
                )) AS in_debt
            FROM om_credit_cards cc
            WHERE cc.status IN ('active','blocked')
            GROUP BY 1
            ORDER BY 1
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $total = (int)$r['total'];
            $inDebt = (int)$r['in_debt'];
            $scoreBuckets[] = [
                'band'        => $r['band'],
                'total'       => $total,
                'in_debt'     => $inDebt,
                'in_debt_pct' => $total > 0 ? round(($inDebt / $total) * 100, 1) : 0,
            ];
        }
    } catch (Exception $e) { /* ignore */ }

    // Funnel: pre_approved -> accepted -> activated -> using (has transactions)
    $funnel = [];
    try {
        $preApprovedCount = (int)$db->query("SELECT COUNT(*) FROM om_credit_cards WHERE status = 'pre_approved' OR accepted_at IS NOT NULL OR activated_at IS NOT NULL")->fetchColumn();
        $acceptedCount    = (int)$db->query("SELECT COUNT(*) FROM om_credit_cards WHERE accepted_at IS NOT NULL")->fetchColumn();
        $activatedCount   = (int)$db->query("SELECT COUNT(*) FROM om_credit_cards WHERE activated_at IS NOT NULL")->fetchColumn();
        $usingCount       = (int)$db->query("SELECT COUNT(DISTINCT card_id) FROM om_credit_card_transactions")->fetchColumn();
        $funnel = [
            ['step' => 'Pre-aprovado', 'count' => $preApprovedCount],
            ['step' => 'Aceito',       'count' => $acceptedCount],
            ['step' => 'Ativado',      'count' => $activatedCount],
            ['step' => 'Usando',       'count' => $usingCount],
        ];
    } catch (Exception $e) { /* ignore */ }

    /* -------- Alerts -------- */

    $fraudToday = [];
    try {
        $stmt = $db->query("
            SELECT t.id, t.card_id, t.customer_id, t.amount, t.merchant, t.transaction_date, c.name AS customer_name
            FROM om_credit_card_transactions t
            LEFT JOIN om_customers c ON c.customer_id = t.customer_id
            WHERE t.transaction_date >= CURRENT_DATE AND (t.status = 'flagged' OR t.status = 'declined')
            ORDER BY t.transaction_date DESC
            LIMIT 10
        ");
        $fraudToday = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) { /* ignore */ }

    $late30 = [];
    try {
        $stmt = $db->query("
            SELECT b.id, b.card_id, b.customer_id, b.due_date,
                   (b.total_amount - b.paid_amount) AS outstanding,
                   c.name AS customer_name, c.phone,
                   (CURRENT_DATE - b.due_date) AS days_late
            FROM om_credit_card_bills b
            LEFT JOIN om_customers c ON c.customer_id = b.customer_id
            WHERE b.due_date < CURRENT_DATE - INTERVAL '30 days'
              AND b.status != 'paid'
            ORDER BY b.due_date ASC
            LIMIT 15
        ");
        $late30 = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) { /* ignore */ }

    $highUtil = [];
    try {
        $stmt = $db->query("
            SELECT cc.id, cc.customer_id, cc.credit_limit, cc.used_limit, cc.card_last4,
                   ROUND((cc.used_limit / NULLIF(cc.credit_limit,0)) * 100, 1) AS utilization,
                   c.name AS customer_name
            FROM om_credit_cards cc
            LEFT JOIN om_customers c ON c.customer_id = cc.customer_id
            WHERE cc.status = 'active'
              AND cc.credit_limit > 0
              AND cc.used_limit / cc.credit_limit >= 0.9
            ORDER BY cc.used_limit / cc.credit_limit DESC
            LIMIT 15
        ");
        $highUtil = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) { /* ignore */ }

    $disputesOpen = [];
    try {
        $stmt = $db->query("
            SELECT t.id, t.customer_id, t.subject, t.priority, t.created_at,
                   c.name AS customer_name
            FROM om_card_support_tickets t
            LEFT JOIN om_customers c ON c.customer_id = t.customer_id
            WHERE t.category = 'fraud' AND t.status IN ('open','in_progress')
            ORDER BY t.created_at DESC
            LIMIT 10
        ");
        $disputesOpen = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) { /* ignore */ }

    $limitRequests = [];
    try {
        $stmt = $db->query("
            SELECT t.id, t.customer_id, t.subject, t.priority, t.created_at,
                   c.name AS customer_name
            FROM om_card_support_tickets t
            LEFT JOIN om_customers c ON c.customer_id = t.customer_id
            WHERE t.category = 'limit' AND t.status IN ('open','in_progress')
            ORDER BY t.created_at DESC
            LIMIT 10
        ");
        $limitRequests = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) { /* ignore */ }

    response(true, [
        'kpis' => [
            'total_cards'         => (int)($kpis['total_cards']     ?? 0),
            'active_cards'        => (int)($kpis['active_cards']    ?? 0),
            'all_time_cards'      => (int)($kpis['all_time_cards']  ?? 0),
            'total_limit_granted' => $totalLimit,
            'total_used'          => $totalUsed,
            'avg_utilization'     => $avgUtil,
            'open_bills_amount'   => $openAmount,
            'overdue_amount'      => $overdueAmount,
            'overdue_percent'     => $overduePct,
            'overdue_count'       => (int)($billKpis['overdue_count'] ?? 0),
            'revenue_month'       => $revenueMonth,
            'open_tickets'        => $openTickets,
            'urgent_tickets'      => $urgentTickets,
            'high_utilization_count' => (int)($kpis['high_utilization_count'] ?? 0),
        ],
        'charts' => [
            'revenue_12m'          => $revenue12m,
            'emitted_12m'          => $emitted12m,
            'status_distribution'  => $statusDist,
            'delinquency_by_score' => $scoreBuckets,
            'funnel'               => $funnel,
        ],
        'alerts' => [
            'fraud_today'     => $fraudToday,
            'late_30_days'    => $late30,
            'high_utilization'=> $highUtil,
            'disputes_open'   => $disputesOpen,
            'limit_requests'  => $limitRequests,
        ],
    ]);
} catch (Exception $e) {
    error_log('[card-support-overview] ' . $e->getMessage());
    response(false, null, 'Erro ao carregar overview: ' . $e->getMessage(), 500);
}
