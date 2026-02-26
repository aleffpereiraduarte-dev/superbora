<?php
/**
 * API: Atualizar status do entregador (online/offline/delivering)
 * POST /mercado/api/entregador/status.php
 */
require_once __DIR__ . '/config.php';

$input = getInput();
$driver_id = (int)($input['driver_id'] ?? $_GET['driver_id'] ?? 0);
$order_id = (int)($input['order_id'] ?? 0);
$status = $input['status'] ?? '';
$lat = (float)($input['lat'] ?? $input['latitude'] ?? 0);
$lng = (float)($input['lng'] ?? $input['longitude'] ?? 0);

if (!$driver_id) {
    jsonResponse(['success' => false, 'error' => 'driver_id obrigatorio'], 400);
}

$pdo = getDB();

// Verificar driver
$driver = validateDriver($driver_id);
if (!$driver) {
    jsonResponse(['success' => false, 'error' => 'Motorista nao encontrado'], 404);
}

// GET - Retornar status atual
if ($_SERVER['REQUEST_METHOD'] === 'GET' || empty($status)) {
    // Buscar pedido atual se tiver
    $currentOrder = null;
    $stmt = $pdo->prepare("SELECT order_id, status, shipping_address, shipping_city FROM om_market_orders WHERE delivery_driver_id = ? AND status IN ('delivering', 'collected') ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$driver_id]);
    $order = $stmt->fetch();

    if ($order) {
        $currentOrder = [
            'order_id' => $order['order_id'],
            'status' => $order['status'],
            'address' => $order['shipping_address'] . ', ' . $order['shipping_city']
        ];
    }

    jsonResponse([
        'success' => true,
        'driver' => [
            'id' => $driver_id,
            'name' => $driver['name'],
            'status' => $driver['status'],
            'is_online' => (bool)$driver['is_online'],
            'is_available' => (bool)$driver['is_available'],
            'rating' => (float)$driver['rating'],
            'total_rides' => (int)$driver['total_rides'],
            'balance' => (float)$driver['balance']
        ],
        'current_order' => $currentOrder,
        'location' => [
            'lat' => (float)$driver['current_lat'],
            'lng' => (float)$driver['current_lng'],
            'last_update' => $driver['last_location_update']
        ]
    ]);
    exit;
}

// POST - Atualizar status
$updates = [];
$params = [];

if ($status === 'online' || $status === 'available') {
    $updates[] = "is_online = 1";
    $updates[] = "is_available = 1";
} elseif ($status === 'offline') {
    $updates[] = "is_online = 0";
    $updates[] = "is_available = 0";
} elseif ($status === 'busy' || $status === 'delivering') {
    $updates[] = "is_available = 0";
}

if ($lat && $lng) {
    $updates[] = "current_lat = ?";
    $updates[] = "current_lng = ?";
    $updates[] = "last_location_update = NOW()";
    $params[] = $lat;
    $params[] = $lng;
}

if (!empty($updates)) {
    $params[] = $driver_id;
    $sql = "UPDATE om_boraum_drivers SET " . implode(", ", $updates) . " WHERE driver_id = ?";
    $pdo->prepare($sql)->execute($params);
}

// Se tem order_id, atualizar status do pedido tambem
if ($order_id > 0 && $status === 'delivering') {
    $pdo->prepare("UPDATE om_market_orders SET status = 'delivering' WHERE order_id = ? AND delivery_driver_id = ?")
        ->execute([$order_id, $driver_id]);
}

jsonResponse([
    'success' => true,
    'message' => 'Status atualizado',
    'driver' => [
        'id' => $driver_id,
        'name' => $driver['name'],
        'is_online' => $status === 'online' || $status === 'available',
        'is_available' => $status === 'available' || $status === 'online'
    ]
]);
