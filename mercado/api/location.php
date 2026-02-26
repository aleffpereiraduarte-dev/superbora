<?php
/**
 * API DE LOCALIZACAO
 * GPS tracking de shoppers e entregadores
 * /mercado/api/location.php
 * SEGURO: Prepared statements
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);

// Conexao segura
require_once dirname(__DIR__) . '/config/database.php';
$pdo = getPDO();

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$action = $input['action'] ?? $_GET['action'] ?? '';

// ══════════════════════════════════════════════════════════════════════════════
// ATUALIZAR LOCALIZACAO
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'update') {
    $type = $input['type'] ?? '';
    $user_id = (int)($input['user_id'] ?? 0);
    $lat = (float)($input['lat'] ?? 0);
    $lng = (float)($input['lng'] ?? 0);

    if (!$type || !$user_id || !$lat || !$lng) {
        echo json_encode(['success' => false, 'message' => 'Dados incompletos']);
        exit;
    }

    // Validar coordenadas (Brasil aproximadamente)
    if ($lat < -35 || $lat > 6 || $lng < -75 || $lng > -30) {
        echo json_encode(['success' => false, 'message' => 'Coordenadas invalidas']);
        exit;
    }

    if ($type === 'shopper') {
        $stmt = $pdo->prepare("UPDATE om_market_shoppers SET lat = ?, lng = ?, last_location_at = NOW() WHERE shopper_id = ?");
        $stmt->execute([$lat, $lng, $user_id]);
    } elseif ($type === 'delivery') {
        $stmt = $pdo->prepare("UPDATE om_market_deliveries SET lat = ?, lng = ?, last_location_at = NOW() WHERE delivery_id = ?");
        $stmt->execute([$lat, $lng, $user_id]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Tipo invalido']);
        exit;
    }

    echo json_encode(['success' => true, 'message' => 'Localizacao atualizada']);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// BUSCAR LOCALIZACAO DO PEDIDO (para cliente acompanhar)
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'track_order') {
    $order_id = (int)($input['order_id'] ?? $_GET['order_id'] ?? 0);

    if (!$order_id) {
        echo json_encode(['success' => false, 'message' => 'order_id obrigatorio']);
        exit;
    }

    // Buscar pedido
    $stmt = $pdo->prepare("
        SELECT o.*,
               p.name as partner_name, p.address as partner_address, p.lat as partner_lat, p.lng as partner_lng
        FROM om_market_orders o
        LEFT JOIN om_market_partners p ON p.partner_id = o.partner_id
        WHERE o.order_id = ?
    ");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        echo json_encode(['success' => false, 'message' => 'Pedido nao encontrado']);
        exit;
    }

    $response = [
        'success' => true,
        'order_id' => $order_id,
        'status' => $order['status'],
        'locations' => [
            'store' => [
                'name' => $order['partner_name'],
                'address' => $order['partner_address'],
                'lat' => (float)$order['partner_lat'],
                'lng' => (float)$order['partner_lng']
            ],
            'delivery' => [
                'address' => $order['delivery_address'] ?? '',
                'lat' => (float)($order['delivery_lat'] ?? 0),
                'lng' => (float)($order['delivery_lng'] ?? 0)
            ]
        ]
    ];

    // Buscar localizacao do shopper se estiver em compra
    if (!empty($order['shopper_id']) && in_array($order['status'], ['em_compra', 'compra_finalizada', 'shopping'])) {
        $stmt = $pdo->prepare("SELECT name, lat, lng, last_location_at FROM om_market_shoppers WHERE shopper_id = ?");
        $stmt->execute([$order['shopper_id']]);
        $shopper = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($shopper) {
            $response['locations']['shopper'] = [
                'name' => $shopper['name'],
                'lat' => (float)$shopper['lat'],
                'lng' => (float)$shopper['lng'],
                'updated' => $shopper['last_location_at']
            ];
        }
    }

    // Buscar localizacao do entregador se estiver em entrega
    if (!empty($order['delivery_id']) && in_array($order['status'], ['em_entrega', 'delivering', 'out_for_delivery'])) {
        $stmt = $pdo->prepare("SELECT name, lat, lng, last_location_at, vehicle_type FROM om_market_deliveries WHERE delivery_id = ?");
        $stmt->execute([$order['delivery_id']]);
        $delivery = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($delivery) {
            $response['locations']['delivery_person'] = [
                'name' => $delivery['name'],
                'lat' => (float)$delivery['lat'],
                'lng' => (float)$delivery['lng'],
                'vehicle' => $delivery['vehicle_type'],
                'updated' => $delivery['last_location_at']
            ];

            // Calcular ETA estimado
            if ($delivery['lat'] && ($order['delivery_lat'] ?? 0)) {
                $distance = 6371 * acos(
                    cos(deg2rad($delivery['lat'])) * cos(deg2rad($order['delivery_lat']))
                    * cos(deg2rad($order['delivery_lng']) - deg2rad($delivery['lng']))
                    + sin(deg2rad($delivery['lat'])) * sin(deg2rad($order['delivery_lat']))
                );

                $speed = $delivery['vehicle_type'] === 'bike' ? 15 : 25;
                $eta_minutes = round(($distance / $speed) * 60);
                $response['eta_minutes'] = max(1, $eta_minutes);
            }
        }
    }

    echo json_encode($response);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// BUSCAR MERCADOS PROXIMOS
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'nearby_stores') {
    $lat = (float)($input['lat'] ?? $_GET['lat'] ?? 0);
    $lng = (float)($input['lng'] ?? $_GET['lng'] ?? 0);
    $radius = (float)($input['radius'] ?? $_GET['radius'] ?? 10);

    if (!$lat || !$lng) {
        echo json_encode(['success' => false, 'message' => 'Coordenadas obrigatorias']);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT p.*,
               (6371 * acos(cos(radians(?)) * cos(radians(p.lat))
               * cos(radians(p.lng) - radians(?))
               + sin(radians(?)) * sin(radians(p.lat)))) AS distance
        FROM om_market_partners p
        WHERE p.status = '1'
          AND p.lat IS NOT NULL
          AND p.lng IS NOT NULL
        HAVING distance <= ?
        ORDER BY distance ASC
        LIMIT 20
    ");
    $stmt->execute([$lat, $lng, $lat, $radius]);

    $stores = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $stores[] = [
            'id' => (int)$row['partner_id'],
            'name' => $row['name'],
            'slug' => $row['slug'] ?? '',
            'logo' => $row['logo'] ?? '',
            'address' => $row['address'] ?? '',
            'lat' => (float)$row['lat'],
            'lng' => (float)$row['lng'],
            'distance_km' => round((float)$row['distance'], 2),
            'delivery_fee' => (float)($row['delivery_fee'] ?? 0),
            'min_order' => (float)($row['min_order'] ?? 0),
            'avg_prep_time' => (int)($row['avg_prep_time'] ?? 30)
        ];
    }

    echo json_encode(['success' => true, 'stores' => $stores]);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// BUSCAR SHOPPERS/ENTREGADORES PROXIMOS (para admin)
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'nearby_workers') {
    $lat = (float)($input['lat'] ?? $_GET['lat'] ?? 0);
    $lng = (float)($input['lng'] ?? $_GET['lng'] ?? 0);
    $type = $input['type'] ?? $_GET['type'] ?? 'all';
    $radius = (float)($input['radius'] ?? $_GET['radius'] ?? 5);

    if (!$lat || !$lng) {
        echo json_encode(['success' => false, 'message' => 'Coordenadas obrigatorias']);
        exit;
    }

    $workers = [];

    if ($type === 'all' || $type === 'shopper') {
        $stmt = $pdo->prepare("
            SELECT 'shopper' as type, shopper_id as id, name, is_online, is_busy, lat, lng, rating,
                   (6371 * acos(cos(radians(?)) * cos(radians(lat))
                   * cos(radians(lng) - radians(?))
                   + sin(radians(?)) * sin(radians(lat)))) AS distance
            FROM om_market_shoppers
            WHERE status = 'active' AND lat IS NOT NULL
            HAVING distance <= ?
        ");
        $stmt->execute([$lat, $lng, $lat, $radius]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $workers[] = $row;
        }
    }

    if ($type === 'all' || $type === 'delivery') {
        $stmt = $pdo->prepare("
            SELECT 'delivery' as type, delivery_id as id, name, is_online, is_busy, lat, lng, rating, vehicle_type,
                   (6371 * acos(cos(radians(?)) * cos(radians(lat))
                   * cos(radians(lng) - radians(?))
                   + sin(radians(?)) * sin(radians(lat)))) AS distance
            FROM om_market_deliveries
            WHERE status = 'active' AND lat IS NOT NULL
            HAVING distance <= ?
        ");
        $stmt->execute([$lat, $lng, $lat, $radius]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $workers[] = $row;
        }
    }

    usort($workers, function($a, $b) {
        return $a['distance'] <=> $b['distance'];
    });

    echo json_encode(['success' => true, 'workers' => $workers]);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// CALCULAR TAXA DE ENTREGA
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'calculate_fee') {
    $partner_id = (int)($input['partner_id'] ?? $_GET['partner_id'] ?? 0);
    $lat = (float)($input['lat'] ?? $_GET['lat'] ?? 0);
    $lng = (float)($input['lng'] ?? $_GET['lng'] ?? 0);

    if (!$partner_id || !$lat || !$lng) {
        echo json_encode(['success' => false, 'message' => 'Dados incompletos']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT lat, lng, delivery_fee FROM om_market_partners WHERE partner_id = ?");
    $stmt->execute([$partner_id]);
    $partner = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$partner) {
        echo json_encode(['success' => false, 'message' => 'Mercado nao encontrado']);
        exit;
    }

    // Calcular distancia
    $distance = 6371 * acos(
        cos(deg2rad($partner['lat'])) * cos(deg2rad($lat))
        * cos(deg2rad($lng) - deg2rad($partner['lng']))
        + sin(deg2rad($partner['lat'])) * sin(deg2rad($lat))
    );

    // Taxa base + adicional por km
    $base_fee = (float)$partner['delivery_fee'];
    $per_km = 1.50;

    if ($distance <= 3) {
        $fee = $base_fee;
    } else {
        $fee = $base_fee + (($distance - 3) * $per_km);
    }

    $fee = round($fee, 2);
    $eta = round(($distance / 25) * 60) + 30;

    echo json_encode([
        'success' => true,
        'distance_km' => round($distance, 2),
        'delivery_fee' => $fee,
        'eta_minutes' => $eta
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Acao invalida']);
