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

    $order_id = (int)($_GET['order_id'] ?? 0);
    if (!$order_id) response(false, null, "order_id obrigatorio", 400);

    $stmt = $db->prepare("
        SELECT t.*,
               CASE
                   WHEN t.actor_type = 'admin' THEN (SELECT name FROM om_admins WHERE admin_id = t.actor_id)
                   WHEN t.actor_type = 'shopper' THEN (SELECT name FROM om_market_shoppers WHERE shopper_id = t.actor_id)
                   WHEN t.actor_type = 'partner' THEN (SELECT name FROM om_market_partners WHERE partner_id = t.actor_id)
                   WHEN t.actor_type = 'customer' THEN (SELECT firstname FROM oc_customer WHERE customer_id = t.actor_id)
                   ELSE 'Sistema'
               END as actor_name
        FROM om_order_timeline t
        WHERE t.order_id = ?
        ORDER BY t.created_at ASC
    ");
    $stmt->execute([$order_id]);
    $timeline = $stmt->fetchAll();

    response(true, ['timeline' => $timeline, 'order_id' => $order_id], "Timeline do pedido");
} catch (Exception $e) {
    error_log("[admin/order-timeline] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
