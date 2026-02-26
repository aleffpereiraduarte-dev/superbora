<?php
/**
 * API para obter detalhes de um pedido do mercado
 */

header('Content-Type: application/json');

require_once __DIR__ . '/config/database.php';
setCorsHeaders();

$id = intval($_GET['id'] ?? 0);

if (!$id) {
    echo json_encode(['success' => false, 'error' => 'ID inválido']);
    exit;
}

// Auth: verificar que o pedido pertence ao usuario autenticado
$customer_id = getCustomerIdFromToken();
if (!$customer_id) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Autenticacao necessaria']);
    exit;
}

try {
    $db = getDB();

    // Buscar pedido
    $stmt = $db->prepare("
        SELECT o.order_id, o.partner_id, o.customer_id, o.shopper_id, o.motorista_id,
               o.status, o.payment_method, o.subtotal, o.delivery_fee, o.discount, o.total,
               o.delivery_address, o.shipping_lat AS delivery_lat, o.shipping_lng AS delivery_lng, o.customer_name, o.customer_phone,
               o.notes, o.rating, o.created_at, o.confirmed_at, o.entrega_finalizada_em,
               p.name as mercado_name,
               s.name as shopper_name,
               CASE WHEN s.phone IS NOT NULL THEN CONCAT(LEFT(s.phone, 4), '****', RIGHT(s.phone, 4)) ELSE NULL END as shopper_phone,
               m.name as motorista_name
        FROM om_market_orders o
        LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
        LEFT JOIN om_shoppers s ON o.shopper_id = s.shopper_id
        LEFT JOIN om_motoristas m ON o.motorista_id = m.motorista_id
        WHERE o.order_id = ? AND o.customer_id = ?
    ");
    $stmt->execute([$id, $customer_id]);
    $pedido = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pedido) {
        echo json_encode(['success' => false, 'error' => 'Pedido não encontrado']);
        exit;
    }

    // Buscar itens
    $stmt = $db->prepare("SELECT * FROM om_market_order_items WHERE order_id = ?");
    $stmt->execute([$id]);
    $itens = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $pedido['itens'] = $itens;

    echo json_encode(['success' => true, 'pedido' => $pedido]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Erro interno']);
}
