<?php
/**
 * GET /api/mercado/tracking/live.php?order_id=X
 * Retorna posicao atual do shopper + ETA para rastreamento em tempo real
 */

require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Autenticacao necessaria", 401);
    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== 'customer') response(false, null, "Token invalido", 401);
    $customerId = (int)$payload['uid'];

    $orderId = (int)($_GET['order_id'] ?? 0);
    if (!$orderId) response(false, null, "order_id obrigatorio", 400);

    // Buscar pedido e validar ownership
    $stmt = $db->prepare("
        SELECT o.order_id, o.status, o.shopper_id, o.shipping_lat, o.shipping_lng,
               o.delivery_address, o.partner_id,
               p.latitude AS partner_lat, p.longitude AS partner_lng, p.name AS partner_name
        FROM om_market_orders o
        LEFT JOIN om_market_partners p ON p.partner_id = o.partner_id
        WHERE o.order_id = ? AND o.customer_id = ?
    ");
    $stmt->execute([$orderId, $customerId]);
    $order = $stmt->fetch();

    if (!$order) response(false, null, "Pedido nao encontrado", 404);

    $data = [
        'order_id' => (int)$order['order_id'],
        'status' => $order['status'],
        'delivery_lat' => (float)$order['shipping_lat'],
        'delivery_lng' => (float)$order['shipping_lng'],
        'delivery_address' => $order['delivery_address'],
        'partner_lat' => (float)$order['partner_lat'],
        'partner_lng' => (float)$order['partner_lng'],
        'partner_name' => $order['partner_name'],
        'driver' => null,
        'eta_minutes' => null
    ];

    // Se tem shopper atribuido, buscar posicao
    if ($order['shopper_id']) {
        $stmt2 = $db->prepare("
            SELECT s.shopper_id, s.latitude, s.longitude, s.ultima_atividade,
                   s.nome AS driver_name, s.foto AS driver_photo,
                   s.telefone AS driver_phone, s.veiculo_tipo AS vehicle_type,
                   s.veiculo_placa AS vehicle_plate
            FROM om_market_shoppers s
            WHERE s.shopper_id = ?
        ");
        $stmt2->execute([$order['shopper_id']]);
        $shopper = $stmt2->fetch();

        if ($shopper) {
            $data['driver'] = [
                'name' => $shopper['driver_name'],
                'photo' => $shopper['driver_photo'],
                'phone' => $shopper['driver_phone'],
                'vehicle_type' => $shopper['vehicle_type'] ?: 'moto',
                'vehicle_plate' => $shopper['vehicle_plate'],
                'lat' => (float)$shopper['latitude'],
                'lng' => (float)$shopper['longitude'],
                'updated_at' => $shopper['ultima_atividade']
            ];

            // Calcular ETA baseado na posicao atual do shopper
            if ($shopper['latitude'] && $order['shipping_lat']) {
                $R = 6371;
                $dLat = deg2rad($order['shipping_lat'] - $shopper['latitude']);
                $dLng = deg2rad($order['shipping_lng'] - $shopper['longitude']);
                $a = sin($dLat / 2) ** 2 + cos(deg2rad($shopper['latitude'])) * cos(deg2rad($order['shipping_lat'])) * sin($dLng / 2) ** 2;
                $dist = $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
                $data['eta_minutes'] = max(2, (int)round($dist * 4));
            }
        }

        // Tambem buscar delivery tracking
        $stmt3 = $db->prepare("
            SELECT last_lat, last_lng, last_location_at
            FROM om_market_delivery_tracking
            WHERE order_id = ?
            ORDER BY last_location_at DESC
            LIMIT 1
        ");
        $stmt3->execute([$orderId]);
        $tracking = $stmt3->fetch();

        if ($tracking && $tracking['last_lat']) {
            $data['driver']['lat'] = (float)$tracking['last_lat'];
            $data['driver']['lng'] = (float)$tracking['last_lng'];
            $data['driver']['updated_at'] = $tracking['last_location_at'];
        }
    }

    response(true, $data);

} catch (Exception $e) {
    error_log("[tracking/live] Erro: " . $e->getMessage());
    response(false, null, "Erro ao buscar tracking", 500);
}
