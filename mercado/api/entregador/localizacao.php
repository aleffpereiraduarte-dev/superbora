<?php
/**
 * API: Atualizar localizacao do entregador
 * POST /mercado/api/entregador/localizacao.php
 * Usa tabela om_boraum_drivers (motorista BoraUm = entregador Mercado)
 */
require_once __DIR__ . '/config.php';

$input = getInput();
$driver_id = (int)($input['driver_id'] ?? 0);
$lat = (float)($input['latitude'] ?? $input['lat'] ?? 0);
$lng = (float)($input['longitude'] ?? $input['lng'] ?? 0);
$status = $input['status'] ?? null;
$order_id = (int)($input['order_id'] ?? 0);

if (!$driver_id) {
    jsonResponse(['success' => false, 'error' => 'driver_id obrigatorio'], 400);
}

if (!$lat || !$lng) {
    jsonResponse(['success' => false, 'error' => 'latitude e longitude obrigatorios'], 400);
}

$pdo = getDB();

// Verificar se motorista existe
$driver = validateDriver($driver_id);
if (!$driver) {
    jsonResponse(['success' => false, 'error' => 'Motorista nao encontrado'], 404);
}

// Atualizar localizacao na tabela om_boraum_drivers
$sql = "UPDATE om_boraum_drivers SET
    current_lat = ?,
    current_lng = ?,
    last_location_update = NOW()";

$params = [$lat, $lng];

// Se informou status, atualizar tambem
if ($status === 'online') {
    $sql .= ", is_online = 1, is_available = 1";
} elseif ($status === 'offline') {
    $sql .= ", is_online = 0, is_available = 0";
}

$sql .= " WHERE driver_id = ?";
$params[] = $driver_id;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

// Se tem pedido em andamento, salvar historico de GPS
if ($order_id > 0) {
    try {
        $stmt = $pdo->prepare("INSERT INTO om_delivery_tracking (order_id, driver_id, lat, lng, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$order_id, $driver_id, $lat, $lng]);
    } catch (Exception $e) {
        // Tabela pode nao existir, ignorar
    }
}

// Buscar pedido atual se estiver em entrega
$currentOrder = null;
$stmt = $pdo->prepare("SELECT order_id, status, shipping_lat, shipping_lng FROM om_market_orders WHERE delivery_driver_id = ? AND status = 'delivering' LIMIT 1");
$stmt->execute([$driver_id]);
$order = $stmt->fetch();

if ($order) {
    // Calcular distancia ate destino
    $distance = null;
    $eta = null;
    if ($order['shipping_lat'] && $order['shipping_lng']) {
        $lat1 = deg2rad($lat);
        $lng1 = deg2rad($lng);
        $lat2 = deg2rad($order['shipping_lat']);
        $lng2 = deg2rad($order['shipping_lng']);

        $dlat = $lat2 - $lat1;
        $dlng = $lng2 - $lng1;
        $a = sin($dlat/2) * sin($dlat/2) + cos($lat1) * cos($lat2) * sin($dlng/2) * sin($dlng/2);
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        $distance = round(6371000 * $c);
        $eta = ceil($distance / (25 * 1000 / 3600) / 60);
    }

    $currentOrder = [
        'order_id' => $order['order_id'],
        'status' => $order['status'],
        'distance_meters' => $distance,
        'eta_minutes' => $eta,
        'arriving' => $distance && $distance < 200
    ];
}

jsonResponse([
    'success' => true,
    'message' => 'Localizacao atualizada',
    'driver' => [
        'id' => $driver_id,
        'name' => $driver['name'],
        'is_online' => $status === 'online' ? 1 : ($status === 'offline' ? 0 : $driver['is_online'])
    ],
    'current_order' => $currentOrder
]);
