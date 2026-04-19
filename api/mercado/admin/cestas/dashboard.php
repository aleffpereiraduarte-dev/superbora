<?php
/**
 * /api/mercado/admin/cestas/dashboard.php
 * Overview dashboard stats for basket subscriptions (admin only).
 *
 * GET — Returns all KPIs and charts for the main cestas dashboard.
 */
require_once __DIR__ . "/../../config/database.php";
require_once dirname(__DIR__, 4) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    $payload = om_auth()->requireAdmin();

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        response(false, null, 'Metodo nao permitido', 405);
    }

    // ── KPIs ─────────────────────────────────────────────────────

    // Subscription counts
    $stmt = $db->query("
        SELECT
            COUNT(*) AS total_subscriptions,
            COUNT(*) FILTER (WHERE status = 'active') AS active,
            COUNT(*) FILTER (WHERE status = 'paused') AS paused,
            COUNT(*) FILTER (WHERE status = 'cancelled') AS cancelled
        FROM om_box_subscriptions
    ");
    $subCounts = $stmt->fetch(PDO::FETCH_ASSOC);

    // Revenue (this month)
    $monthStart = date('Y-m-01');
    $monthEnd = date('Y-m-t');
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(amount), 0) AS monthly_revenue
        FROM om_cesta_payments
        WHERE status = 'paid' AND created_at::date BETWEEN ? AND ?
    ");
    $stmt->execute([$monthStart, $monthEnd]);
    $monthlyRevenue = (float)$stmt->fetchColumn();

    // Previous month revenue (for comparison)
    $prevMonthStart = date('Y-m-01', strtotime('-1 month'));
    $prevMonthEnd = date('Y-m-t', strtotime('-1 month'));
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(amount), 0) AS prev_revenue
        FROM om_cesta_payments
        WHERE status = 'paid' AND created_at::date BETWEEN ? AND ?
    ");
    $stmt->execute([$prevMonthStart, $prevMonthEnd]);
    $prevRevenue = (float)$stmt->fetchColumn();

    $revenueGrowth = $prevRevenue > 0
        ? round((($monthlyRevenue - $prevRevenue) / $prevRevenue) * 100, 1)
        : ($monthlyRevenue > 0 ? 100 : 0);

    // Estimated monthly recurring revenue
    $stmt = $db->query("
        SELECT COALESCE(SUM(
            CASE
                WHEN s.frequency = 'weekly' THEN b.base_price * 4
                WHEN s.frequency = 'biweekly' THEN b.base_price * 2
                WHEN s.frequency = '3x_week' THEN b.base_price * 12
                WHEN s.frequency = 'daily' THEN b.base_price * 30
                ELSE b.base_price
            END
        ), 0) AS mrr
        FROM om_box_subscriptions s
        JOIN om_subscription_boxes b ON s.box_id = b.id
        WHERE s.status = 'active'
    ");
    $mrr = round((float)$stmt->fetchColumn(), 2);

    // Today's deliveries
    $today = date('Y-m-d');
    $stmt = $db->prepare("
        SELECT
            COUNT(*) AS total,
            COUNT(*) FILTER (WHERE status = 'delivered') AS delivered,
            COUNT(*) FILTER (WHERE status IN ('scheduled', 'preparing', 'ready', 'out_for_delivery')) AS pending,
            COUNT(*) FILTER (WHERE status = 'failed') AS failed
        FROM om_cesta_orders
        WHERE delivery_date = ?
    ");
    $stmt->execute([$today]);
    $todayDeliveries = $stmt->fetch(PDO::FETCH_ASSOC);

    // This week's deliveries
    $weekStart = date('Y-m-d', strtotime('monday this week'));
    $weekEnd = date('Y-m-d', strtotime('sunday this week'));
    $stmt = $db->prepare("
        SELECT COUNT(*) AS total FROM om_cesta_orders
        WHERE delivery_date BETWEEN ? AND ? AND status != 'cancelled'
    ");
    $stmt->execute([$weekStart, $weekEnd]);
    $weekOrders = (int)$stmt->fetchColumn();

    // Growth: new subs this month vs last month
    $stmt = $db->prepare("
        SELECT COUNT(*) AS new_subs FROM om_box_subscriptions
        WHERE created_at::date BETWEEN ? AND ?
    ");
    $stmt->execute([$monthStart, $monthEnd]);
    $newSubsThisMonth = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT COUNT(*) AS new_subs FROM om_box_subscriptions
        WHERE created_at::date BETWEEN ? AND ?
    ");
    $stmt->execute([$prevMonthStart, $prevMonthEnd]);
    $newSubsLastMonth = (int)$stmt->fetchColumn();

    $subsGrowth = $newSubsLastMonth > 0
        ? round((($newSubsThisMonth - $newSubsLastMonth) / $newSubsLastMonth) * 100, 1)
        : ($newSubsThisMonth > 0 ? 100 : 0);

    // Churn rate (cancellations this month / total active at start of month)
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM om_box_subscriptions
        WHERE status = 'cancelled' AND cancelled_at::date BETWEEN ? AND ?
    ");
    $stmt->execute([$monthStart, $monthEnd]);
    $cancelledThisMonth = (int)$stmt->fetchColumn();

    $activeAtStart = (int)$subCounts['active'] + $cancelledThisMonth;
    $churnRate = $activeAtStart > 0 ? round(($cancelledThisMonth / $activeAtStart) * 100, 1) : 0;

    // ── Charts ───────────────────────────────────────────────────

    // Popular basket types
    $stmt = $db->query("
        SELECT b.name, COUNT(s.id) AS subscribers
        FROM om_subscription_boxes b
        LEFT JOIN om_box_subscriptions s ON s.box_id = b.id AND s.status = 'active'
        WHERE b.is_active = true
        GROUP BY b.id, b.name
        ORDER BY subscribers DESC
        LIMIT 8
    ");
    $popularTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Delivery day distribution
    $stmt = $db->query("
        SELECT
            delivery_day,
            COUNT(*) AS count
        FROM om_box_subscriptions
        WHERE status = 'active'
        GROUP BY delivery_day
        ORDER BY delivery_day
    ");
    $deliveryDays = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $dayNames = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sab'];
    foreach ($deliveryDays as &$dd) {
        $dd['day_name'] = $dayNames[(int)$dd['delivery_day']] ?? '?';
        $dd['count'] = (int)$dd['count'];
    }

    // City breakdown
    $stmt = $db->query("
        SELECT
            COALESCE(city, 'Nao informada') AS city,
            COUNT(*) AS count
        FROM om_box_subscriptions
        WHERE status = 'active'
        GROUP BY city
        ORDER BY count DESC
        LIMIT 10
    ");
    $cityBreakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Weekly trend (last 8 weeks)
    $stmt = $db->query("
        SELECT
            DATE_TRUNC('week', created_at)::date AS week_start,
            COUNT(*) AS new_subscriptions
        FROM om_box_subscriptions
        WHERE created_at >= NOW() - INTERVAL '8 weeks'
        GROUP BY week_start
        ORDER BY week_start ASC
    ");
    $weeklyTrend = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Frequency distribution
    $stmt = $db->query("
        SELECT frequency, COUNT(*) AS count
        FROM om_box_subscriptions
        WHERE status = 'active'
        GROUP BY frequency
        ORDER BY count DESC
    ");
    $freqDist = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Upcoming deliveries (next 7 days)
    $stmt = $db->prepare("
        SELECT
            delivery_date,
            COUNT(*) AS total_orders,
            COUNT(*) FILTER (WHERE status = 'scheduled') AS pending
        FROM om_cesta_orders
        WHERE delivery_date BETWEEN ? AND ?
          AND status NOT IN ('cancelled')
        GROUP BY delivery_date
        ORDER BY delivery_date ASC
    ");
    $stmt->execute([$today, date('Y-m-d', strtotime('+7 days'))]);
    $upcoming = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Average basket value
    $stmt = $db->query("
        SELECT COALESCE(AVG(b.base_price), 0) AS avg_value
        FROM om_box_subscriptions s
        JOIN om_subscription_boxes b ON s.box_id = b.id
        WHERE s.status = 'active'
    ");
    $avgBasketValue = round((float)$stmt->fetchColumn(), 2);

    // Retention: % of subs older than 30 days still active
    $stmt = $db->query("
        SELECT
            COUNT(*) FILTER (WHERE status = 'active' AND created_at < NOW() - INTERVAL '30 days') AS retained,
            COUNT(*) FILTER (WHERE created_at < NOW() - INTERVAL '30 days') AS total_older_30d
        FROM om_box_subscriptions
    ");
    $retention = $stmt->fetch(PDO::FETCH_ASSOC);
    $retentionRate = (int)$retention['total_older_30d'] > 0
        ? round(((int)$retention['retained'] / (int)$retention['total_older_30d']) * 100, 1)
        : 0;

    response(true, [
        'kpis' => [
            'active_subscribers' => (int)$subCounts['active'],
            'paused_subscribers' => (int)$subCounts['paused'],
            'total_subscribers' => (int)$subCounts['total_subscriptions'],
            'monthly_revenue' => $monthlyRevenue,
            'revenue_growth' => $revenueGrowth,
            'mrr' => $mrr,
            'avg_basket_value' => $avgBasketValue,
            'churn_rate' => $churnRate,
            'retention_rate' => $retentionRate,
            'new_subs_this_month' => $newSubsThisMonth,
            'subs_growth' => $subsGrowth,
        ],
        'today' => [
            'total_deliveries' => (int)$todayDeliveries['total'],
            'delivered' => (int)$todayDeliveries['delivered'],
            'pending' => (int)$todayDeliveries['pending'],
            'failed' => (int)$todayDeliveries['failed'],
        ],
        'week_orders' => $weekOrders,
        'charts' => [
            'popular_types' => $popularTypes,
            'delivery_days' => $deliveryDays,
            'city_breakdown' => $cityBreakdown,
            'weekly_trend' => $weeklyTrend,
            'frequency_distribution' => $freqDist,
            'upcoming_deliveries' => $upcoming,
        ],
    ]);
} catch (Exception $e) {
    error_log('[cestas/dashboard] ' . $e->getMessage());
    response(false, null, 'Erro interno', 500);
}
