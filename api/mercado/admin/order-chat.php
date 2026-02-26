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
        SELECT ch.*,
               CASE
                   WHEN ch.sender_type = 'customer' THEN (SELECT firstname FROM oc_customer WHERE customer_id = ch.sender_id)
                   WHEN ch.sender_type = 'shopper' THEN (SELECT name FROM om_market_shoppers WHERE shopper_id = ch.sender_id)
                   WHEN ch.sender_type = 'admin' THEN (SELECT name FROM om_admins WHERE admin_id = ch.sender_id)
                   WHEN ch.sender_type = 'partner' THEN (SELECT name FROM om_market_partners WHERE partner_id = ch.sender_id)
                   ELSE 'Sistema'
               END as sender_name
        FROM om_order_chat ch
        WHERE ch.order_id = ?
        ORDER BY ch.created_at ASC
    ");
    $stmt->execute([$order_id]);
    $messages = $stmt->fetchAll();

    response(true, ['messages' => $messages, 'order_id' => $order_id], "Chat do pedido");
} catch (Exception $e) {
    error_log("[admin/order-chat] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
