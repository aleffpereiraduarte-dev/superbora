<?php
/**
 * API: Listar ofertas de entrega disponiveis
 * GET /mercado/api/entregador/ofertas.php?driver_id=X&lat=Y&lng=Z
 * Usa tabela om_boraum_drivers (motorista BoraUm = entregador Mercado)
 */
require_once __DIR__ . '/config.php';

$driver_id = (int)($_GET['driver_id'] ?? $_POST['driver_id'] ?? 0);
$lat = (float)($_GET['lat'] ?? $_POST['lat'] ?? 0);
$lng = (float)($_GET['lng'] ?? $_POST['lng'] ?? 0);

if (!$driver_id) {
    jsonResponse(['success' => false, 'error' => 'driver_id obrigatorio'], 400);
}

$pdo = getDB();

// Verificar se driver existe e esta ativo
$driver = validateDriver($driver_id);
if (!$driver) {
    jsonResponse(['success' => false, 'error' => 'Motorista nao encontrado'], 404);
}

if (!canDeliverMarket($driver)) {
    jsonResponse(['success' => false, 'error' => 'Motorista nao aprovado para entregas', 'status' => $driver['status']], 403);
}

// Buscar ofertas pendentes para este driver
$stmt = $pdo->prepare("
    SELECT o.*,
           ord.order_id, ord.total, ord.shipping_address, ord.shipping_number, ord.shipping_city,
           ord.shipping_latitude, ord.shipping_longitude, ord.delivery_fee,
           p.name as mercado_nome, p.address as mercado_endereco, p.latitude as mercado_lat, p.longitude as mercado_lng
    FROM om_market_driver_offers o
    JOIN om_market_orders ord ON o.order_id = ord.order_id
    LEFT JOIN om_market_partners p ON ord.partner_id = p.partner_id
    WHERE o.driver_id = ?
    AND o.status = 'pending'
    AND (o.expires_at IS NULL OR o.expires_at > NOW())
    ORDER BY o.created_at DESC
");
$stmt->execute([$driver_id]);
$offers = $stmt->fetchAll();

// Se nao tem ofertas especificas, buscar pedidos aguardando entregador
if (empty($offers)) {
    $sql = "
        SELECT ord.order_id, ord.total, ord.shipping_address, ord.shipping_number, ord.shipping_city,
               ord.shipping_latitude, ord.shipping_longitude, ord.delivery_fee, ord.created_at,
               p.name as mercado_nome, p.address as mercado_endereco, p.latitude as mercado_lat, p.longitude as mercado_lng
        FROM om_market_orders ord
        LEFT JOIN om_market_partners p ON ord.partner_id = p.partner_id
        WHERE ord.status IN ('awaiting_delivery', 'purchased', 'shopping_complete')
        AND (ord.delivery_driver_id IS NULL OR ord.delivery_driver_id = 0)
    ";

    // Se tem coordenadas, ordenar por distancia
    if ($lat && $lng) {
        $sql .= " ORDER BY (POW(COALESCE(ord.shipping_latitude, 0) - ?, 2) + POW(COALESCE(ord.shipping_longitude, 0) - ?, 2)) ASC";
        $stmt = $pdo->prepare($sql . " LIMIT 20");
        $stmt->execute([$lat, $lng]);
    } else {
        $stmt = $pdo->prepare($sql . " ORDER BY ord.created_at ASC LIMIT 20");
        $stmt->execute();
    }

    $available = $stmt->fetchAll();

    jsonResponse([
        'success' => true,
        'driver' => [
            'id' => $driver_id,
            'name' => $driver['name'],
            'rating' => (float)$driver['rating']
        ],
        'offers' => [],
        'available_orders' => array_map(function($o) use ($lat, $lng) {
            $distance = null;
            if ($lat && $lng && $o['mercado_lat'] && $o['mercado_lng']) {
                $lat1 = deg2rad($lat);
                $lng1 = deg2rad($lng);
                $lat2 = deg2rad($o['mercado_lat']);
                $lng2 = deg2rad($o['mercado_lng']);
                $dlat = $lat2 - $lat1;
                $dlng = $lng2 - $lng1;
                $a = sin($dlat/2) * sin($dlat/2) + cos($lat1) * cos($lat2) * sin($dlng/2) * sin($dlng/2);
                $c = 2 * atan2(sqrt($a), sqrt(1-$a));
                $distance = round(6371 * $c, 1);
            }
            $fee = (float)($o['delivery_fee'] ?? 0);
            if ($fee <= 0) {
                $fee = max(5.00, min(15.00, ($distance ?? 5) * 1.5));
            }
            return [
                'order_id' => $o['order_id'],
                'total' => (float)$o['total'],
                'pickup' => [
                    'name' => $o['mercado_nome'] ?? 'Mercado',
                    'address' => $o['mercado_endereco'] ?? '',
                    'lat' => (float)($o['mercado_lat'] ?? 0),
                    'lng' => (float)($o['mercado_lng'] ?? 0)
                ],
                'delivery' => [
                    'address' => $o['shipping_address'] . ', ' . $o['shipping_number'],
                    'city' => $o['shipping_city'],
                    'lat' => (float)($o['shipping_latitude'] ?? 0),
                    'lng' => (float)($o['shipping_longitude'] ?? 0)
                ],
                'distance_km' => $distance,
                'delivery_fee' => $fee,
                'created_at' => $o['created_at']
            ];
        }, $available)
    ]);
    exit;
}

// Formatar ofertas
$formatted = array_map(function($o) {
    return [
        'offer_id' => $o['id'] ?? $o['offer_id'],
        'order_id' => $o['order_id'],
        'total' => (float)$o['total'],
        'delivery_fee' => (float)($o['delivery_fee'] ?? 8.00),
        'pickup' => [
            'name' => $o['mercado_nome'] ?? 'Mercado',
            'address' => $o['mercado_endereco'] ?? ''
        ],
        'delivery' => [
            'address' => $o['shipping_address'] . ', ' . $o['shipping_number'],
            'city' => $o['shipping_city']
        ],
        'expires_at' => $o['expires_at'] ?? null,
        'seconds_left' => $o['expires_at'] ? max(0, strtotime($o['expires_at']) - time()) : 300
    ];
}, $offers);

jsonResponse([
    'success' => true,
    'driver' => [
        'id' => $driver_id,
        'name' => $driver['name'],
        'rating' => (float)$driver['rating']
    ],
    'offers' => $formatted,
    'available_orders' => []
]);
