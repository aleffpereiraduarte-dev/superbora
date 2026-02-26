<?php
/**
 * API: Obter codigo de entrega
 * GET /mercado/api/entregador/codigo-entrega.php?order_id=X
 */
require_once __DIR__ . '/config.php';

$order_id = (int)($_GET['order_id'] ?? $_POST['order_id'] ?? 0);

if (!$order_id) {
    jsonResponse(['success' => false, 'error' => 'order_id obrigatorio'], 400);
}

$pdo = getDB();

// Buscar pedido
$stmt = $pdo->prepare("SELECT order_id, delivery_code, customer_name, status FROM om_market_orders WHERE order_id = ?");
$stmt->execute([$order_id]);
$order = $stmt->fetch();

if (!$order) {
    jsonResponse(['success' => false, 'error' => 'Pedido nao encontrado'], 404);
}

// Gerar codigo se nao existir
$delivery_code = $order['delivery_code'];
if (!$delivery_code) {
    $delivery_code = strtoupper(substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ'), 0, 5)) . '-' . rand(100, 999);
    $pdo->prepare("UPDATE om_market_orders SET delivery_code = ? WHERE order_id = ?")->execute([$delivery_code, $order_id]);
}

jsonResponse([
    'success' => true,
    'order_id' => $order_id,
    'delivery_code' => $delivery_code,
    'status' => $order['status'],
    'customer_name' => $order['customer_name'] ?? 'Cliente'
]);
