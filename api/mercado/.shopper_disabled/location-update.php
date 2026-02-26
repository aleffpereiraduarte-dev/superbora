<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * POST /api/mercado/shopper/location-update.php
 * Atualiza localizacao do entregador em tempo real com Pusher
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * REQUER: Autenticacao de Shopper
 * Header: Authorization: Bearer <token>
 *
 * Body: {
 *   "latitude": -23.5505,
 *   "longitude": -46.6333,
 *   "order_id": 123,       // Opcional - se informado, dispara evento Pusher
 *   "heading": 90,         // Opcional - direcao em graus (0-360)
 *   "speed": 25.5,         // Opcional - velocidade em km/h
 *   "accuracy": 10.0       // Opcional - precisao GPS em metros
 * }
 *
 * Response: {
 *   "success": true,
 *   "data": {
 *     "location_id": 123,
 *     "eta_minutes": 8,
 *     "distance_km": 2.3,
 *     "pusher_sent": true
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
    // VALIDACAO DE ENTRADA
    // ═══════════════════════════════════════════════════════════════════
    $lat = $input["latitude"] ?? $input["lat"] ?? null;
    $lng = $input["longitude"] ?? $input["lng"] ?? null;
    $order_id = isset($input["order_id"]) ? (int)$input["order_id"] : null;
    $heading = isset($input["heading"]) ? (int)$input["heading"] : null;
    $speed = isset($input["speed"]) ? (float)$input["speed"] : null;
    $accuracy = isset($input["accuracy"]) ? (float)$input["accuracy"] : null;

    if ($lat === null || $lng === null) {
        response(false, null, "latitude e longitude sao obrigatorios", 400);
    }

    $lat = floatval($lat);
    $lng = floatval($lng);

    // Validar range de coordenadas
    if ($lat < -90 || $lat > 90) {
        response(false, null, "Latitude invalida. Deve estar entre -90 e 90", 400);
    }

    if ($lng < -180 || $lng > 180) {
        response(false, null, "Longitude invalida. Deve estar entre -180 e 180", 400);
    }

    // Validar heading (0-360)
    if ($heading !== null && ($heading < 0 || $heading > 360)) {
        $heading = null;
    }

    // Validar speed (positivo)
    if ($speed !== null && $speed < 0) {
        $speed = null;
    }

    // ═══════════════════════════════════════════════════════════════════
    // ATUALIZAR LOCALIZACAO DO SHOPPER
    // ═══════════════════════════════════════════════════════════════════
    $stmt = $db->prepare("
        UPDATE om_market_shoppers SET
            latitude = ?,
            longitude = ?,
            ultima_atividade = NOW()
        WHERE shopper_id = ?
    ");
    $stmt->execute([$lat, $lng, $shopper_id]);

    $responseData = [
        'latitude' => $lat,
        'longitude' => $lng,
        'updated_at' => date('c'),
        'pusher_sent' => false
    ];

    // ═══════════════════════════════════════════════════════════════════
    // SE TEM ORDER_ID, SALVAR HISTORICO E DISPARAR PUSHER
    // ═══════════════════════════════════════════════════════════════════
    if ($order_id) {
        // Verificar se o pedido pertence a este shopper
        $stmt = $db->prepare("
            SELECT o.order_id, o.status, o.shipping_lat, o.shipping_lng,
                   o.customer_id, o.partner_id,
                   p.latitude AS partner_lat, p.longitude AS partner_lng
            FROM om_market_orders o
            LEFT JOIN om_market_partners p ON p.partner_id = o.partner_id
            WHERE o.order_id = ?
            AND o.shopper_id = ?
            AND o.status IN ('aceito', 'coletando', 'coleta_finalizada', 'em_entrega')
        ");
        $stmt->execute([$order_id, $shopper_id]);
        $order = $stmt->fetch();

        if ($order) {
            // Inserir no historico de localizacoes
            $stmt = $db->prepare("
                INSERT INTO om_delivery_locations
                (order_id, worker_id, latitude, longitude, heading, speed, accuracy)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$order_id, $shopper_id, $lat, $lng, $heading, $speed, $accuracy]);
            $location_id = $db->lastInsertId();

            // Calcular distancia e ETA
            $dest_lat = (float)$order['shipping_lat'];
            $dest_lng = (float)$order['shipping_lng'];

            $distance_km = null;
            $eta_minutes = null;

            if ($dest_lat && $dest_lng) {
                // Formula de Haversine
                $R = 6371; // Raio da Terra em km
                $dLat = deg2rad($dest_lat - $lat);
                $dLng = deg2rad($dest_lng - $lng);
                $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat)) * cos(deg2rad($dest_lat)) * sin($dLng / 2) ** 2;
                $distance_km = round($R * 2 * atan2(sqrt($a), sqrt(1 - $a)), 2);

                // ETA baseado em velocidade media de 15 km/h em cidade
                // ou velocidade atual se disponivel
                $avg_speed = ($speed && $speed > 5) ? $speed : 15;
                $eta_minutes = max(1, (int)round(($distance_km / $avg_speed) * 60));
            }

            // Determinar status baseado na distancia
            $tracking_status = 'em_entrega';
            if ($order['status'] === 'coletando') {
                $tracking_status = 'coletando';
            } elseif ($distance_km !== null && $distance_km < 0.3) {
                $tracking_status = 'chegando';
            }

            // Atualizar/inserir tracking live
            $stmt = $db->prepare("
                INSERT INTO om_delivery_tracking_live
                (order_id, worker_id, latitude, longitude, heading, speed, accuracy, eta_minutes, distance_km, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    latitude = VALUES(latitude),
                    longitude = VALUES(longitude),
                    heading = VALUES(heading),
                    speed = VALUES(speed),
                    accuracy = VALUES(accuracy),
                    eta_minutes = VALUES(eta_minutes),
                    distance_km = VALUES(distance_km),
                    status = VALUES(status),
                    updated_at = NOW()
            ");
            $stmt->execute([
                $order_id, $shopper_id, $lat, $lng,
                $heading, $speed, $accuracy,
                $eta_minutes, $distance_km, $tracking_status
            ]);

            // Tambem atualizar om_market_delivery_tracking (tabela existente)
            $stmt = $db->prepare("
                INSERT INTO om_market_delivery_tracking (order_id, shopper_id, last_lat, last_lng, last_location_at)
                VALUES (?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    last_lat = VALUES(last_lat),
                    last_lng = VALUES(last_lng),
                    last_location_at = NOW()
            ");
            $stmt->execute([$order_id, $shopper_id, $lat, $lng]);

            // ═══════════════════════════════════════════════════════════════════
            // DISPARAR EVENTO PUSHER PARA O CANAL DO PEDIDO
            // ═══════════════════════════════════════════════════════════════════
            try {
                $pusher = PusherService::getInstance();
                $channel = "order-{$order_id}";
                $event = 'location-update';

                $pusherData = [
                    'order_id' => $order_id,
                    'driver' => [
                        'id' => $shopper_id,
                        'lat' => $lat,
                        'lng' => $lng,
                        'heading' => $heading,
                        'speed' => $speed
                    ],
                    'eta_minutes' => $eta_minutes,
                    'distance_km' => $distance_km,
                    'status' => $tracking_status,
                    'timestamp' => date('c')
                ];

                $pusherSent = $pusher->trigger($channel, $event, $pusherData);

                // Se estiver chegando (< 300m), enviar notificacao especial
                if ($tracking_status === 'chegando') {
                    $pusher->trigger($channel, 'driver-arriving', [
                        'order_id' => $order_id,
                        'message' => 'O entregador esta chegando!',
                        'eta_minutes' => $eta_minutes,
                        'timestamp' => date('c')
                    ]);
                }

                $responseData['pusher_sent'] = $pusherSent;

            } catch (Exception $e) {
                error_log("[location-update] Pusher error: " . $e->getMessage());
                $responseData['pusher_sent'] = false;
            }

            $responseData['location_id'] = (int)$location_id;
            $responseData['order_id'] = $order_id;
            $responseData['eta_minutes'] = $eta_minutes;
            $responseData['distance_km'] = $distance_km;
            $responseData['status'] = $tracking_status;
        }
    } else {
        // Se nao tem order_id, verificar se tem pedido ativo e salvar tracking
        $stmt = $db->prepare("
            SELECT order_id, status, delivery_address
            FROM om_market_orders
            WHERE shopper_id = ?
            AND status IN ('aceito', 'coletando', 'coleta_finalizada', 'em_entrega')
            LIMIT 1
        ");
        $stmt->execute([$shopper_id]);
        $pedido = $stmt->fetch();

        if ($pedido) {
            $stmt = $db->prepare("
                INSERT INTO om_market_delivery_tracking (order_id, shopper_id, last_lat, last_lng, last_location_at)
                VALUES (?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    last_lat = VALUES(last_lat),
                    last_lng = VALUES(last_lng),
                    last_location_at = NOW()
            ");
            $stmt->execute([$pedido['order_id'], $shopper_id, $lat, $lng]);

            $responseData['active_order'] = [
                'order_id' => (int)$pedido['order_id'],
                'status' => $pedido['status']
            ];
        }
    }

    response(true, $responseData, "Localizacao atualizada!");

} catch (Exception $e) {
    error_log("[location-update] Erro: " . $e->getMessage());
    response(false, null, "Erro ao atualizar localizacao", 500);
}
