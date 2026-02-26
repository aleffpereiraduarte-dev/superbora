<?php
/**
 * ╔══════════════════════════════════════════════════════════════════════════════╗
 * ║  🚗 BORAUM DISPATCH - Automatic Delivery Dispatch                            ║
 * ║  Called automatically when shopper completes shopping                        ║
 * ╠══════════════════════════════════════════════════════════════════════════════╣
 * ║  POST /api/boraum/dispatch.php                                               ║
 * ║  { "order_id": 123 }                                                         ║
 * ╚══════════════════════════════════════════════════════════════════════════════╝
 */

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

try {
    $db = getDB();
    $input = getInput();

    $orderId = intval($input['order_id'] ?? 0);

    if (!$orderId) {
        response(false, null, 'order_id obrigatório', 400);
    }

    // Buscar pedido
    $stmt = $db->prepare("
        SELECT o.*,
               p.name as partner_name, p.address as partner_address,
               p.latitude as partner_lat, p.longitude as partner_lng,
               p.phone as partner_phone
        FROM om_market_orders o
        LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
        WHERE o.order_id = ?
    ");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();

    if (!$order) {
        response(false, null, 'Pedido não encontrado', 404);
    }

    // Verificar se já foi despachado para BoraUm
    if (!empty($order['boraum_pedido_id'])) {
        response(true, [
            'order_id' => $orderId,
            'boraum_id' => $order['boraum_pedido_id'],
            'message' => 'Pedido já despachado para BoraUm'
        ]);
    }

    // Verificar se pedido está pronto para entrega (purchased ou delivering)
    if (!in_array($order['status'], ['purchased', 'delivering'])) {
        response(false, null, 'Pedido não está pronto para entrega. Status: ' . $order['status'], 400);
    }

    // Verificar se API está configurada
    if (empty(BORAUM_API_KEY)) {
        // Salvar flag para tentar depois quando configurado
        $stmt = $db->prepare("UPDATE om_market_orders SET delivery_wave = 1 WHERE order_id = ?");
        $stmt->execute([$orderId]);

        response(false, null, 'BoraUm API não configurada. Pedido marcado para dispatch manual.', 503);
    }

    // Preparar dados para BoraUm
    $deliveryData = [
        'external_id' => $order['order_number'],
        'pickup' => [
            'name' => $order['partner_name'] ?? 'Mercado',
            'address' => $order['partner_address'] ?? '',
            'latitude' => floatval($order['partner_lat'] ?? 0),
            'longitude' => floatval($order['partner_lng'] ?? 0),
            'phone' => $order['partner_phone'] ?? '',
            'instructions' => 'Pedido #' . $order['order_number'] . ' - Já coletado pelo shopper'
        ],
        'dropoff' => [
            'name' => $order['customer_name'] ?? 'Cliente',
            'address' => $order['shipping_address'] ?? $order['delivery_address'] ?? '',
            'latitude' => floatval($order['shipping_latitude'] ?? $order['delivery_lat'] ?? 0),
            'longitude' => floatval($order['shipping_longitude'] ?? $order['delivery_lng'] ?? 0),
            'phone' => $order['customer_phone'] ?? '',
            'instructions' => $order['shipping_instructions'] ?? $order['delivery_instructions'] ?? ''
        ],
        'package' => [
            'description' => 'Compras de mercado - ' . ($order['items_count'] ?? '?') . ' itens',
            'value' => floatval($order['total'] ?? 0),
            'weight' => 5.0, // Peso estimado em kg
            'dimensions' => '40x30x30' // Dimensões estimadas
        ],
        'vehicle_type' => $order['vehicle_required'] ?? BORAUM_VEHICLE_DEFAULT,
        'delivery_code' => $order['delivery_code'] ?? '',
        'webhook_url' => 'https://' . ($_SERVER['HTTP_HOST'] ?? 'onemundo.com') . '/mercado/api/boraum/webhook.php'
    ];

    // Chamar API do BoraUm
    $result = callBoraUmAPI('/deliveries', 'POST', $deliveryData);

    if ($result['success']) {
        $boraumId = $result['data']['id'] ?? null;
        $driverId = $result['data']['driver_id'] ?? null;
        $eta = $result['data']['eta_minutes'] ?? null;

        // Atualizar pedido com ID do BoraUm
        $stmt = $db->prepare("
            UPDATE om_market_orders
            SET boraum_pedido_id = ?,
                delivery_driver_id = ?,
                eta_minutes = ?,
                delivery_dispatched_at = NOW(),
                status = CASE WHEN status = 'purchased' THEN 'delivering' ELSE status END
            WHERE order_id = ?
        ");
        $stmt->execute([$boraumId, $driverId, $eta, $orderId]);

        // Registrar na timeline
        $stmt = $db->prepare("
            INSERT INTO om_order_timeline (order_id, status, title, description, created_at)
            VALUES (?, 'delivering', 'Entrega despachada', 'Pedido enviado para BoraUm. ID: " . $boraumId . "', NOW())
        ");
        $stmt->execute([$orderId]);

        // Log
        logBoraUm('dispatch', $orderId, $deliveryData, $result);

        response(true, [
            'order_id' => $orderId,
            'boraum_id' => $boraumId,
            'driver_id' => $driverId,
            'eta_minutes' => $eta,
            'message' => 'Entrega despachada com sucesso para BoraUm'
        ]);

    } else {
        // Log erro
        logBoraUm('dispatch_error', $orderId, $deliveryData, $result);

        response(false, null, 'Erro ao despachar para BoraUm: ' . ($result['error'] ?? 'Unknown'), 500);
    }

} catch (Exception $e) {
    error_log("BoraUm Dispatch Error: " . $e->getMessage());
    response(false, null, $e->getMessage(), 500);
}

/**
 * Chamar API do BoraUm
 */
function callBoraUmAPI($endpoint, $method = 'GET', $data = null) {
    $url = BORAUM_API_URL . $endpoint;

    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . BORAUM_API_KEY,
        'X-API-Secret: ' . BORAUM_API_SECRET
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    } elseif ($method === 'PUT') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['success' => false, 'error' => $error, 'http_code' => 0];
    }

    $decoded = json_decode($response, true);

    if ($httpCode >= 200 && $httpCode < 300) {
        return ['success' => true, 'data' => $decoded, 'http_code' => $httpCode];
    } else {
        return [
            'success' => false,
            'error' => $decoded['message'] ?? $decoded['error'] ?? 'HTTP ' . $httpCode,
            'data' => $decoded,
            'http_code' => $httpCode
        ];
    }
}

/**
 * Log de operações BoraUm
 */
function logBoraUm($action, $orderId, $request, $response) {
    global $db;

    try {
        $stmt = $db->prepare("
            INSERT INTO om_boraum_logs (order_id, action, request_data, response_data, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $orderId,
            $action,
            json_encode($request),
            json_encode($response)
        ]);
    } catch (Exception $e) {
        error_log("BoraUm Log Error: " . $e->getMessage());
    }
}
