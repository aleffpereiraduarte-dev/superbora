<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * POST /api/mercado/shopper/start-tracking.php
 * Inicia o tracking de um pedido (chamar quando aceitar ou iniciar entrega)
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * REQUER: Autenticacao de Shopper
 * Header: Authorization: Bearer <token>
 *
 * Body: {
 *   "order_id": 123,
 *   "latitude": -23.5505,    // Posicao inicial
 *   "longitude": -46.6333
 * }
 *
 * Response: {
 *   "success": true,
 *   "data": {
 *     "tracking_started": true,
 *     "pusher_channel": "order-123"
 *   }
 * }
 */

require_once __DIR__ . "/../config/auth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/PusherService.php";

setCorsHeaders();

try {
    $input = getInput();
    $db = getDB();

    // ═══════════════════════════════════════════════════════════════════
    // AUTENTICACAO
    // ═══════════════════════════════════════════════════════════════════
    $auth = requireShopperAuth();
    $shopper_id = $auth['uid'];

    // ═══════════════════════════════════════════════════════════════════
    // VALIDACAO
    // ═══════════════════════════════════════════════════════════════════
    $order_id = isset($input["order_id"]) ? (int)$input["order_id"] : null;
    $lat = isset($input["latitude"]) ? floatval($input["latitude"]) : null;
    $lng = isset($input["longitude"]) ? floatval($input["longitude"]) : null;

    if (!$order_id) {
        response(false, null, "order_id e obrigatorio", 400);
    }

    // Verificar se o pedido pertence a este shopper
    $stmt = $db->prepare("
        SELECT o.order_id, o.status, o.shipping_lat, o.shipping_lng,
               o.customer_id, o.partner_id, o.order_number,
               p.latitude AS partner_lat, p.longitude AS partner_lng, p.name AS partner_name
        FROM om_market_orders o
        LEFT JOIN om_market_partners p ON p.partner_id = o.partner_id
        WHERE o.order_id = ?
        AND o.shopper_id = ?
    ");
    $stmt->execute([$order_id, $shopper_id]);
    $order = $stmt->fetch();

    if (!$order) {
        response(false, null, "Pedido nao encontrado ou nao atribuido a voce", 404);
    }

    // ═══════════════════════════════════════════════════════════════════
    // INICIAR TRACKING
    // ═══════════════════════════════════════════════════════════════════
    $tracking_status = 'coletando';
    if (in_array($order['status'], ['coleta_finalizada', 'em_entrega'])) {
        $tracking_status = 'em_entrega';
    }

    // Calcular ETA e distancia
    $eta_minutes = null;
    $distance_km = null;

    if ($lat && $lng && $order['shipping_lat']) {
        // Calcular distancia ate o destino
        $R = 6371;
        $dLat = deg2rad($order['shipping_lat'] - $lat);
        $dLng = deg2rad($order['shipping_lng'] - $lng);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat)) * cos(deg2rad($order['shipping_lat'])) * sin($dLng / 2) ** 2;
        $distance_km = round($R * 2 * atan2(sqrt($a), sqrt(1 - $a)), 2);
        $eta_minutes = max(1, (int)round($distance_km * 4)); // ~15 km/h media
    }

    // Inserir/atualizar tracking live
    $stmt = $db->prepare("
        INSERT INTO om_delivery_tracking_live
        (order_id, worker_id, latitude, longitude, eta_minutes, distance_km, status)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            latitude = VALUES(latitude),
            longitude = VALUES(longitude),
            eta_minutes = VALUES(eta_minutes),
            distance_km = VALUES(distance_km),
            status = VALUES(status),
            updated_at = NOW()
    ");
    $stmt->execute([
        $order_id, $shopper_id,
        $lat ?? 0, $lng ?? 0,
        $eta_minutes, $distance_km,
        $tracking_status
    ]);

    // Tambem criar registro inicial no historico
    if ($lat && $lng) {
        $stmt = $db->prepare("
            INSERT INTO om_delivery_locations
            (order_id, worker_id, latitude, longitude)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$order_id, $shopper_id, $lat, $lng]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // NOTIFICAR CLIENTE VIA PUSHER
    // ═══════════════════════════════════════════════════════════════════
    try {
        // Buscar dados do shopper
        $stmt = $db->prepare("
            SELECT nome, foto, veiculo, placa, cor_veiculo, avaliacao_media
            FROM om_market_shoppers
            WHERE shopper_id = ?
        ");
        $stmt->execute([$shopper_id]);
        $shopper = $stmt->fetch();

        // Enviar status update
        PusherService::orderStatusUpdate($order_id, $tracking_status, getStatusLabel($tracking_status));

        // Se tem localizacao, enviar tambem
        if ($lat && $lng) {
            PusherService::locationUpdate($order_id, [
                'driver_id' => $shopper_id,
                'lat' => $lat,
                'lng' => $lng,
                'eta_minutes' => $eta_minutes,
                'distance_km' => $distance_km,
                'status' => $tracking_status
            ]);
        }

    } catch (Exception $e) {
        error_log("[start-tracking] Pusher error: " . $e->getMessage());
    }

    response(true, [
        'tracking_started' => true,
        'order_id' => $order_id,
        'pusher_channel' => "order-{$order_id}",
        'initial_status' => $tracking_status,
        'eta_minutes' => $eta_minutes,
        'distance_km' => $distance_km
    ], "Tracking iniciado!");

} catch (Exception $e) {
    error_log("[start-tracking] Erro: " . $e->getMessage());
    response(false, null, "Erro ao iniciar tracking", 500);
}

function getStatusLabel(string $status): string {
    $labels = [
        'coletando' => 'Entregador coletando',
        'em_entrega' => 'A caminho',
        'chegando' => 'Chegando!'
    ];
    return $labels[$status] ?? ucfirst(str_replace('_', ' ', $status));
}
