<?php
/**
 * API DE MATCHING
 * Motor de distribuicao de ofertas para shoppers e entregadores
 * /mercado/api/matching.php
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);

require_once dirname(__DIR__) . '/config/database.php';
$pdo = getPDO();

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$action = $input['action'] ?? $_GET['action'] ?? '';

// Funcao para obter config
function getConfig($pdo, $key, $default = null) {
    $stmt = $pdo->prepare("SELECT config_value FROM om_matching_config WHERE config_key = ?");
    $stmt->execute([$key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return $row['config_value'];
    }
    return $default;
}

// Funcao para log
function matchingLog($pdo, $order_id, $action, $target_type, $target_id, $wave, $details = '') {
    $stmt = $pdo->prepare("INSERT INTO om_matching_log (order_id, action, target_type, target_id, wave, details)
                  VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$order_id, $action, $target_type, $target_id, $wave, $details]);
}

// ==============================================================================
// CRIAR OFERTAS PARA SHOPPERS
// ==============================================================================
if ($action === 'create_shopper_offers') {
    $order_id = (int)($input['order_id'] ?? 0);

    if (!$order_id) {
        echo json_encode(['success' => false, 'message' => 'order_id obrigatorio']);
        exit;
    }

    // Buscar pedido
    $stmt = $pdo->prepare("SELECT * FROM om_market_orders WHERE order_id = ?");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        echo json_encode(['success' => false, 'message' => 'Pedido nao encontrado']);
        exit;
    }

    if ($order['shopper_id']) {
        echo json_encode(['success' => false, 'message' => 'Pedido ja tem shopper']);
        exit;
    }

    $partner_id = $order['partner_id'];

    // Config
    $wave1_count = (int)getConfig($pdo, 'wave1_count', 3);
    $offer_timeout = (int)getConfig($pdo, 'offer_timeout', 300);
    $commission = (float)getConfig($pdo, 'shopper_commission', 5);

    // Calcular ganho do shopper
    $earning = round($order['subtotal'] * ($commission / 100), 2);
    $earning = max($earning, 5.00); // Minimo R$ 5

    // Contar itens
    $stmt = $pdo->prepare("SELECT COUNT(*) as c FROM om_market_order_items WHERE order_id = ?");
    $stmt->execute([$order_id]);
    $items = $stmt->fetch(PDO::FETCH_ASSOC)['c'];

    // Buscar shoppers online disponiveis
    $delivery_lat = $order['delivery_lat'];
    $delivery_lng = $order['delivery_lng'];

    $stmt = $pdo->prepare("
        SELECT s.shopper_id, s.name, s.rating,
               (6371 * acos(cos(radians(?)) * cos(radians(s.lat))
               * cos(radians(s.lng) - radians(?))
               + sin(radians(?)) * sin(radians(s.lat)))) AS distance
        FROM om_market_shoppers s
        WHERE s.status = 'active'
          AND s.is_online = '1'
          AND s.is_busy = '0'
          AND (s.partner_id IS NULL OR s.partner_id = ?)
          AND s.shopper_id NOT IN (
              SELECT shopper_id FROM om_shopper_offers
              WHERE order_id = ? AND status IN ('pending', 'accepted')
          )
        ORDER BY s.rating DESC, distance ASC
        LIMIT ?
    ");
    $stmt->execute([$delivery_lat, $delivery_lng, $delivery_lat, $partner_id, $order_id, $wave1_count]);
    $shoppers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $created = 0;
    $expires = date('Y-m-d H:i:s', time() + $offer_timeout);

    foreach ($shoppers as $shopper) {
        $sid = $shopper['shopper_id'];

        $stmt = $pdo->prepare("
            INSERT INTO om_shopper_offers
            (order_id, shopper_id, partner_id, earning, total_items, status, current_wave, wave_started_at, expires_at)
            VALUES
            (?, ?, ?, ?, ?, 'pending', 1, NOW(), ?)
        ");
        $stmt->execute([$order_id, $sid, $partner_id, $earning, $items, $expires]);

        matchingLog($pdo, $order_id, 'offer_created', 'shopper', $sid, 1, "Earning: R$ $earning");
        $created++;

        // TODO: Enviar notificacao push para o shopper
    }

    // Atualizar status do pedido
    if ($created > 0) {
        $stmt = $pdo->prepare("UPDATE om_market_orders SET status = 'aguardando_shopper' WHERE order_id = ?");
        $stmt->execute([$order_id]);
    }

    echo json_encode([
        'success' => true,
        'offers_created' => $created,
        'earning' => $earning,
        'expires_at' => $expires
    ]);
    exit;
}

// ==============================================================================
// CRIAR OFERTAS PARA ENTREGADORES
// ==============================================================================
if ($action === 'create_delivery_offers') {
    $order_id = (int)($input['order_id'] ?? 0);

    if (!$order_id) {
        echo json_encode(['success' => false, 'message' => 'order_id obrigatorio']);
        exit;
    }

    // Buscar pedido
    $stmt = $pdo->prepare("SELECT * FROM om_market_orders WHERE order_id = ?");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        echo json_encode(['success' => false, 'message' => 'Pedido nao encontrado']);
        exit;
    }

    if ($order['delivery_id']) {
        echo json_encode(['success' => false, 'message' => 'Pedido ja tem entregador']);
        exit;
    }

    // Buscar mercado para pegar localizacao
    $stmt = $pdo->prepare("SELECT * FROM om_market_partners WHERE partner_id = ?");
    $stmt->execute([$order['partner_id']]);
    $partner = $stmt->fetch(PDO::FETCH_ASSOC);

    // Config
    $wave1_count = (int)getConfig($pdo, 'wave1_count', 3);
    $offer_timeout = (int)getConfig($pdo, 'offer_timeout', 300);
    $base_fee = (float)getConfig($pdo, 'delivery_fee_base', 8.90);
    $per_km = (float)getConfig($pdo, 'delivery_fee_per_km', 1.50);

    // Calcular distancia
    $distance = 0;
    if ($partner && $partner['lat'] && $order['delivery_lat']) {
        $distance = 6371 * acos(
            cos(deg2rad($partner['lat'])) * cos(deg2rad($order['delivery_lat']))
            * cos(deg2rad($order['delivery_lng']) - deg2rad($partner['lng']))
            + sin(deg2rad($partner['lat'])) * sin(deg2rad($order['delivery_lat']))
        );
    }

    // Calcular ganho do entregador
    $earning = $base_fee + ($distance * $per_km);
    $earning = round($earning, 2);

    // Buscar entregadores online disponiveis
    $partner_lat = $partner['lat'];
    $partner_lng = $partner['lng'];

    $stmt = $pdo->prepare("
        SELECT d.delivery_id, d.name, d.rating, d.vehicle_type, d.lat, d.lng,
               (6371 * acos(cos(radians(?)) * cos(radians(d.lat))
               * cos(radians(d.lng) - radians(?))
               + sin(radians(?)) * sin(radians(d.lat)))) AS distance_to_store
        FROM om_market_deliveries d
        WHERE d.status = 'active'
          AND d.is_online = '1'
          AND d.is_busy = '0'
          AND d.delivery_id NOT IN (
              SELECT delivery_id FROM om_delivery_offers
              WHERE order_id = ? AND status IN ('pending', 'accepted')
          )
        ORDER BY distance_to_store ASC, d.rating DESC
        LIMIT ?
    ");
    $stmt->execute([$partner_lat, $partner_lng, $partner_lat, $order_id, $wave1_count]);
    $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $created = 0;
    $expires = date('Y-m-d H:i:s', time() + $offer_timeout);

    foreach ($deliveries as $delivery) {
        $did = $delivery['delivery_id'];

        $stmt = $pdo->prepare("
            INSERT INTO om_delivery_offers
            (order_id, delivery_id, earning, distance_km, vehicle_required,
             pickup_address, pickup_lat, pickup_lng,
             delivery_address, delivery_lat, delivery_lng,
             status, current_wave, wave_started_at, expires_at)
            VALUES
            (?, ?, ?, ?, 'moto',
             ?, ?, ?,
             ?, ?, ?,
             'pending', 1, NOW(), ?)
        ");
        $stmt->execute([
            $order_id, $did, $earning, $distance,
            $partner['address'], $partner['lat'], $partner['lng'],
            $order['delivery_address'], $order['delivery_lat'], $order['delivery_lng'],
            $expires
        ]);

        matchingLog($pdo, $order_id, 'offer_created', 'delivery', $did, 1, "Earning: R$ $earning, Distance: {$distance}km");
        $created++;

        // TODO: Enviar notificacao push
    }

    // Atualizar status do pedido
    if ($created > 0) {
        $stmt = $pdo->prepare("UPDATE om_market_orders SET status = 'aguardando_entregador' WHERE order_id = ?");
        $stmt->execute([$order_id]);
    }

    echo json_encode([
        'success' => true,
        'offers_created' => $created,
        'earning' => $earning,
        'distance_km' => round($distance, 2),
        'expires_at' => $expires
    ]);
    exit;
}

// ==============================================================================
// ACEITAR OFERTA (SHOPPER)
// ==============================================================================
if ($action === 'accept_shopper_offer') {
    $offer_id = (int)($input['offer_id'] ?? 0);
    $shopper_id = (int)($input['shopper_id'] ?? 0);

    if (!$offer_id || !$shopper_id) {
        echo json_encode(['success' => false, 'message' => 'Dados incompletos']);
        exit;
    }

    // Verificar oferta
    $stmt = $pdo->prepare("
        SELECT o.*, ord.status as order_status, ord.shopper_id as current_shopper
        FROM om_shopper_offers o
        JOIN om_market_orders ord ON ord.order_id = o.order_id
        WHERE o.offer_id = ? AND o.shopper_id = ?
    ");
    $stmt->execute([$offer_id, $shopper_id]);
    $offer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$offer) {
        echo json_encode(['success' => false, 'message' => 'Oferta nao encontrada']);
        exit;
    }

    if ($offer['status'] !== 'pending') {
        echo json_encode(['success' => false, 'message' => 'Oferta ja processada']);
        exit;
    }

    if ($offer['current_shopper']) {
        echo json_encode(['success' => false, 'message' => 'Pedido ja aceito por outro shopper']);
        exit;
    }

    if (strtotime($offer['expires_at']) < time()) {
        echo json_encode(['success' => false, 'message' => 'Oferta expirada']);
        exit;
    }

    $order_id = $offer['order_id'];

    // Buscar nome do shopper
    $stmt = $pdo->prepare("SELECT name FROM om_market_shoppers WHERE shopper_id = ?");
    $stmt->execute([$shopper_id]);
    $shopper = $stmt->fetch(PDO::FETCH_ASSOC);

    // Aceitar oferta
    $stmt = $pdo->prepare("UPDATE om_shopper_offers SET status = 'accepted', responded_at = NOW() WHERE offer_id = ?");
    $stmt->execute([$offer_id]);

    // Rejeitar outras ofertas do mesmo pedido
    $stmt = $pdo->prepare("UPDATE om_shopper_offers SET status = 'rejected' WHERE order_id = ? AND offer_id != ? AND status = 'pending'");
    $stmt->execute([$order_id, $offer_id]);

    // Atribuir ao pedido
    $stmt = $pdo->prepare("
        UPDATE om_market_orders
        SET shopper_id = ?,
            shopper_name = ?,
            shopper_earning = ?,
            shopper_accepted_at = NOW(),
            status = 'em_compra'
        WHERE order_id = ?
    ");
    $stmt->execute([$shopper_id, $shopper['name'], $offer['earning'], $order_id]);

    // Marcar shopper como ocupado
    $stmt = $pdo->prepare("UPDATE om_market_shoppers SET is_busy = '1', current_order_id = ? WHERE shopper_id = ?");
    $stmt->execute([$order_id, $shopper_id]);

    matchingLog($pdo, $order_id, 'offer_accepted', 'shopper', $shopper_id, $offer['current_wave'], '');

    echo json_encode([
        'success' => true,
        'message' => 'Oferta aceita!',
        'order_id' => $order_id
    ]);
    exit;
}

// ==============================================================================
// ACEITAR OFERTA (ENTREGADOR)
// ==============================================================================
if ($action === 'accept_delivery_offer') {
    $offer_id = (int)($input['offer_id'] ?? 0);
    $delivery_id = (int)($input['delivery_id'] ?? 0);

    if (!$offer_id || !$delivery_id) {
        echo json_encode(['success' => false, 'message' => 'Dados incompletos']);
        exit;
    }

    // Verificar oferta
    $stmt = $pdo->prepare("
        SELECT o.*, ord.status as order_status, ord.delivery_id as current_delivery
        FROM om_delivery_offers o
        JOIN om_market_orders ord ON ord.order_id = o.order_id
        WHERE o.offer_id = ? AND o.delivery_id = ?
    ");
    $stmt->execute([$offer_id, $delivery_id]);
    $offer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$offer) {
        echo json_encode(['success' => false, 'message' => 'Oferta nao encontrada']);
        exit;
    }

    if ($offer['status'] !== 'pending') {
        echo json_encode(['success' => false, 'message' => 'Oferta ja processada']);
        exit;
    }

    if ($offer['current_delivery']) {
        echo json_encode(['success' => false, 'message' => 'Pedido ja aceito por outro entregador']);
        exit;
    }

    $order_id = $offer['order_id'];

    // Buscar nome do entregador
    $stmt = $pdo->prepare("SELECT name FROM om_market_deliveries WHERE delivery_id = ?");
    $stmt->execute([$delivery_id]);
    $delivery = $stmt->fetch(PDO::FETCH_ASSOC);

    // Aceitar oferta
    $stmt = $pdo->prepare("UPDATE om_delivery_offers SET status = 'accepted', responded_at = NOW() WHERE offer_id = ?");
    $stmt->execute([$offer_id]);

    // Rejeitar outras ofertas
    $stmt = $pdo->prepare("UPDATE om_delivery_offers SET status = 'rejected' WHERE order_id = ? AND offer_id != ? AND status = 'pending'");
    $stmt->execute([$order_id, $offer_id]);

    // Atribuir ao pedido
    $stmt = $pdo->prepare("
        UPDATE om_market_orders
        SET delivery_id = ?,
            delivery_name = ?,
            delivery_earning = ?,
            delivery_accepted_at = NOW(),
            status = 'em_entrega'
        WHERE order_id = ?
    ");
    $stmt->execute([$delivery_id, $delivery['name'], $offer['earning'], $order_id]);

    // Marcar entregador como ocupado
    $stmt = $pdo->prepare("UPDATE om_market_deliveries SET is_busy = '1', current_order_id = ? WHERE delivery_id = ?");
    $stmt->execute([$order_id, $delivery_id]);

    matchingLog($pdo, $order_id, 'offer_accepted', 'delivery', $delivery_id, $offer['current_wave'], '');

    echo json_encode([
        'success' => true,
        'message' => 'Oferta aceita!',
        'order_id' => $order_id
    ]);
    exit;
}

// ==============================================================================
// REJEITAR OFERTA
// ==============================================================================
if ($action === 'reject_offer') {
    $offer_id = (int)($input['offer_id'] ?? 0);
    $type = $input['type'] ?? 'shopper'; // shopper ou delivery

    if (!$offer_id) {
        echo json_encode(['success' => false, 'message' => 'offer_id obrigatorio']);
        exit;
    }

    $table = $type === 'delivery' ? 'om_delivery_offers' : 'om_shopper_offers';
    $id_field = $type === 'delivery' ? 'delivery_id' : 'shopper_id';

    $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE offer_id = ?");
    $stmt->execute([$offer_id]);
    $offer = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($offer && $offer['status'] === 'pending') {
        $stmt = $pdo->prepare("UPDATE {$table} SET status = 'rejected', responded_at = NOW() WHERE offer_id = ?");
        $stmt->execute([$offer_id]);
        matchingLog($pdo, $offer['order_id'], 'offer_rejected', $type, $offer[$id_field], $offer['current_wave'], '');
    }

    echo json_encode(['success' => true]);
    exit;
}

// ==============================================================================
// BUSCAR OFERTAS PENDENTES
// ==============================================================================
if ($action === 'get_pending_offers') {
    $type = $input['type'] ?? $_GET['type'] ?? 'shopper';
    $user_id = (int)($input['user_id'] ?? $_GET['user_id'] ?? 0);

    if (!$user_id) {
        echo json_encode(['success' => false, 'message' => 'user_id obrigatorio']);
        exit;
    }

    if ($type === 'delivery') {
        $stmt = $pdo->prepare("
            SELECT o.*, ord.order_number, ord.customer_name,
                   p.name as partner_name, p.address as partner_address
            FROM om_delivery_offers o
            JOIN om_market_orders ord ON ord.order_id = o.order_id
            LEFT JOIN om_market_partners p ON p.partner_id = ord.partner_id
            WHERE o.delivery_id = ?
              AND o.status = 'pending'
              AND o.expires_at > NOW()
            ORDER BY o.created_at DESC
        ");
        $stmt->execute([$user_id]);
    } else {
        $stmt = $pdo->prepare("
            SELECT o.*, ord.order_number, ord.customer_name, ord.delivery_address,
                   p.name as partner_name, p.address as partner_address
            FROM om_shopper_offers o
            JOIN om_market_orders ord ON ord.order_id = o.order_id
            LEFT JOIN om_market_partners p ON p.partner_id = ord.partner_id
            WHERE o.shopper_id = ?
              AND o.status = 'pending'
              AND o.expires_at > NOW()
            ORDER BY o.created_at DESC
        ");
        $stmt->execute([$user_id]);
    }

    $offers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'offers' => $offers]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Acao invalida']);
