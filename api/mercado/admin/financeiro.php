<?php
/**
 * /api/mercado/admin/financeiro.php
 *
 * iFood-level financial overview endpoint.
 *
 * GET: Financial overview with period filtering.
 *   Params: period (7d/14d/30d/60d/90d)
 *   Returns: GMV, commission, delivery fees, refunds, daily breakdown,
 *            payment method split, top 10 partners, pending payouts,
 *            period comparison, refund rate and trend.
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        response(false, null, "Metodo nao permitido", 405);
    }

    $period = $_GET['period'] ?? '30d';
    // Whitelist allowed period values to prevent SQL injection via INTERVAL
    $periodMap = ['7d' => 7, '14d' => 14, '30d' => 30, '60d' => 60, '90d' => 90];
    $days = $periodMap[$period] ?? 30;
    $interval_param = $days . ' days';

    // ── GMV (Gross Merchandise Value) ──
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(total), 0) as gmv,
               COUNT(*) as total_orders
        FROM om_market_orders
        WHERE created_at >= CURRENT_DATE - CAST(? AS INTERVAL)
          AND status NOT IN ('cancelled','refunded')
    ");
    $stmt->execute([$interval_param]);
    $gmv_row = $stmt->fetch();
    $gmv = round((float)$gmv_row['gmv'], 2);
    $total_orders = (int)$gmv_row['total_orders'];

    // ── Commission earned (platform_fee or service_fee) ──
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(COALESCE(platform_fee, service_fee, 0)), 0) as commission
        FROM om_market_orders
        WHERE created_at >= CURRENT_DATE - CAST(? AS INTERVAL)
          AND status NOT IN ('cancelled','refunded')
    ");
    $stmt->execute([$interval_param]);
    $commission = round((float)$stmt->fetch()['commission'], 2);

    // ── Delivery fees ──
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(delivery_fee), 0) as delivery_fees
        FROM om_market_orders
        WHERE created_at >= CURRENT_DATE - CAST(? AS INTERVAL)
          AND status NOT IN ('cancelled','refunded')
    ");
    $stmt->execute([$interval_param]);
    $delivery_fees = round((float)$stmt->fetch()['delivery_fees'], 2);

    // ── Refund total + count ──
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(total), 0) as refund_total,
               COUNT(*) as refund_count
        FROM om_market_orders
        WHERE created_at >= CURRENT_DATE - CAST(? AS INTERVAL)
          AND status = 'refunded'
    ");
    $stmt->execute([$interval_param]);
    $refund_row = $stmt->fetch();
    $refund_total = round((float)$refund_row['refund_total'], 2);
    $refund_count = (int)$refund_row['refund_count'];

    // ── Refund rate ──
    $stmt = $db->prepare("
        SELECT COUNT(*) as all_orders
        FROM om_market_orders
        WHERE created_at >= CURRENT_DATE - CAST(? AS INTERVAL)
    ");
    $stmt->execute([$interval_param]);
    $all_orders_count = (int)$stmt->fetch()['all_orders'];
    $refund_rate = $all_orders_count > 0 ? round(($refund_count / $all_orders_count) * 100, 2) : 0;

    // ── Daily revenue breakdown ──
    $trend_days = min($days, 90);
    $trend_interval = $trend_days . ' days';
    $stmt = $db->prepare("
        SELECT DATE(created_at) as date,
               COUNT(*) as orders,
               COALESCE(SUM(total), 0) as gmv,
               COALESCE(SUM(COALESCE(platform_fee, service_fee, 0)), 0) as commission,
               COALESCE(SUM(delivery_fee), 0) as delivery_fees,
               COUNT(DISTINCT customer_id) as customers
        FROM om_market_orders
        WHERE created_at >= CURRENT_DATE - CAST(? AS INTERVAL)
          AND status NOT IN ('cancelled','refunded')
        GROUP BY DATE(created_at)
        ORDER BY date ASC
    ");
    $stmt->execute([$trend_interval]);
    $daily_breakdown = $stmt->fetchAll();

    // Format numeric fields in daily breakdown
    foreach ($daily_breakdown as &$day) {
        $day['orders'] = (int)$day['orders'];
        $day['gmv'] = round((float)$day['gmv'], 2);
        $day['commission'] = round((float)$day['commission'], 2);
        $day['delivery_fees'] = round((float)$day['delivery_fees'], 2);
        $day['customers'] = (int)$day['customers'];
    }
    unset($day);

    // ── Payment method split ──
    $stmt = $db->prepare("
        SELECT payment_method,
               COUNT(*) as count,
               COALESCE(SUM(total), 0) as total
        FROM om_market_orders
        WHERE created_at >= CURRENT_DATE - CAST(? AS INTERVAL)
          AND status NOT IN ('cancelled','refunded')
        GROUP BY payment_method
        ORDER BY count DESC
    ");
    $stmt->execute([$interval_param]);
    $payment_methods = $stmt->fetchAll();

    foreach ($payment_methods as &$pm) {
        $pm['count'] = (int)$pm['count'];
        $pm['total'] = round((float)$pm['total'], 2);
    }
    unset($pm);

    // ── Top 10 partners by revenue ──
    $stmt = $db->prepare("
        SELECT p.partner_id, p.name, p.logo,
               COUNT(o.order_id) as total_orders,
               COALESCE(SUM(o.total), 0) as revenue,
               COALESCE(AVG(o.total), 0) as avg_ticket
        FROM om_market_orders o
        JOIN om_market_partners p ON p.partner_id = o.partner_id
        WHERE o.created_at >= CURRENT_DATE - CAST(? AS INTERVAL)
          AND o.status NOT IN ('cancelled','refunded')
        GROUP BY p.partner_id, p.name, p.logo
        ORDER BY revenue DESC
        LIMIT 10
    ");
    $stmt->execute([$interval_param]);
    $top_partners = $stmt->fetchAll();

    foreach ($top_partners as &$tp) {
        $tp['total_orders'] = (int)$tp['total_orders'];
        $tp['revenue'] = round((float)$tp['revenue'], 2);
        $tp['avg_ticket'] = round((float)$tp['avg_ticket'], 2);
    }
    unset($tp);

    // ── Pending payouts ──
    $pending_payouts = ['count' => 0, 'total' => 0];
    try {
        $stmt = $db->query("
            SELECT COUNT(*) as count, COALESCE(SUM(amount), 0) as total
            FROM om_market_repasses
            WHERE status = 'pending'
        ");
        $row = $stmt->fetch();
        $pending_payouts = [
            'count' => (int)($row['count'] ?? 0),
            'total' => round((float)($row['total'] ?? 0), 2),
        ];
    } catch (Exception $e) {
        // Table may not exist
    }

    // ── Period comparison (current vs previous) ──
    $prev_start = ($days * 2) . ' days';
    $prev_end = $days . ' days';
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(total), 0) as gmv,
               COUNT(*) as orders,
               COALESCE(SUM(COALESCE(platform_fee, service_fee, 0)), 0) as commission
        FROM om_market_orders
        WHERE created_at >= CURRENT_DATE - CAST(? AS INTERVAL)
          AND created_at < CURRENT_DATE - CAST(? AS INTERVAL)
          AND status NOT IN ('cancelled','refunded')
    ");
    $stmt->execute([$prev_start, $prev_end]);
    $prev = $stmt->fetch();
    $prev_gmv = (float)$prev['gmv'];
    $prev_orders = (int)$prev['orders'];
    $prev_commission = (float)$prev['commission'];

    $gmv_change = $prev_gmv > 0 ? round((($gmv - $prev_gmv) / $prev_gmv) * 100, 1) : 0;
    $orders_change = $prev_orders > 0 ? round((($total_orders - $prev_orders) / $prev_orders) * 100, 1) : 0;
    $commission_change = $prev_commission > 0 ? round((($commission - $prev_commission) / $prev_commission) * 100, 1) : 0;

    // ── Previous period refund rate for trend ──
    $stmt = $db->prepare("
        SELECT COUNT(*) as all_orders,
               SUM(CASE WHEN status = 'refunded' THEN 1 ELSE 0 END) as refund_count
        FROM om_market_orders
        WHERE created_at >= CURRENT_DATE - CAST(? AS INTERVAL)
          AND created_at < CURRENT_DATE - CAST(? AS INTERVAL)
    ");
    $stmt->execute([$prev_start, $prev_end]);
    $prev_ref = $stmt->fetch();
    $prev_all = (int)($prev_ref['all_orders'] ?? 0);
    $prev_refund_count = (int)($prev_ref['refund_count'] ?? 0);
    $prev_refund_rate = $prev_all > 0 ? round(($prev_refund_count / $prev_all) * 100, 2) : 0;
    $refund_rate_change = $prev_refund_rate > 0 ? round($refund_rate - $prev_refund_rate, 2) : 0;

    $net_revenue = $commission + $delivery_fees - $refund_total;

    response(true, [
        'period' => $period,
        'days' => $days,
        'gmv' => $gmv,
        'total_orders' => $total_orders,
        'commission' => $commission,
        'delivery_fees' => $delivery_fees,
        'refund_total' => $refund_total,
        'refund_count' => $refund_count,
        'refund_rate' => $refund_rate,
        'net_revenue' => round($net_revenue, 2),
        'daily_breakdown' => $daily_breakdown,
        'payment_methods' => $payment_methods,
        'top_partners' => $top_partners,
        'pending_payouts' => $pending_payouts,
        'comparison' => [
            'gmv_change' => $gmv_change,
            'orders_change' => $orders_change,
            'commission_change' => $commission_change,
            'prev_gmv' => round($prev_gmv, 2),
            'prev_orders' => $prev_orders,
            'prev_commission' => round($prev_commission, 2),
        ],
        'refund_trend' => [
            'current_rate' => $refund_rate,
            'previous_rate' => $prev_refund_rate,
            'change' => $refund_rate_change,
        ],
    ], "Dados financeiros carregados");

} catch (Exception $e) {
    error_log("[admin/financeiro] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
