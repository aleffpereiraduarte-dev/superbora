<?php
/**
 * GET /api/mercado/partner/financeiro.php
 * Partner financial dashboard: revenue summary, daily breakdown, payment split,
 * pending payouts, recent transactions, refund totals.
 * Params: period=today|week|month (default: month)
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $payload = om_auth()->requirePartner();
    $partnerId = (int)$payload['uid'];

    if ($_SERVER["REQUEST_METHOD"] !== "GET") {
        response(false, null, "Metodo nao permitido", 405);
    }

    $period = $_GET['period'] ?? 'month';
    if (!in_array($period, ['today', 'week', 'month'], true)) {
        $period = 'month';
    }

    // Determine date range
    $now = new DateTime();
    switch ($period) {
        case 'today':
            $startDate = $now->format('Y-m-d');
            $endDate = $startDate;
            break;
        case 'week':
            $startDate = (clone $now)->modify('-7 days')->format('Y-m-d');
            $endDate = $now->format('Y-m-d');
            break;
        case 'month':
        default:
            $startDate = (clone $now)->modify('-30 days')->format('Y-m-d');
            $endDate = $now->format('Y-m-d');
            break;
    }

    // Get commission rate from om_market_partners (default 15%)
    $commissionRate = 15.0;
    try {
        $stmtComm = $db->prepare("SELECT commission_rate FROM om_market_partners WHERE partner_id = ?");
        $stmtComm->execute([$partnerId]);
        $commRow = $stmtComm->fetch();
        if ($commRow && $commRow['commission_rate'] !== null) {
            $commissionRate = (float)$commRow['commission_rate'];
        }
    } catch (Exception $e) {
        // Table/column may not exist, use default
    }

    // ── Revenue summary: today, this_week, this_month ──
    $stmtToday = $db->prepare("
        SELECT COALESCE(SUM(total), 0) as revenue, COUNT(*) as orders
        FROM om_market_orders
        WHERE partner_id = ? AND DATE(date_added) = CURRENT_DATE
          AND status = 'entregue'
    ");
    $stmtToday->execute([$partnerId]);
    $todayRow = $stmtToday->fetch();

    $stmtWeek = $db->prepare("
        SELECT COALESCE(SUM(total), 0) as revenue, COUNT(*) as orders
        FROM om_market_orders
        WHERE partner_id = ? AND DATE(date_added) >= CURRENT_DATE - INTERVAL '7 days'
          AND status = 'entregue'
    ");
    $stmtWeek->execute([$partnerId]);
    $weekRow = $stmtWeek->fetch();

    $stmtMonth = $db->prepare("
        SELECT COALESCE(SUM(total), 0) as revenue, COUNT(*) as orders
        FROM om_market_orders
        WHERE partner_id = ? AND DATE(date_added) >= CURRENT_DATE - INTERVAL '30 days'
          AND status = 'entregue'
    ");
    $stmtMonth->execute([$partnerId]);
    $monthRow = $stmtMonth->fetch();

    $summary = [
        'today' => [
            'revenue' => round((float)$todayRow['revenue'], 2),
            'orders' => (int)$todayRow['orders']
        ],
        'this_week' => [
            'revenue' => round((float)$weekRow['revenue'], 2),
            'orders' => (int)$weekRow['orders']
        ],
        'this_month' => [
            'revenue' => round((float)$monthRow['revenue'], 2),
            'orders' => (int)$monthRow['orders']
        ]
    ];

    // ── Daily revenue breakdown (last 30 days) ──
    $stmtDaily = $db->prepare("
        SELECT
            DATE(date_added) as date,
            COUNT(*) as orders_count,
            COALESCE(SUM(total), 0) as gmv,
            COALESCE(SUM(COALESCE(platform_fee, service_fee, total * ? / 100)), 0) as commission
        FROM om_market_orders
        WHERE partner_id = ?
          AND DATE(date_added) >= CURRENT_DATE - INTERVAL '30 days'
          AND status = 'entregue'
        GROUP BY DATE(date_added)
        ORDER BY DATE(date_added) DESC
    ");
    $stmtDaily->execute([$commissionRate, $partnerId]);
    $dailyRows = $stmtDaily->fetchAll();

    $dailyBreakdown = [];
    foreach ($dailyRows as $row) {
        $gmv = round((float)$row['gmv'], 2);
        $commission = round((float)$row['commission'], 2);
        $dailyBreakdown[] = [
            'date' => $row['date'],
            'orders_count' => (int)$row['orders_count'],
            'gmv' => $gmv,
            'commission' => $commission,
            'net' => round($gmv - $commission, 2)
        ];
    }

    // ── Payment method split ──
    $stmtPayment = $db->prepare("
        SELECT
            COALESCE(forma_pagamento, 'outro') as method,
            COUNT(*) as count,
            COALESCE(SUM(total), 0) as total
        FROM om_market_orders
        WHERE partner_id = ?
          AND DATE(date_added) BETWEEN ? AND ?
          AND status = 'entregue'
        GROUP BY forma_pagamento
        ORDER BY total DESC
    ");
    $stmtPayment->execute([$partnerId, $startDate, $endDate]);
    $paymentRows = $stmtPayment->fetchAll();

    $paymentSplit = [];
    foreach ($paymentRows as $row) {
        $paymentSplit[] = [
            'method' => $row['method'],
            'count' => (int)$row['count'],
            'total' => round((float)$row['total'], 2)
        ];
    }

    // ── Pending payouts ──
    $pendingPayouts = ['count' => 0, 'total' => 0];
    try {
        $stmtPayouts = $db->prepare("
            SELECT COUNT(*) as count, COALESCE(SUM(amount), 0) as total
            FROM om_market_repasses
            WHERE partner_id = ? AND status = 'pendente'
        ");
        $stmtPayouts->execute([$partnerId]);
        $payoutRow = $stmtPayouts->fetch();
        $pendingPayouts = [
            'count' => (int)$payoutRow['count'],
            'total' => round((float)$payoutRow['total'], 2)
        ];
    } catch (Exception $e) {
        // Table may not exist
    }

    // ── Recent transactions (last 20 orders) ──
    $stmtRecent = $db->prepare("
        SELECT
            order_id,
            total,
            COALESCE(platform_fee, service_fee, total * ? / 100) as commission,
            status,
            forma_pagamento,
            date_added
        FROM om_market_orders
        WHERE partner_id = ?
          AND DATE(date_added) BETWEEN ? AND ?
        ORDER BY date_added DESC
        LIMIT 20
    ");
    $stmtRecent->execute([$commissionRate, $partnerId, $startDate, $endDate]);
    $recentRows = $stmtRecent->fetchAll();

    $recentTransactions = [];
    foreach ($recentRows as $row) {
        $total = round((float)$row['total'], 2);
        $commission = round((float)$row['commission'], 2);
        $recentTransactions[] = [
            'order_id' => (int)$row['order_id'],
            'total' => $total,
            'commission' => $commission,
            'net' => round($total - $commission, 2),
            'status' => $row['status'],
            'payment_method' => $row['forma_pagamento'],
            'date' => $row['date_added']
        ];
    }

    // ── Refund total for period ──
    $stmtRefunds = $db->prepare("
        SELECT COUNT(*) as count, COALESCE(SUM(total), 0) as total
        FROM om_market_orders
        WHERE partner_id = ?
          AND DATE(date_added) BETWEEN ? AND ?
          AND status IN ('refunded', 'reembolsado')
    ");
    $stmtRefunds->execute([$partnerId, $startDate, $endDate]);
    $refundRow = $stmtRefunds->fetch();

    $refunds = [
        'count' => (int)$refundRow['count'],
        'total' => round((float)$refundRow['total'], 2)
    ];

    response(true, [
        'period' => $period,
        'date_range' => ['start' => $startDate, 'end' => $endDate],
        'commission_rate' => $commissionRate,
        'summary' => $summary,
        'daily_breakdown' => $dailyBreakdown,
        'payment_split' => $paymentSplit,
        'pending_payouts' => $pendingPayouts,
        'recent_transactions' => $recentTransactions,
        'refunds' => $refunds
    ], "Dados financeiros carregados");

} catch (Exception $e) {
    error_log("[partner/financeiro] Erro: " . $e->getMessage());
    response(false, null, "Erro ao carregar dados financeiros", 500);
}
