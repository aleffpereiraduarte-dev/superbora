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
    if (!$id) response(false, null, "ID obrigatorio", 400);

    $stmt = $db->prepare("
        SELECT partner_id, name, nome, email, phone, telefone, cnpj,
               address, endereco, city, state, neighborhood, cep,
               categoria, status, logo, banner, description,
               commission_rate, commission_type, partnership_type,
               opening_hours, delivery_radius, min_order, avg_prep_time,
               rating, total_orders, total_vendas,
               date_added as created_at, date_modified as updated_at
        FROM om_market_partners WHERE partner_id = ?
    ");
    $stmt->execute([$id]);
    $market = $stmt->fetch();
    if (!$market) response(false, null, "Mercado nao encontrado", 404);

    // Products count
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM om_market_products WHERE partner_id = ?");
    $stmt->execute([$id]);
    $products_count = (int)$stmt->fetch()['total'];

    // Order stats
    $stmt = $db->prepare("
        SELECT COUNT(*) as total_orders,
               COALESCE(SUM(CASE WHEN status NOT IN ('cancelled','refunded') THEN total ELSE 0 END), 0) as total_revenue,
               COALESCE(AVG(CASE WHEN status NOT IN ('cancelled','refunded') THEN total END), 0) as avg_order
        FROM om_market_orders WHERE partner_id = ?
    ");
    $stmt->execute([$id]);
    $order_stats = $stmt->fetch();

    // Recent orders
    $stmt = $db->prepare("
        SELECT o.order_id, o.status, o.total, o.created_at,
               c.firstname as customer_name
        FROM om_market_orders o
        LEFT JOIN oc_customer c ON o.customer_id = c.customer_id
        WHERE o.partner_id = ?
        ORDER BY o.created_at DESC LIMIT 10
    ");
    $stmt->execute([$id]);
    $recent_orders = $stmt->fetchAll();

    response(true, [
        'market' => $market,
        'products_count' => $products_count,
        'order_stats' => $order_stats,
        'recent_orders' => $recent_orders
    ], "Detalhes do mercado");
} catch (Exception $e) {
    error_log("[admin/mercado] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
