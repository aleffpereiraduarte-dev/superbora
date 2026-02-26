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

    // Top performing partners
    $stmt = $db->query("
        SELECT p.partner_id, p.name,
               COUNT(o.order_id) as orders_count,
               COALESCE(SUM(o.total), 0) as revenue
        FROM om_market_partners p
        INNER JOIN om_market_orders o ON p.partner_id = o.partner_id
        WHERE o.created_at >= CURRENT_DATE - INTERVAL '30 days'
          AND o.status NOT IN ('cancelled','refunded')
        GROUP BY p.partner_id, p.name
        ORDER BY revenue DESC LIMIT 5
    ");
    $top_partners = $stmt->fetchAll();

    // Peak hours
    $stmt = $db->query("
        SELECT EXTRACT(HOUR FROM created_at)::int as hour, COUNT(*) as orders_count
        FROM om_market_orders
        WHERE created_at >= CURRENT_DATE - INTERVAL '30 days'
        GROUP BY EXTRACT(HOUR FROM created_at)::int
        ORDER BY orders_count DESC
    ");
    $peak_hours = $stmt->fetchAll();

    // Growth trend
    $stmt = $db->query("
        SELECT
            SUM(CASE WHEN created_at >= CURRENT_DATE - INTERVAL '7 days' THEN 1 ELSE 0 END) as this_week,
            SUM(CASE WHEN created_at >= CURRENT_DATE - INTERVAL '14 days'
                      AND created_at < CURRENT_DATE - INTERVAL '7 days' THEN 1 ELSE 0 END) as last_week
        FROM om_market_orders
    ");
    $growth = $stmt->fetch();
    $growth_rate = ($growth['last_week'] > 0)
        ? round((($growth['this_week'] - $growth['last_week']) / $growth['last_week']) * 100, 1)
        : 0;

    // Anomalies
    $anomalies = [];
    $stmt = $db->query("SELECT COUNT(*) as c FROM om_market_orders WHERE status = 'cancelled' AND DATE(created_at) = CURRENT_DATE");
    $cancelled_today = (int)$stmt->fetch()['c'];

    $stmt = $db->query("
        SELECT AVG(daily_cancelled) as avg_c
        FROM (SELECT COUNT(*) as daily_cancelled FROM om_market_orders
              WHERE status = 'cancelled' AND created_at >= CURRENT_DATE - INTERVAL '30 days'
              GROUP BY DATE(created_at)) t
    ");
    $avg_cancelled = (float)($stmt->fetch()['avg_c'] ?? 0);

    if ($cancelled_today > $avg_cancelled * 2 && $cancelled_today > 3) {
        $anomalies[] = [
            'type' => 'high_cancellations',
            'severity' => 'warning',
            'message' => "Cancelamentos hoje ({$cancelled_today}) acima do normal (" . round($avg_cancelled) . ")"
        ];
    }

    // Suggestions
    $suggestions = [];
    $stmt = $db->query("
        SELECT COUNT(*) as c FROM om_market_orders
        WHERE status IN ('ready','confirmed') AND shopper_id IS NULL
        AND created_at < NOW() - INTERVAL '30 minutes'
    ");
    $delayed = (int)$stmt->fetch()['c'];
    if ($delayed > 0) {
        $suggestions[] = [
            'type' => 'dispatch',
            'priority' => 'high',
            'message' => "{$delayed} pedidos aguardando shopper ha mais de 30 minutos"
        ];
    }

    response(true, [
        'trends' => [
            'top_partners' => $top_partners,
            'peak_hours' => $peak_hours,
            'growth_rate' => $growth_rate,
            'this_week_orders' => (int)($growth['this_week'] ?? 0),
            'last_week_orders' => (int)($growth['last_week'] ?? 0)
        ],
        'anomalies' => $anomalies,
        'suggestions' => $suggestions
    ], "Inteligencia carregada");
} catch (Exception $e) {
    error_log("[admin/inteligencia] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
