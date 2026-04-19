<?php
/**
 * GET /api/mercado/metrics-business.php
 *
 * Prometheus-format business KPIs (orders, revenue, AOV, conversion).
 * Scraped every 60s. Queries PostgreSQL directly — keep the query set small.
 */

require_once __DIR__ . '/config/database.php';

$remote = $_SERVER['REMOTE_ADDR'] ?? '';
$isInternal = (
    str_starts_with($remote, '10.') || str_starts_with($remote, '172.16.') ||
    str_starts_with($remote, '172.17.') || str_starts_with($remote, '172.18.') ||
    str_starts_with($remote, '172.19.') || str_starts_with($remote, '172.20.') ||
    str_starts_with($remote, '192.168.') || $remote === '127.0.0.1' || $remote === '::1'
);
$token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$expected = $_ENV['METRICS_TOKEN'] ?? getenv('METRICS_TOKEN') ?: '';
$tokenOk = $expected && hash_equals('Bearer ' . $expected, $token);
if (!$isInternal && !$tokenOk) {
    http_response_code(403);
    exit("forbidden\n");
}

header('Content-Type: text/plain; charset=utf-8');

try {
    $db = getDB();

    // Lifetime totals
    $stmt = $db->query("
        SELECT
            COUNT(*) AS total_orders,
            COUNT(*) FILTER (WHERE status = 'entregue') AS delivered,
            COUNT(*) FILTER (WHERE status IN ('cancelado','recusado')) AS cancelled,
            COALESCE(SUM(total) FILTER (WHERE status = 'entregue'), 0) AS revenue_lifetime
        FROM om_market_orders
    ");
    $lt = $stmt->fetch();

    // Last 24h
    $stmt = $db->query("
        SELECT
            COUNT(*) AS orders_24h,
            COUNT(*) FILTER (WHERE status = 'entregue') AS delivered_24h,
            COALESCE(SUM(total) FILTER (WHERE status = 'entregue'), 0) AS revenue_24h,
            COALESCE(AVG(total) FILTER (WHERE status = 'entregue'), 0) AS aov_24h
        FROM om_market_orders
        WHERE date_added > NOW() - INTERVAL '24 hours'
    ");
    $d24 = $stmt->fetch();

    // Last 1h (for rate panels)
    $stmt = $db->query("
        SELECT COUNT(*) AS orders_1h
        FROM om_market_orders
        WHERE date_added > NOW() - INTERVAL '1 hour'
    ");
    $h1 = $stmt->fetch();

    // Status distribution
    $stmt = $db->query("
        SELECT status, COUNT(*) AS c
        FROM om_market_orders
        WHERE date_added > NOW() - INTERVAL '24 hours'
        GROUP BY status
    ");
    $statusBreakdown = $stmt->fetchAll();

    // Payment status breakdown (PIX waiting, paid, failed)
    $stmt = $db->query("
        SELECT COALESCE(payment_status, 'unknown') AS payment_status, COUNT(*) AS c
        FROM om_market_orders
        WHERE date_added > NOW() - INTERVAL '24 hours'
        GROUP BY payment_status
    ");
    $paymentBreakdown = $stmt->fetchAll();

    // Active carts (items in cart right now)
    $stmt = $db->query("SELECT COUNT(DISTINCT customer_id) AS active_carts FROM om_market_cart");
    $carts = (int)$stmt->fetchColumn();

    // Top partners by orders 24h
    $stmt = $db->query("
        SELECT o.partner_id, COUNT(*) AS orders
        FROM om_market_orders o
        WHERE o.date_added > NOW() - INTERVAL '24 hours'
          AND o.status = 'entregue'
        GROUP BY o.partner_id
        ORDER BY orders DESC
        LIMIT 10
    ");
    $topPartners = $stmt->fetchAll();

    // ─── Emit Prometheus metrics ─────────────────────────────────
    echo "# HELP superbora_orders_total Total orders lifetime\n";
    echo "# TYPE superbora_orders_total counter\n";
    echo "superbora_orders_total " . (int)$lt['total_orders'] . "\n";

    echo "# HELP superbora_orders_delivered_total Orders delivered lifetime\n";
    echo "# TYPE superbora_orders_delivered_total counter\n";
    echo "superbora_orders_delivered_total " . (int)$lt['delivered'] . "\n";

    echo "# HELP superbora_orders_cancelled_total Orders cancelled lifetime\n";
    echo "# TYPE superbora_orders_cancelled_total counter\n";
    echo "superbora_orders_cancelled_total " . (int)$lt['cancelled'] . "\n";

    echo "# HELP superbora_revenue_lifetime_brl Lifetime delivered revenue (BRL)\n";
    echo "# TYPE superbora_revenue_lifetime_brl counter\n";
    echo "superbora_revenue_lifetime_brl " . (float)$lt['revenue_lifetime'] . "\n";

    echo "# HELP superbora_orders_24h Orders in last 24 hours\n";
    echo "# TYPE superbora_orders_24h gauge\n";
    echo "superbora_orders_24h " . (int)$d24['orders_24h'] . "\n";

    echo "# HELP superbora_revenue_24h_brl Revenue in last 24 hours (BRL)\n";
    echo "# TYPE superbora_revenue_24h_brl gauge\n";
    echo "superbora_revenue_24h_brl " . (float)$d24['revenue_24h'] . "\n";

    echo "# HELP superbora_aov_24h_brl Average order value 24h (BRL)\n";
    echo "# TYPE superbora_aov_24h_brl gauge\n";
    echo "superbora_aov_24h_brl " . round((float)$d24['aov_24h'], 2) . "\n";

    echo "# HELP superbora_orders_1h Orders in last 1 hour\n";
    echo "# TYPE superbora_orders_1h gauge\n";
    echo "superbora_orders_1h " . (int)$h1['orders_1h'] . "\n";

    echo "# HELP superbora_active_carts Customers with items in cart\n";
    echo "# TYPE superbora_active_carts gauge\n";
    echo "superbora_active_carts {$carts}\n";

    echo "# HELP superbora_order_status Orders by status (24h)\n";
    echo "# TYPE superbora_order_status gauge\n";
    foreach ($statusBreakdown as $row) {
        $s = addcslashes((string)$row['status'], '"\\');
        echo "superbora_order_status{status=\"{$s}\"} " . (int)$row['c'] . "\n";
    }

    echo "# HELP superbora_payment_status Orders by payment status (24h)\n";
    echo "# TYPE superbora_payment_status gauge\n";
    foreach ($paymentBreakdown as $row) {
        $s = addcslashes((string)$row['payment_status'], '"\\');
        echo "superbora_payment_status{status=\"{$s}\"} " . (int)$row['c'] . "\n";
    }

    echo "# HELP superbora_top_partner_orders_24h Top partners by delivered orders (24h)\n";
    echo "# TYPE superbora_top_partner_orders_24h gauge\n";
    foreach ($topPartners as $row) {
        echo "superbora_top_partner_orders_24h{partner_id=\"" . (int)$row['partner_id'] . "\"} " . (int)$row['orders'] . "\n";
    }

} catch (Exception $e) {
    echo "# error: " . addcslashes($e->getMessage(), "\n") . "\n";
}
