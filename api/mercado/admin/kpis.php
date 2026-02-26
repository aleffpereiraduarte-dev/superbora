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
        WHERE created_at >= CURRENT_DATE - CAST(? AS INTERVAL) AND status NOT IN ('cancelled','refunded')
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

    response(true, [
        'period' => $period,
        'delivery_time_avg' => $delivery_time_avg,
        'order_accuracy' => $order_accuracy,
        'customer_satisfaction' => $customer_satisfaction,
        'shopper_utilization' => $shopper_utilization,
        'revenue_per_order' => $revenue_per_order,
        'cancellation_rate' => $cancellation_rate
    ], "KPIs carregados");
} catch (Exception $e) {
    error_log("[admin/kpis] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
