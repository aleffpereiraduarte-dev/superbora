<?php
/**
 * API: Aceitar entrega
 * POST /mercado/api/entregador/aceitar.php
 * Usa tabela om_boraum_drivers (motorista BoraUm = entregador Mercado)
 */
require_once __DIR__ . '/config.php';

$input = getInput();
$order_id = (int)($input['order_id'] ?? 0);
$driver_id = (int)($input['driver_id'] ?? 0);
$offer_id = (int)($input['offer_id'] ?? 0);

if (!$order_id || !$driver_id) {
    jsonResponse(['success' => false, 'error' => 'order_id e driver_id obrigatorios'], 400);
}

$pdo = getDB();

// Verificar driver
$driver = validateDriver($driver_id);
if (!$driver) {
    jsonResponse(['success' => false, 'error' => 'Motorista nao encontrado'], 404);
}

if (!canDeliverMarket($driver)) {
    jsonResponse(['success' => false, 'error' => 'Motorista nao aprovado para entregas'], 403);
}

// Verificar pedido
$stmt = $pdo->prepare("SELECT * FROM om_market_orders WHERE order_id = ?");
$stmt->execute([$order_id]);
$order = $stmt->fetch();

if (!$order) {
    jsonResponse(['success' => false, 'error' => 'Pedido nao encontrado'], 404);
}

// Status válidos para aceitar entrega: purchased (após shopper finalizar)
if ($order['status'] !== 'purchased') {
    jsonResponse(['success' => false, 'error' => 'Pedido nao disponivel para entrega', 'status' => $order['status']], 400);
}

if ($order['delivery_driver_id'] && $order['delivery_driver_id'] != $driver_id) {
    jsonResponse(['success' => false, 'error' => 'Pedido ja atribuido a outro entregador'], 400);
}

$pdo->beginTransaction();
try {
    // Atribuir entregador ao pedido e mudar status para 'delivering'
    $stmt = $pdo->prepare("UPDATE om_market_orders SET
        delivery_driver_id = ?,
        delivery_name = ?,
        delivery_accepted_at = NOW(),
        status = 'delivering'
        WHERE order_id = ?
    ");
    $stmt->execute([$driver_id, $driver['name'], $order_id]);

    // Marcar motorista como ocupado
    $pdo->prepare("UPDATE om_boraum_drivers SET is_available = 0 WHERE driver_id = ?")->execute([$driver_id]);

    // Se tinha oferta, marcar como aceita
    if ($offer_id > 0) {
        $pdo->prepare("UPDATE om_market_driver_offers SET status = 'accepted', responded_at = NOW() WHERE id = ?")->execute([$offer_id]);
        // Rejeitar outras ofertas do mesmo pedido
        $pdo->prepare("UPDATE om_market_driver_offers SET status = 'cancelled' WHERE order_id = ? AND id != ?")->execute([$order_id, $offer_id]);
    }

    // Registrar no historico
    try {
        $pdo->prepare("INSERT INTO om_market_order_history (order_id, status, comment, created_at) VALUES (?, 'delivery_accepted', ?, NOW())")
            ->execute([$order_id, 'Entregador ' . $driver['name'] . ' aceitou a entrega']);
    } catch (Exception $e) {}

    $pdo->commit();

    jsonResponse([
        'success' => true,
        'message' => 'Entrega aceita com sucesso!',
        'order_id' => $order_id,
        'driver' => [
            'id' => $driver_id,
            'name' => $driver['name']
        ],
        'pickup' => [
            'market_id' => $order['market_id'],
            'address' => $order['store_address'] ?? 'Ver no app'
        ],
        'delivery' => [
            'address' => $order['shipping_address'] . ', ' . $order['shipping_number'],
            'city' => $order['shipping_city'],
            'customer' => $order['customer_name'] ?? 'Cliente'
        ]
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    jsonResponse(['success' => false, 'error' => 'Erro ao aceitar entrega: ' . $e->getMessage()], 500);
}
