<?php
/**
 * API: Confirmar coleta do pedido (Handoff)
 * POST /mercado/api/entregador/coletar.php
 */
require_once __DIR__ . '/config.php';

$input = getInput();
$order_id = (int)($input['order_id'] ?? 0);
$driver_id = (int)($input['driver_id'] ?? 0);
$qr_code = $input['qr_code'] ?? '';

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
$stmt = $pdo->prepare("SELECT * FROM om_market_orders WHERE order_id = ?");
$stmt->execute([$order_id]);
$order = $stmt->fetch();

if (!$order) {
    jsonResponse(['success' => false, 'error' => 'Pedido nao encontrado'], 404);
}

// Verificar se o driver e o responsavel
if ($order['delivery_driver_id'] && $order['delivery_driver_id'] != $driver_id) {
    jsonResponse(['success' => false, 'error' => 'Voce nao e o entregador deste pedido'], 403);
}

// Validar QR code se fornecido
if ($qr_code) {
    $expected_code = 'HANDOFF-' . $order_id;
    if ($qr_code !== $expected_code && $qr_code !== ($order['handoff_code'] ?? '')) {
        jsonResponse(['success' => false, 'error' => 'QR Code invalido'], 400);
    }
}

$pdo->beginTransaction();
try {
    // Atualizar pedido
    $stmt = $pdo->prepare("UPDATE om_market_orders SET
        status = 'delivering',
        delivery_driver_id = ?,
        delivery_name = ?,
        delivery_picked_at = NOW()
        WHERE order_id = ?
    ");
    $stmt->execute([$driver_id, $driver['name'], $order_id]);

    // Registrar no historico
    try {
        $pdo->prepare("INSERT INTO om_market_order_history (order_id, status, comment, created_at) VALUES (?, 'collected', ?, NOW())")
            ->execute([$order_id, 'Pedido coletado pelo entregador ' . $driver['name']]);
    } catch (Exception $e) {}

    $pdo->commit();

    // Gerar codigo de entrega
    $delivery_code = strtoupper(substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ'), 0, 5)) . '-' . rand(100, 999);
    $pdo->prepare("UPDATE om_market_orders SET delivery_code = ? WHERE order_id = ?")->execute([$delivery_code, $order_id]);

    jsonResponse([
        'success' => true,
        'message' => 'Coleta confirmada! Siga para o endereco de entrega.',
        'order_id' => $order_id,
        'delivery_code' => $delivery_code,
        'delivery_address' => [
            'address' => $order['shipping_address'] . ', ' . $order['shipping_number'],
            'complement' => $order['shipping_complement'] ?? '',
            'neighborhood' => $order['shipping_neighborhood'] ?? '',
            'city' => $order['shipping_city'],
            'lat' => (float)($order['shipping_lat'] ?? $order['shipping_latitude'] ?? 0),
            'lng' => (float)($order['shipping_lng'] ?? $order['shipping_longitude'] ?? 0)
        ],
        'customer' => [
            'name' => $order['customer_name'] ?? 'Cliente',
            'phone' => $order['customer_phone'] ?? ''
        ]
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    jsonResponse(['success' => false, 'error' => 'Erro ao confirmar coleta: ' . $e->getMessage()], 500);
}
