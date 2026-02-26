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

    // Active deliveries with shopper positions
    $stmt = $db->query("
        SELECT o.order_id, o.status, o.total, o.delivery_address, o.created_at, o.updated_at,
               o.shopper_id,
               s.name as shopper_name, s.phone as shopper_phone, s.photo as shopper_photo,
               p.name as partner_name, p.address as partner_address,
               c.firstname as customer_name, c.telephone as customer_phone,
               w.current_lat AS lat, w.current_lng AS lng, w.is_online as worker_online
        FROM om_market_orders o
        INNER JOIN om_market_shoppers s ON o.shopper_id = s.shopper_id
        LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
        LEFT JOIN oc_customer c ON o.customer_id = c.customer_id
        LEFT JOIN om_workers w ON w.email = s.email
        WHERE o.status IN ('collecting', 'in_transit')
        ORDER BY o.created_at ASC
    ");
    $deliveries = $stmt->fetchAll();

    // Summary stats
    $collecting = 0;
    $in_transit = 0;
    foreach ($deliveries as $d) {
        if ($d['status'] === 'collecting') $collecting++;
        else $in_transit++;
    }

    response(true, [
        'active_deliveries' => $deliveries,
        'summary' => [
            'total' => count($deliveries),
            'collecting' => $collecting,
            'in_transit' => $in_transit
        ]
    ], "Entregas ativas");
} catch (Exception $e) {
    error_log("[admin/tracking] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
