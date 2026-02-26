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
    if (!$id) response(false, null, "ID do shopper obrigatorio", 400);

    // Profile — select only needed columns, exclude sensitive fields (password_hash, pix_key, bank_account, cpf)
    $stmt = $db->prepare("
        SELECT shopper_id, name, email, phone, status, photo, avatar,
               rating, is_online, saldo, city, state, neighborhood,
               vehicle_type, vehicle_plate, has_vehicle,
               created_at, updated_at, last_login, last_activity
        FROM om_market_shoppers WHERE shopper_id = ?
    ");
    $stmt->execute([$id]);
    $shopper = $stmt->fetch();
    if (!$shopper) response(false, null, "Shopper nao encontrado", 404);

    // Orders
    $stmt = $db->prepare("
        SELECT o.order_id, o.status, o.total, o.delivery_fee, o.created_at,
               p.name as partner_name, c.firstname as customer_name
        FROM om_market_orders o
        LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
        LEFT JOIN oc_customer c ON o.customer_id = c.customer_id
        WHERE o.shopper_id = ?
        ORDER BY o.created_at DESC
        LIMIT 50
    ");
    $stmt->execute([$id]);
    $orders = $stmt->fetchAll();

    // Performance stats
    $stmt = $db->prepare("
        SELECT COUNT(*) as total_orders,
               SUM(CASE WHEN status = 'entregue' THEN 1 ELSE 0 END) as delivered,
               SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
               COALESCE(SUM(delivery_fee), 0) as total_earnings,
               COALESCE(AVG(EXTRACT(EPOCH FROM (updated_at - created_at)) / 60), 0) as avg_delivery_minutes
        FROM om_market_orders
        WHERE shopper_id = ?
    ");
    $stmt->execute([$id]);
    $performance = $stmt->fetch();

    response(true, [
        'shopper' => $shopper,
        'orders' => $orders,
        'performance' => $performance
    ], "Detalhes do shopper");
} catch (Exception $e) {
    error_log("[admin/shopper] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
