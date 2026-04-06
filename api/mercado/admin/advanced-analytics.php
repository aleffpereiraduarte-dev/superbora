<?php
/**
 * /api/mercado/admin/advanced-analytics.php
 *
 * Comprehensive KPI dashboard with period comparison.
 *
 * GET ?period=today|week|month|quarter
 *
 * Returns:
 *   revenue     — total, vs_previous (%), by_day breakdown
 *   orders      — total, completed, cancelled, cancel_rate, by_hour distribution
 *   customers   — active, new, returning, churn_rate
 *   stores      — active, avg_prep_time, top_stores (by GMV)
 *   delivery    — avg_time, on_time_rate, by_method (retirada/proprio/boraum)
 *   payments    — pix, card, cash, avg_ticket
 *   support     — tickets_open, avg_response_time, satisfaction
 *   conversion  — views_to_cart, cart_to_order, overall funnel
 *
 * Heavy queries are cached in Redis (2 min for today, 10 min for longer periods).
 */
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../config/cache.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    response(false, null, "Metodo nao permitido", 405);
}

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();

    // ── Period resolution ──
    $period = $_GET['period'] ?? 'today';
    $validPeriods = ['today', 'week', 'month', 'quarter'];
    if (!in_array($period, $validPeriods, true)) {
        response(false, null, "Periodo invalido. Use: today, week, month, quarter", 400);
    }

    // Map period to days for SQL intervals
    $periodDays = [
        'today'   => 1,
        'week'    => 7,
        'month'   => 30,
        'quarter' => 90,
    ];
    $days = $periodDays[$period];
    $intervalParam = $days . ' days';
    $prevIntervalParam = ($days * 2) . ' days';

    // Cache TTL: shorter for today (2 min), longer for historical (10 min)
    $cacheTTL = $period === 'today' ? 120 : 600;
    $cacheKey = "admin_advanced_analytics_{$period}";

    $data = om_cache()->remember($cacheKey, $cacheTTL, function () use ($db, $period, $days, $intervalParam, $prevIntervalParam) {

        // ════════════════════════════════════════════
        // Helper: period start condition
        // ════════════════════════════════════════════
        // Use safe integer cast (already validated above, but defense-in-depth)
        $safeDays = (int)$days;
        $periodStart = $period === 'today'
            ? "created_at >= CURRENT_DATE"
            : "created_at >= CURRENT_DATE - INTERVAL '" . $safeDays . " days'";

        $prevPeriodRange = $period === 'today'
            ? "created_at >= CURRENT_DATE - INTERVAL '1 day' AND created_at < CURRENT_DATE"
            : "created_at >= CURRENT_DATE - INTERVAL '" . ($safeDays * 2) . " days' AND created_at < CURRENT_DATE - INTERVAL '" . $safeDays . " days'";

        // ════════════════════════════════════════════
        // 1. REVENUE
        // ════════════════════════════════════════════
        $stmt = dbQuery($db, "
            SELECT
                COALESCE(SUM(total), 0) AS total_revenue,
                COUNT(*) AS order_count
            FROM om_market_orders
            WHERE {$periodStart} AND status NOT IN ('cancelado', 'reembolsado')
        ");
        $revCurrent = $stmt->fetch();
        $totalRevenue = round((float)$revCurrent['total_revenue'], 2);

        // Previous period revenue for comparison
        $stmt = dbQuery($db, "
            SELECT COALESCE(SUM(total), 0) AS total_revenue
            FROM om_market_orders
            WHERE {$prevPeriodRange} AND status NOT IN ('cancelado', 'reembolsado')
        ");
        $prevRevenue = round((float)$stmt->fetch()['total_revenue'], 2);
        $revenueChange = $prevRevenue > 0
            ? round((($totalRevenue - $prevRevenue) / $prevRevenue) * 100, 1)
            : ($totalRevenue > 0 ? 100.0 : 0.0);
        $revenueVsPrevious = ($revenueChange >= 0 ? '+' : '') . $revenueChange . '%';

        // Revenue by day
        $revenueByDay = [];
        if ($period === 'today') {
            // For today, break down by hour
            $stmt = dbQuery($db, "
                SELECT EXTRACT(HOUR FROM created_at)::int AS hour,
                       COALESCE(SUM(total), 0) AS revenue,
                       COUNT(*) AS orders
                FROM om_market_orders
                WHERE created_at >= CURRENT_DATE AND status NOT IN ('cancelado', 'reembolsado')
                GROUP BY EXTRACT(HOUR FROM created_at)
                ORDER BY hour
            ");
            $revenueByDay = $stmt->fetchAll();
            foreach ($revenueByDay as &$r) {
                $r['hour'] = (int)$r['hour'];
                $r['revenue'] = round((float)$r['revenue'], 2);
                $r['orders'] = (int)$r['orders'];
            }
            unset($r);
        } else {
            $stmt = dbQuery($db, "
                SELECT DATE(created_at) AS date,
                       COALESCE(SUM(total), 0) AS revenue,
                       COUNT(*) AS orders
                FROM om_market_orders
                WHERE {$periodStart} AND status NOT IN ('cancelado', 'reembolsado')
                GROUP BY DATE(created_at)
                ORDER BY date ASC
            ");
            $revenueByDay = $stmt->fetchAll();
            foreach ($revenueByDay as &$r) {
                $r['revenue'] = round((float)$r['revenue'], 2);
                $r['orders'] = (int)$r['orders'];
            }
            unset($r);
        }

        // ════════════════════════════════════════════
        // 2. ORDERS
        // ════════════════════════════════════════════
        $stmt = dbQuery($db, "
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status IN ('entregue', 'finalizado') THEN 1 ELSE 0 END) AS completed,
                SUM(CASE WHEN status = 'cancelado' THEN 1 ELSE 0 END) AS cancelled,
                SUM(CASE WHEN status = 'reembolsado' THEN 1 ELSE 0 END) AS refunded,
                SUM(CASE WHEN status IN ('pendente', 'aceito', 'preparando', 'pronto', 'em_entrega') THEN 1 ELSE 0 END) AS in_progress
            FROM om_market_orders
            WHERE {$periodStart}
        ");
        $ordersRow = $stmt->fetch();
        $ordersTotal = (int)$ordersRow['total'];
        $ordersCompleted = (int)$ordersRow['completed'];
        $ordersCancelled = (int)$ordersRow['cancelled'];
        $cancelRate = $ordersTotal > 0 ? round(($ordersCancelled / $ordersTotal) * 100, 1) : 0;

        // Orders by hour (distribution)
        $stmt = dbQuery($db, "
            SELECT EXTRACT(HOUR FROM created_at)::int AS hour, COUNT(*) AS count
            FROM om_market_orders
            WHERE {$periodStart}
            GROUP BY EXTRACT(HOUR FROM created_at)
            ORDER BY hour
        ");
        $ordersByHour = $stmt->fetchAll();
        foreach ($ordersByHour as &$h) {
            $h['hour'] = (int)$h['hour'];
            $h['count'] = (int)$h['count'];
        }
        unset($h);

        // ════════════════════════════════════════════
        // 3. CUSTOMERS
        // ════════════════════════════════════════════
        // Active customers (placed an order in period)
        $stmt = dbQuery($db, "
            SELECT COUNT(DISTINCT customer_id) AS active
            FROM om_market_orders
            WHERE {$periodStart}
        ");
        $activeCustomers = (int)$stmt->fetch()['active'];

        // New customers (registered in period)
        $newCustomersCondition = $period === 'today'
            ? "created_at >= CURRENT_DATE"
            : "created_at >= CURRENT_DATE - INTERVAL '{$days} days'";
        $stmt = dbQuery($db, "
            SELECT COUNT(*) AS new_count
            FROM om_customers
            WHERE {$newCustomersCondition}
        ");
        $newCustomers = (int)$stmt->fetch()['new_count'];

        // Returning customers (ordered before the period AND in the period)
        $stmt = dbQuery($db, "
            SELECT COUNT(DISTINCT o1.customer_id) AS returning_count
            FROM om_market_orders o1
            WHERE {$periodStart}
              AND EXISTS (
                  SELECT 1 FROM om_market_orders o2
                  WHERE o2.customer_id = o1.customer_id
                    AND o2.created_at < " . ($period === 'today' ? "CURRENT_DATE" : "CURRENT_DATE - INTERVAL '{$days} days'") . "
              )
        ");
        $returningCustomers = (int)$stmt->fetch()['returning_count'];

        // Churn rate: customers active in previous period but NOT in current period
        $stmt = dbQuery($db, "
            WITH prev_active AS (
                SELECT DISTINCT customer_id
                FROM om_market_orders
                WHERE {$prevPeriodRange}
            ),
            curr_active AS (
                SELECT DISTINCT customer_id
                FROM om_market_orders
                WHERE {$periodStart}
            )
            SELECT
                COUNT(pa.customer_id) AS prev_count,
                COUNT(pa.customer_id) FILTER (WHERE ca.customer_id IS NULL) AS churned
            FROM prev_active pa
            LEFT JOIN curr_active ca ON ca.customer_id = pa.customer_id
        ");
        $churnRow = $stmt->fetch();
        $churnRate = (int)$churnRow['prev_count'] > 0
            ? round(((int)$churnRow['churned'] / (int)$churnRow['prev_count']) * 100, 1)
            : 0;

        // ════════════════════════════════════════════
        // 4. STORES
        // ════════════════════════════════════════════
        // Active stores (received at least 1 order in period)
        $stmt = dbQuery($db, "
            SELECT COUNT(DISTINCT partner_id) AS active
            FROM om_market_orders
            WHERE {$periodStart}
        ");
        $activeStores = (int)$stmt->fetch()['active'];

        // Avg prep time (from accepted to ready, in minutes)
        $stmt = dbQuery($db, "
            SELECT AVG(EXTRACT(EPOCH FROM (COALESCE(delivered_at, updated_at) - accepted_at)) / 60) AS avg_minutes
            FROM om_market_orders
            WHERE {$periodStart}
              AND accepted_at IS NOT NULL
              AND status IN ('entregue', 'finalizado')
        ");
        $avgPrepMinutes = round((float)($stmt->fetch()['avg_minutes'] ?? 0), 0);
        $avgPrepTime = $avgPrepMinutes . 'min';

        // Top 10 stores by GMV
        $stmt = dbQuery($db, "
            SELECT p.partner_id, p.name, p.logo,
                   COUNT(o.order_id) AS total_orders,
                   COALESCE(SUM(o.total), 0) AS gmv,
                   ROUND(COALESCE(AVG(o.total), 0)::numeric, 2) AS avg_ticket
            FROM om_market_orders o
            JOIN om_market_partners p ON p.partner_id = o.partner_id
            WHERE o.{$periodStart} AND o.status NOT IN ('cancelado', 'reembolsado')
            GROUP BY p.partner_id, p.name, p.logo
            ORDER BY gmv DESC
            LIMIT 10
        ");
        $topStores = $stmt->fetchAll();
        foreach ($topStores as &$s) {
            $s['total_orders'] = (int)$s['total_orders'];
            $s['gmv'] = round((float)$s['gmv'], 2);
            $s['avg_ticket'] = round((float)$s['avg_ticket'], 2);
        }
        unset($s);

        // ════════════════════════════════════════════
        // 5. DELIVERY
        // ════════════════════════════════════════════
        // Avg delivery time (created_at to delivered_at for delivered orders)
        $stmt = dbQuery($db, "
            SELECT AVG(EXTRACT(EPOCH FROM (delivered_at - created_at)) / 60) AS avg_minutes
            FROM om_market_orders
            WHERE {$periodStart}
              AND status IN ('entregue', 'finalizado')
              AND delivered_at IS NOT NULL
        ");
        $avgDeliveryMinutes = round((float)($stmt->fetch()['avg_minutes'] ?? 0), 0);
        $avgDeliveryTime = $avgDeliveryMinutes . 'min';

        // On-time rate (delivered within 60 min of creation as benchmark)
        $stmt = dbQuery($db, "
            SELECT
                COUNT(*) AS total_delivered,
                SUM(CASE WHEN EXTRACT(EPOCH FROM (delivered_at - created_at)) / 60 <= 60 THEN 1 ELSE 0 END) AS on_time
            FROM om_market_orders
            WHERE {$periodStart}
              AND status IN ('entregue', 'finalizado')
              AND delivered_at IS NOT NULL
        ");
        $onTimeRow = $stmt->fetch();
        $onTimeRate = (int)$onTimeRow['total_delivered'] > 0
            ? round(((int)$onTimeRow['on_time'] / (int)$onTimeRow['total_delivered']) * 100, 1)
            : 0;

        // By delivery method (delivery_type: retirada, proprio, boraum)
        $stmt = dbQuery($db, "
            SELECT
                COALESCE(delivery_type, 'desconhecido') AS method,
                COUNT(*) AS count,
                COALESCE(SUM(total), 0) AS revenue
            FROM om_market_orders
            WHERE {$periodStart} AND status NOT IN ('cancelado', 'reembolsado')
            GROUP BY COALESCE(delivery_type, 'desconhecido')
            ORDER BY count DESC
        ");
        $deliveryByMethod = [];
        while ($row = $stmt->fetch()) {
            $deliveryByMethod[$row['method']] = [
                'count' => (int)$row['count'],
                'revenue' => round((float)$row['revenue'], 2),
            ];
        }

        // ════════════════════════════════════════════
        // 6. PAYMENTS
        // ════════════════════════════════════════════
        $stmt = dbQuery($db, "
            SELECT
                COALESCE(payment_method, forma_pagamento, 'desconhecido') AS method,
                COUNT(*) AS count,
                COALESCE(SUM(total), 0) AS total
            FROM om_market_orders
            WHERE {$periodStart} AND status NOT IN ('cancelado', 'reembolsado')
            GROUP BY COALESCE(payment_method, forma_pagamento, 'desconhecido')
            ORDER BY count DESC
        ");
        $paymentRows = $stmt->fetchAll();

        $pixCount = 0; $cardCount = 0; $cashCount = 0; $otherCount = 0;
        $paymentBreakdown = [];
        foreach ($paymentRows as $pr) {
            $m = strtolower($pr['method']);
            $cnt = (int)$pr['count'];
            if (strpos($m, 'pix') !== false) {
                $pixCount += $cnt;
            } elseif (strpos($m, 'cart') !== false || strpos($m, 'card') !== false || strpos($m, 'credito') !== false || strpos($m, 'debito') !== false || strpos($m, 'stripe') !== false) {
                $cardCount += $cnt;
            } elseif (strpos($m, 'dinheiro') !== false || strpos($m, 'cash') !== false) {
                $cashCount += $cnt;
            } else {
                $otherCount += $cnt;
            }
            $paymentBreakdown[] = [
                'method' => $pr['method'],
                'count' => $cnt,
                'total' => round((float)$pr['total'], 2),
            ];
        }

        // Avg ticket
        $validOrders = (int)$revCurrent['order_count'];
        $avgTicket = $validOrders > 0 ? round($totalRevenue / $validOrders, 2) : 0;

        // ════════════════════════════════════════════
        // 7. SUPPORT
        // ════════════════════════════════════════════
        $supportCondition = $period === 'today'
            ? "created_at >= CURRENT_DATE"
            : "created_at >= CURRENT_DATE - INTERVAL '{$days} days'";

        // Open tickets right now
        $stmt = dbQuery($db, "
            SELECT COUNT(*) AS open_count
            FROM om_support_tickets
            WHERE status IN ('open', 'in_progress')
        ");
        $ticketsOpen = (int)$stmt->fetch()['open_count'];

        // Tickets created in period
        $ticketsCreated = 0;
        $avgResponseMinutes = 0;
        try {
            $stmt = dbQuery($db, "
                SELECT
                    COUNT(*) AS created,
                    AVG(EXTRACT(EPOCH FROM (COALESCE(first_response_at, resolved_at, NOW()) - created_at)) / 3600) AS avg_hours
                FROM om_support_tickets
                WHERE {$supportCondition}
            ");
            $supportRow = $stmt->fetch();
            $ticketsCreated = (int)$supportRow['created'];
            $avgResponseHours = round((float)($supportRow['avg_hours'] ?? 0), 1);
        } catch (Exception $e) {
            // first_response_at column may not exist
            $avgResponseHours = 0;
        }
        $avgResponseTime = $avgResponseHours < 1
            ? round($avgResponseHours * 60, 0) . 'min'
            : round($avgResponseHours, 1) . 'h';

        // Customer satisfaction from ratings
        $stmt = dbQuery($db, "
            SELECT ROUND(AVG(rating)::numeric, 1) AS avg_satisfaction
            FROM om_market_ratings
            WHERE rating > 0 AND rater_type = 'customer'
              AND created_at >= CURRENT_DATE - INTERVAL '90 days'
        ");
        $satisfaction = (string)($stmt->fetch()['avg_satisfaction'] ?? '0');

        // ════════════════════════════════════════════
        // 8. CONVERSION FUNNEL
        // ════════════════════════════════════════════
        $eventCondition = $period === 'today'
            ? "created_at >= CURRENT_DATE"
            : "created_at >= CURRENT_DATE - INTERVAL '{$days} days'";

        $viewsToCart = '0%';
        $cartToOrder = '0%';
        $overallConversion = '0%';

        try {
            $stmt = dbQuery($db, "
                SELECT
                    COUNT(*) FILTER (WHERE event_type = 'view_product') AS views,
                    COUNT(*) FILTER (WHERE event_type = 'add_to_cart') AS adds,
                    COUNT(*) FILTER (WHERE event_type = 'purchase') AS purchases
                FROM om_user_events
                WHERE {$eventCondition}
            ");
            $funnelRow = $stmt->fetch();
            $views = (int)($funnelRow['views'] ?? 0);
            $adds = (int)($funnelRow['adds'] ?? 0);
            $purchases = (int)($funnelRow['purchases'] ?? 0);

            if ($views > 0) {
                $viewsToCart = round(($adds / $views) * 100, 1) . '%';
                $overallConversion = round(($purchases / $views) * 100, 1) . '%';
            }
            if ($adds > 0) {
                $cartToOrder = round(($purchases / $adds) * 100, 1) . '%';
            }
        } catch (Exception $e) {
            // om_user_events table may not exist yet
            error_log("[advanced-analytics] Conversion funnel error: " . $e->getMessage());
        }

        // ════════════════════════════════════════════
        // BUILD RESPONSE
        // ════════════════════════════════════════════
        return [
            'period' => $period,
            'period_label' => match($period) {
                'today' => 'Hoje',
                'week' => 'Ultimos 7 dias',
                'month' => 'Ultimos 30 dias',
                'quarter' => 'Ultimos 90 dias',
            },
            'revenue' => [
                'total' => $totalRevenue,
                'vs_previous' => $revenueVsPrevious,
                'by_day' => $revenueByDay,
            ],
            'orders' => [
                'total' => $ordersTotal,
                'completed' => $ordersCompleted,
                'cancelled' => $ordersCancelled,
                'refunded' => (int)$ordersRow['refunded'],
                'in_progress' => (int)$ordersRow['in_progress'],
                'cancel_rate' => $cancelRate . '%',
                'by_hour' => $ordersByHour,
            ],
            'customers' => [
                'active' => $activeCustomers,
                'new' => $newCustomers,
                'returning' => $returningCustomers,
                'churn_rate' => $churnRate . '%',
            ],
            'stores' => [
                'active' => $activeStores,
                'avg_prep_time' => $avgPrepTime,
                'top_stores' => $topStores,
            ],
            'delivery' => [
                'avg_time' => $avgDeliveryTime,
                'on_time_rate' => $onTimeRate . '%',
                'by_method' => $deliveryByMethod,
            ],
            'payments' => [
                'pix' => $pixCount,
                'card' => $cardCount,
                'cash' => $cashCount,
                'other' => $otherCount,
                'avg_ticket' => $avgTicket,
                'breakdown' => $paymentBreakdown,
            ],
            'support' => [
                'tickets_open' => $ticketsOpen,
                'tickets_created' => $ticketsCreated,
                'avg_response_time' => $avgResponseTime,
                'satisfaction' => $satisfaction,
            ],
            'conversion' => [
                'views_to_cart' => $viewsToCart,
                'cart_to_order' => $cartToOrder,
                'overall' => $overallConversion,
            ],
        ];
    });

    response(true, $data, "Analytics avancado carregado");

} catch (Exception $e) {
    error_log("[admin/advanced-analytics] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
