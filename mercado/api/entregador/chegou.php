<?php
/**
 * API: Entregador chegou no destino
 * POST /mercado/api/entregador/chegou.php
 */
require_once __DIR__ . '/config.php';

$input = getInput();
$order_id = (int)($input['order_id'] ?? 0);
$driver_id = (int)($input['driver_id'] ?? 0);

if (!$order_id || !$driver_id) {
    jsonResponse(['success' => false, 'error' => 'order_id e driver_id obrigatorios'], 400);
}

$pdo = getDB();

// Verificar driver
$driver = validateDriver($driver_id);
if (!$driver) {
    jsonResponse(['success' => false, 'error' => 'Motorista nao encontrado'], 404);
}

// Verificar pedido
$stmt = $pdo->prepare("SELECT * FROM om_market_orders WHERE order_id = ? AND delivery_driver_id = ?");
$stmt->execute([$order_id, $driver_id]);
$order = $stmt->fetch();

if (!$order) {
    jsonResponse(['success' => false, 'error' => 'Pedido nao encontrado ou nao atribuido a voce'], 404);
}

// Atualizar status (coluna correta: driver_arrived_at)
$pdo->prepare("UPDATE om_market_orders SET driver_arrived_at = NOW() WHERE order_id = ?")->execute([$order_id]);

// Registrar no historico
try {
    $pdo->prepare("INSERT INTO om_market_order_history (order_id, status, comment, created_at) VALUES (?, 'arrived', 'Entregador chegou no destino', NOW())")
        ->execute([$order_id]);
} catch (Exception $e) {}

// Buscar codigo de entrega
$delivery_code = $order['delivery_code'] ?? '';
if (!$delivery_code) {
    $delivery_code = strtoupper(substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ'), 0, 5)) . '-' . rand(100, 999);
    $pdo->prepare("UPDATE om_market_orders SET delivery_code = ? WHERE order_id = ?")->execute([$delivery_code, $order_id]);
}

jsonResponse([
    'success' => true,
    'message' => 'Chegada registrada! Aguardando confirmacao do cliente.',
    'order_id' => $order_id,
    'delivery_code' => $delivery_code,
    'customer' => [
        'name' => $order['customer_name'] ?? 'Cliente',
        'phone' => $order['customer_phone'] ?? ''
    ],
    'instructions' => 'Solicite ao cliente o codigo de entrega: ' . $delivery_code
]);
