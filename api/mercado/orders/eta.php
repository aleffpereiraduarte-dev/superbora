<?php
/**
 * GET /api/mercado/orders/eta.php?order_id=X
 * Calcula ETA inteligente baseado em distancia + preparo
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

    // Buscar pedido
    $stmt = $db->prepare("
        SELECT o.order_id, o.status, o.partner_id, o.shipping_lat, o.shipping_lng,
               o.accepted_at, o.date_added, o.is_pickup,
               p.latitude AS partner_lat, p.longitude AS partner_lng,
               p.delivery_time_min, p.delivery_time_max, p.name AS partner_name
        FROM om_market_orders o
        LEFT JOIN om_market_partners p ON p.partner_id = o.partner_id
        WHERE o.order_id = ? AND o.customer_id = ?
    ");
    $stmt->execute([$orderId, $customerId]);
    $order = $stmt->fetch();

    if (!$order) response(false, null, "Pedido nao encontrado", 404);

    $status = $order['status'];

    // Statuses finais nao tem ETA
    if (in_array($status, ['entregue', 'cancelado', 'cancelled'])) {
        response(true, ['eta_minutes' => 0, 'status' => $status, 'message' => 'Pedido finalizado']);
    }

    // Calcular distancia Haversine entre loja e cliente
    $distKm = 0;
    if ($order['partner_lat'] && $order['partner_lng'] && $order['shipping_lat'] && $order['shipping_lng']) {
        $distKm = haversineDistance(
            (float)$order['partner_lat'], (float)$order['partner_lng'],
            (float)$order['shipping_lat'], (float)$order['shipping_lng']
        );
    }

    // Tempo de entrega baseado em distancia (4 min/km, velocidade media moto/bike)
    $deliveryTime = round($distKm * 4);
    if ($deliveryTime < 5) $deliveryTime = 5; // minimo 5 min

    // Tempo de preparo estimado
    $prepTimeMin = (int)($order['delivery_time_min'] ?? 15);
    $prepTimeMax = (int)($order['delivery_time_max'] ?? 30);
    $prepTimeAvg = round(($prepTimeMin + $prepTimeMax) / 2);

    $prepRemaining = $prepTimeAvg;
    $etaMinutes = 0;

    switch ($status) {
        case 'pending':
        case 'pendente':
            // Ainda nao aceito: preparo completo + entrega
            $etaMinutes = $prepTimeAvg + $deliveryTime + 5; // +5 min buffer aceitacao
            $prepRemaining = $prepTimeAvg;
            break;

        case 'aceito':
        case 'accepted':
        case 'confirmado':
            // Aceito mas nao comecou preparo: preparo completo + entrega
            $etaMinutes = $prepTimeAvg + $deliveryTime;
            $prepRemaining = $prepTimeAvg;
            break;

        case 'preparando':
        case 'preparing':
            // Em preparo: estimar quanto falta do preparo
            if ($order['accepted_at']) {
                $elapsed = max(0, (time() - strtotime($order['accepted_at'])) / 60);
                $prepRemaining = max(2, $prepTimeAvg - $elapsed);
            } else {
                $prepRemaining = round($prepTimeAvg * 0.5);
            }
            $etaMinutes = round($prepRemaining) + $deliveryTime;
            break;

        case 'pronto':
        case 'ready':
            // Pronto para coleta: so tempo de entrega + margem shopper aceitar
            $prepRemaining = 0;
            $etaMinutes = $deliveryTime + 5;
            break;

        case 'coletando':
        case 'collecting':
            // Shopper indo ate a loja
            $prepRemaining = 0;
            $etaMinutes = $deliveryTime + 3;
            break;

        case 'em_entrega':
        case 'delivering':
        case 'saiu_entrega':
            // Em transito: so tempo de viagem
            $prepRemaining = 0;
            // Tentar usar posicao real do shopper
            $stmt2 = $db->prepare("
                SELECT latitude, longitude FROM om_market_shoppers
                WHERE shopper_id = (SELECT shopper_id FROM om_market_orders WHERE order_id = ?)
            ");
            $stmt2->execute([$orderId]);
            $shopper = $stmt2->fetch();
            if ($shopper && $shopper['latitude'] && $order['shipping_lat']) {
                $shopperDist = haversineDistance(
                    (float)$shopper['latitude'], (float)$shopper['longitude'],
                    (float)$order['shipping_lat'], (float)$order['shipping_lng']
                );
                $etaMinutes = max(2, round($shopperDist * 4));
            } else {
                $etaMinutes = $deliveryTime;
            }
            break;

        default:
            $etaMinutes = $prepTimeAvg + $deliveryTime;
    }

    if ($order['is_pickup']) {
        $etaMinutes = $prepRemaining;
        $deliveryTime = 0;
    }

    response(true, [
        'eta_minutes' => (int)round($etaMinutes),
        'prep_time_remaining' => (int)round($prepRemaining),
        'delivery_time' => (int)round($deliveryTime),
        'distance_km' => round($distKm, 1),
        'status' => $status,
        'is_pickup' => (bool)$order['is_pickup']
    ]);

} catch (Exception $e) {
    error_log("[orders/eta] Erro: " . $e->getMessage());
    response(false, null, "Erro ao calcular ETA", 500);
}

/**
 * Calcula distancia entre 2 pontos usando formula de Haversine
 * @return float Distancia em km
 */
function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $R = 6371; // Raio da Terra em km
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) * sin($dLat / 2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLng / 2) * sin($dLng / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $R * $c;
}
