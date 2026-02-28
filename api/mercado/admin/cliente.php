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
    if (!$id) response(false, null, "ID do cliente obrigatorio", 400);

    // Customer profile — explicit columns to avoid exposing sensitive data
    $stmt = $db->prepare("
        SELECT customer_id as id, name as nome, email, phone as telefone,
               created_at, status, cpf
        FROM om_customers
        WHERE customer_id = ?
    ");
    $stmt->execute([$id]);
    $customer = $stmt->fetch();
    if (!$customer) response(false, null, "Cliente nao encontrado", 404);

    // Order history
    $stmt = $db->prepare("
        SELECT o.order_id, o.status, o.total, o.delivery_fee, o.created_at,
               p.name as partner_name
        FROM om_market_orders o
        LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
        WHERE o.customer_id = ?
        ORDER BY o.created_at DESC
        LIMIT 50
    ");
    $stmt->execute([$id]);
    $orders = $stmt->fetchAll();

    // Spending stats
    $stmt = $db->prepare("
        SELECT COUNT(*) as total_orders,
               COALESCE(SUM(CASE WHEN status NOT IN ('cancelled','refunded') THEN total ELSE 0 END), 0) as total_spent,
               COALESCE(AVG(CASE WHEN status NOT IN ('cancelled','refunded') THEN total END), 0) as avg_order,
               MAX(created_at) as last_order_date
        FROM om_market_orders
        WHERE customer_id = ?
    ");
    $stmt->execute([$id]);
    $stats = $stmt->fetch();

    response(true, [
        'customer' => $customer,
        'orders' => $orders,
        'stats' => $stats
    ], "Detalhes do cliente");
} catch (Exception $e) {
    error_log("[admin/cliente] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
