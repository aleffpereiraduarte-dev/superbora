<?php
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();

    $period = $_GET['period'] ?? '30d';
    // Whitelist allowed period values to prevent SQL injection via INTERVAL
    $periodMap = ['7d' => 7, '14d' => 14, '30d' => 30, '60d' => 60, '90d' => 90];
    $days = $periodMap[$period] ?? 30;
    $interval_param = $days . ' days';

    // Delivery time avg
    $stmt = $db->prepare("
        SELECT AVG(EXTRACT(EPOCH FROM (updated_at - created_at)) / 60) as avg_minutes
        FROM om_market_orders
        WHERE status = 'entregue' AND created_at >= CURRENT_DATE - CAST(? AS INTERVAL)
    ");
    $stmt->execute([$interval_param]);
    $delivery_time_avg = round((float)($stmt->fetch()['avg_minutes'] ?? 0), 1);

    // Order accuracy
    $stmt = $db->prepare("
        SELECT COUNT(*) as total,
               SUM(CASE WHEN status = 'entregue' THEN 1 ELSE 0 END) as delivered
        FROM om_market_orders
        WHERE created_at >= CURRENT_DATE - CAST(? AS INTERVAL) AND status != 'cancelled'
    ");
    $stmt->execute([$interval_param]);
    $acc = $stmt->fetch();
    $order_accuracy = $acc['total'] > 0 ? round(($acc['delivered'] / $acc['total']) * 100, 1) : 0;

    // Customer satisfaction (avg shopper rating)
    $stmt = $db->query("SELECT AVG(rating) as avg_rating FROM om_market_shoppers WHERE status = '1' AND rating > 0");
    $customer_satisfaction = round((float)($stmt->fetch()['avg_rating'] ?? 0), 2);

    // Shopper utilization
    $stmt = $db->query("SELECT COUNT(*) as total FROM om_market_shoppers WHERE is_online = 1 AND status = '1'");
    $online = (int)$stmt->fetch()['total'];
    $stmt = $db->query("SELECT COUNT(DISTINCT shopper_id) as busy FROM om_market_orders WHERE status IN ('collecting','in_transit')");
    $busy = (int)$stmt->fetch()['busy'];
    $shopper_utilization = $online > 0 ? round(($busy / $online) * 100, 1) : 0;

    // Revenue per order
    $stmt = $db->prepare("
        SELECT AVG(total) as avg_total FROM om_market_orders
        WHERE created_at >= CURRENT_DATE - CAST(? AS INTERVAL) AND status NOT IN ('cancelled','refunded','cancelado')
    ");
    $stmt->execute([$interval_param]);
    $revenue_per_order = round((float)($stmt->fetch()['avg_total'] ?? 0), 2);

    // Cancellation rate
    $stmt = $db->prepare("
        SELECT COUNT(*) as total,
               SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
        FROM om_market_orders WHERE created_at >= CURRENT_DATE - CAST(? AS INTERVAL)
    ");
    $stmt->execute([$interval_param]);
    $canc = $stmt->fetch();
    $cancellation_rate = $canc['total'] > 0 ? round(($canc['cancelled'] / $canc['total']) * 100, 1) : 0;

    // GMV (Gross Merchandise Value)
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(total), 0) as gmv, COUNT(*) as total_orders
        FROM om_market_orders
        WHERE created_at >= CURRENT_DATE - CAST(? AS INTERVAL) AND status NOT IN ('cancelled','refunded','cancelado')
    ");
    $stmt->execute([$interval_param]);
    $gmv_row = $stmt->fetch();
    $gmv = round((float)$gmv_row['gmv'], 2);
    $total_orders = (int)$gmv_row['total_orders'];

    // Today's GMV + orders
    $stmt = $db->query("
        SELECT COALESCE(SUM(total), 0) as gmv_today, COUNT(*) as orders_today
        FROM om_market_orders
        WHERE created_at >= CURRENT_DATE AND status NOT IN ('cancelled','refunded','cancelado')
    ");
    $today = $stmt->fetch();

    // Active customers (ordered in period)
    $stmt = $db->prepare("
        SELECT COUNT(DISTINCT customer_id) as active_customers
        FROM om_market_orders
        WHERE created_at >= CURRENT_DATE - CAST(? AS INTERVAL)
    ");
    $stmt->execute([$interval_param]);
    $active_customers = (int)$stmt->fetch()['active_customers'];

    // Top 10 stores by GMV
    $stmt = $db->prepare("
        SELECT p.partner_id, p.name as nome,
               COUNT(o.order_id) as total_orders,
               COALESCE(SUM(o.total), 0) as gmv,
               COALESCE(AVG(o.total), 0) as avg_ticket
        FROM om_market_orders o
        JOIN om_market_partners p ON p.partner_id = o.partner_id
        WHERE o.created_at >= CURRENT_DATE - CAST(? AS INTERVAL) AND o.status NOT IN ('cancelled','refunded','cancelado')
        GROUP BY p.partner_id, p.name
        ORDER BY gmv DESC
        LIMIT 10
    ");
    $stmt->execute([$interval_param]);
    $top_stores = $stmt->fetchAll();

    // Top 10 products by volume
    $stmt = $db->prepare("
        SELECT p.product_id, p.name, p.category_id,
               COALESCE(SUM(oi.quantity), 0) as total_sold,
               COALESCE(SUM(oi.quantity * oi.price), 0) as revenue
        FROM om_market_order_items oi
        JOIN om_market_products p ON p.product_id = oi.product_id
        JOIN om_market_orders o ON o.order_id = oi.order_id
        WHERE o.created_at >= CURRENT_DATE - CAST(? AS INTERVAL) AND o.status NOT IN ('cancelled','refunded','cancelado')
        GROUP BY p.product_id, p.name, p.category_id
        ORDER BY total_sold DESC
        LIMIT 10
    ");
    $stmt->execute([$interval_param]);
    $top_products = $stmt->fetchAll();

    // Daily trend (last N days)
    $trend_days = min($days, 30);
    $trend_interval = $trend_days . ' days';
    $stmt = $db->prepare("
        SELECT DATE(created_at) as date,
               COUNT(*) as orders,
               COALESCE(SUM(total), 0) as gmv,
               COUNT(DISTINCT customer_id) as customers
        FROM om_market_orders
        WHERE created_at >= CURRENT_DATE - CAST(? AS INTERVAL) AND status NOT IN ('cancelled','refunded','cancelado')
        GROUP BY DATE(created_at)
        ORDER BY date ASC
    ");
    $stmt->execute([$trend_interval]);
    $daily_trend = $stmt->fetchAll();

    // Previous period comparison
    $prev_start = $days * 2 . ' days';
    $prev_end = $days . ' days';
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(total), 0) as gmv, COUNT(*) as orders
        FROM om_market_orders
        WHERE created_at >= CURRENT_DATE - CAST(? AS INTERVAL)
          AND created_at < CURRENT_DATE - CAST(? AS INTERVAL)
          AND status NOT IN ('cancelled','refunded','cancelado')
    ");
    $stmt->execute([$prev_start, $prev_end]);
    $prev = $stmt->fetch();
    $gmv_change = $prev['gmv'] > 0 ? round((($gmv - $prev['gmv']) / $prev['gmv']) * 100, 1) : 0;
    $orders_change = $prev['orders'] > 0 ? round((($total_orders - $prev['orders']) / $prev['orders']) * 100, 1) : 0;

    // Payment method distribution
    $stmt = $db->prepare("
        SELECT COALESCE(payment_method, forma_pagamento, 'desconhecido') as payment_method,
               COUNT(*) as count, COALESCE(SUM(total), 0) as total
        FROM om_market_orders
        WHERE created_at >= CURRENT_DATE - CAST(? AS INTERVAL) AND status NOT IN ('cancelled','refunded','cancelado')
        GROUP BY COALESCE(payment_method, forma_pagamento, 'desconhecido')
        ORDER BY count DESC
    ");
    $stmt->execute([$interval_param]);
    $payment_methods = $stmt->fetchAll();

    response(true, [
        'period' => $period,
        'gmv' => $gmv,
        'gmv_today' => round((float)$today['gmv_today'], 2),
        'orders_today' => (int)$today['orders_today'],
        'total_orders' => $total_orders,
        'active_customers' => $active_customers,
        'delivery_time_avg' => $delivery_time_avg,
        'order_accuracy' => $order_accuracy,
        'customer_satisfaction' => $customer_satisfaction,
        'shopper_utilization' => $shopper_utilization,
        'revenue_per_order' => $revenue_per_order,
        'cancellation_rate' => $cancellation_rate,
        'comparison' => [
            'gmv_change' => $gmv_change,
            'orders_change' => $orders_change
        ],
        'top_stores' => $top_stores,
        'top_products' => $top_products,
        'daily_trend' => $daily_trend,
        'payment_methods' => $payment_methods
    ], "KPIs carregados");
} catch (Exception $e) {
    error_log("[admin/kpis] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
