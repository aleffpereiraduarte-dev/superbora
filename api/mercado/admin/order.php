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

    $id = (int)($_GET['id'] ?? 0);
    if (!$id) response(false, null, "ID do pedido obrigatorio", 400);

    // Full order with JOINs
    $stmt = $db->prepare("
        SELECT o.*,
               c.firstname as customer_firstname, c.lastname as customer_lastname,
               c.email as customer_email, c.telephone as customer_phone,
               p.name as partner_name, p.email as partner_email,
               p.phone as partner_phone, p.address as partner_address,
               s.name as shopper_name, s.email as shopper_email,
               s.phone as shopper_phone
        FROM om_market_orders o
        LEFT JOIN oc_customer c ON o.customer_id = c.customer_id
        LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
        LEFT JOIN om_market_shoppers s ON o.shopper_id = s.shopper_id
        WHERE o.order_id = ?
    ");
    $stmt->execute([$id]);
    $order = $stmt->fetch();
    if (!$order) response(false, null, "Pedido nao encontrado", 404);

    // Items
    $stmt = $db->prepare("SELECT * FROM om_market_order_items WHERE order_id = ?");
    $stmt->execute([$id]);
    $items = $stmt->fetchAll();

    // Timeline
    $stmt = $db->prepare("SELECT * FROM om_order_timeline WHERE order_id = ? ORDER BY created_at ASC");
    $stmt->execute([$id]);
    $timeline = $stmt->fetchAll();

    // Notes
    $stmt = $db->prepare("
        SELECT n.*, a.name as admin_name
        FROM om_order_notes n
        LEFT JOIN om_admins a ON n.admin_id = a.admin_id
        WHERE n.order_id = ?
        ORDER BY n.created_at DESC
    ");
    $stmt->execute([$id]);
    $notes = $stmt->fetchAll();

    response(true, [
        'order' => $order,
        'items' => $items,
        'timeline' => $timeline,
        'notes' => $notes,
        'totals' => [
            'subtotal' => (float)($order['subtotal'] ?? 0),
            'delivery_fee' => (float)($order['delivery_fee'] ?? 0),
            'total' => (float)($order['total'] ?? 0)
        ]
    ], "Detalhes do pedido");
} catch (Exception $e) {
    error_log("[admin/order] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
