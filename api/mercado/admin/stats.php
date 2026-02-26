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

    $stmt = $db->query("SELECT COUNT(*) as total FROM om_market_orders");
    $total_orders = (int)$stmt->fetch()['total'];

    $stmt = $db->query("
        SELECT COALESCE(SUM(total), 0) as total
        FROM om_market_orders
        WHERE status NOT IN ('cancelled', 'refunded')
    ");
    $total_revenue = (float)$stmt->fetch()['total'];

    $stmt = $db->query("SELECT COUNT(*) as total FROM om_customers WHERE is_active = '1'");
    $total_customers = (int)$stmt->fetch()['total'];

    $stmt = $db->query("SELECT COUNT(*) as total FROM om_market_partners WHERE status = '1'");
    $total_partners = (int)$stmt->fetch()['total'];

    $stmt = $db->query("SELECT COUNT(*) as total FROM om_market_shoppers WHERE status = '1'");
    $total_shoppers = (int)$stmt->fetch()['total'];

    $avg_order_value = $total_orders > 0 ? round($total_revenue / $total_orders, 2) : 0;

    response(true, [
        'total_orders' => $total_orders,
        'total_revenue' => $total_revenue,
        'total_customers' => $total_customers,
        'total_partners' => $total_partners,
        'total_shoppers' => $total_shoppers,
        'avg_order_value' => $avg_order_value
    ], "Estatisticas gerais");
} catch (Exception $e) {
    error_log("[admin/stats] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
