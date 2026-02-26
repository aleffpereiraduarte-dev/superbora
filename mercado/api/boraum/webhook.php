<?php
/**
 * ╔══════════════════════════════════════════════════════════════════════════════╗
 * ║  🚗 BORAUM WEBHOOK - Receive delivery status updates                         ║
 * ╠══════════════════════════════════════════════════════════════════════════════╣
 * ║  POST /api/boraum/webhook.php                                                ║
 * ║  Called by BoraUm when delivery status changes                               ║
 * ╚══════════════════════════════════════════════════════════════════════════════╝
 */

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

// Log all webhooks
$rawInput = file_get_contents('php://input');
error_log("BoraUm Webhook received: " . $rawInput);

try {
    $db = getDB();

    // Verify webhook signature if secret is set
    if (!empty(BORAUM_WEBHOOK_SECRET)) {
        $signature = $_SERVER['HTTP_X_BORAUM_SIGNATURE'] ?? '';
        $expectedSignature = hash_hmac('sha256', $rawInput, BORAUM_WEBHOOK_SECRET);

        if (!hash_equals($expectedSignature, $signature)) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Invalid signature']);
            exit;
        }
    }

    $data = json_decode($rawInput, true);

    if (!$data) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
        exit;
    }

    // Extrair dados do webhook
    $event = $data['event'] ?? $data['type'] ?? '';
    $deliveryId = $data['delivery_id'] ?? $data['id'] ?? '';
    $externalId = $data['external_id'] ?? $data['order_number'] ?? '';
    $status = $data['status'] ?? '';
    $driverData = $data['driver'] ?? [];
    $location = $data['location'] ?? [];

    // Log webhook
    $stmt = $db->prepare("
        INSERT INTO om_boraum_logs (order_id, action, request_data, response_data, created_at)
        VALUES (NULL, 'webhook', ?, ?, NOW())
    ");
    $stmt->execute([$rawInput, json_encode(['event' => $event, 'delivery_id' => $deliveryId])]);

    // Buscar pedido pelo ID do BoraUm ou external_id
    $order = null;

    if ($deliveryId) {
        $stmt = $db->prepare("SELECT * FROM om_market_orders WHERE boraum_pedido_id = ? LIMIT 1");
        $stmt->execute([$deliveryId]);
        $order = $stmt->fetch();
    }

    if (!$order && $externalId) {
        $stmt = $db->prepare("SELECT * FROM om_market_orders WHERE order_number = ? LIMIT 1");
        $stmt->execute([$externalId]);
        $order = $stmt->fetch();
    }

    if (!$order) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Order not found']);
        exit;
    }

    $orderId = $order['order_id'];

    // Mapear status do BoraUm para OneMundo
    global $BORAUM_STATUS_MAP;
    $newStatus = $BORAUM_STATUS_MAP[$status] ?? null;

    // Processar eventos específicos
    switch ($event) {
        case 'delivery.accepted':
        case 'driver_assigned':
            // Motorista aceitou a entrega
            $driverName = $driverData['name'] ?? 'Motorista BoraUm';
            $driverPhone = $driverData['phone'] ?? '';
            $vehiclePlate = $driverData['vehicle_plate'] ?? '';

            $stmt = $db->prepare("
                UPDATE om_market_orders
                SET status = 'delivering',
                    delivery_accepted_at = NOW(),
                    delivery_name = ?,
                    shopper_phone = COALESCE(shopper_phone, ?),
                    updated_at = NOW()
                WHERE order_id = ?
            ");
            $stmt->execute([$driverName, $driverPhone, $orderId]);

            addTimeline($db, $orderId, 'delivering', 'Motorista a caminho',
                "Motorista $driverName aceitou a entrega" . ($vehiclePlate ? " (Placa: $vehiclePlate)" : ''));

            // Notificar cliente
            notifyCustomer($db, $order, 'driver_assigned', [
                'driver_name' => $driverName,
                'eta_minutes' => $data['eta_minutes'] ?? 30
            ]);
            break;

        case 'delivery.picked_up':
        case 'picked_up':
            // Motorista pegou o pedido no mercado
            $stmt = $db->prepare("
                UPDATE om_market_orders
                SET delivery_picked_at = NOW(),
                    updated_at = NOW()
                WHERE order_id = ?
            ");
            $stmt->execute([$orderId]);

            addTimeline($db, $orderId, 'delivering', 'Pedido coletado',
                'Motorista coletou o pedido no mercado');

            notifyCustomer($db, $order, 'picked_up', [
                'eta_minutes' => $data['eta_minutes'] ?? 20
            ]);
            break;

        case 'delivery.in_transit':
        case 'in_transit':
            // Motorista em trânsito
            $stmt = $db->prepare("
                UPDATE om_market_orders
                SET eta_minutes = ?,
                    eta_updated_at = NOW(),
                    updated_at = NOW()
                WHERE order_id = ?
            ");
            $stmt->execute([$data['eta_minutes'] ?? null, $orderId]);

            // Atualizar localização se disponível
            if (!empty($location['latitude']) && !empty($location['longitude'])) {
                $stmt = $db->prepare("
                    UPDATE om_market_orders
                    SET delivery_latitude = ?,
                        delivery_longitude = ?
                    WHERE order_id = ?
                ");
                $stmt->execute([$location['latitude'], $location['longitude'], $orderId]);
            }
            break;

        case 'delivery.arrived':
        case 'arrived':
            // Motorista chegou no destino
            addTimeline($db, $orderId, 'delivering', 'Motorista chegou',
                'O motorista chegou no endereço de entrega');

            notifyCustomer($db, $order, 'arrived', []);
            break;

        case 'delivery.completed':
        case 'delivered':
            // Entrega concluída
            $proofPhoto = $data['proof_photo'] ?? $data['photo_url'] ?? '';
            $signature = $data['signature'] ?? '';

            $stmt = $db->prepare("
                UPDATE om_market_orders
                SET status = 'delivered',
                    delivered_at = NOW(),
                    delivery_photo = ?,
                    delivery_signature = ?,
                    matching_status = 'completed',
                    updated_at = NOW()
                WHERE order_id = ?
            ");
            $stmt->execute([$proofPhoto, $signature, $orderId]);

            addTimeline($db, $orderId, 'delivered', 'Pedido entregue',
                'Entrega concluída com sucesso');

            notifyCustomer($db, $order, 'delivered', []);

            // Criar repasses se ainda não criados
            try {
                $ch = curl_init('http://localhost/mercado/api/financeiro/criar-repasse.php');
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode(['order_id' => $orderId]),
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 5
                ]);
                curl_exec($ch);
                curl_close($ch);
            } catch (Exception $e) {
                error_log("Erro ao criar repasses via webhook: " . $e->getMessage());
            }
            break;

        case 'delivery.cancelled':
        case 'cancelled':
            // Entrega cancelada pelo BoraUm
            $reason = $data['reason'] ?? $data['cancellation_reason'] ?? 'Cancelado pelo sistema de entrega';

            $stmt = $db->prepare("
                UPDATE om_market_orders
                SET boraum_pedido_id = NULL,
                    delivery_wave = delivery_wave + 1,
                    updated_at = NOW()
                WHERE order_id = ?
            ");
            $stmt->execute([$orderId]);

            addTimeline($db, $orderId, 'cancelled', 'Entrega cancelada',
                "Entrega cancelada: $reason. Tentando novo despacho...");

            // Tentar despachar novamente se não excedeu o limite
            if ($order['delivery_wave'] < 3) {
                // Agendar nova tentativa
                error_log("BoraUm: Agendando nova tentativa de dispatch para pedido $orderId");
            }
            break;

        case 'location.updated':
        case 'driver_location':
            // Atualização de localização do motorista
            if (!empty($location['latitude']) && !empty($location['longitude'])) {
                $stmt = $db->prepare("
                    UPDATE om_market_orders
                    SET delivery_latitude = ?,
                        delivery_longitude = ?,
                        eta_minutes = ?,
                        eta_updated_at = NOW()
                    WHERE order_id = ?
                ");
                $stmt->execute([
                    $location['latitude'],
                    $location['longitude'],
                    $data['eta_minutes'] ?? null,
                    $orderId
                ]);
            }
            break;

        default:
            error_log("BoraUm Webhook: Unknown event '$event'");
    }

    echo json_encode(['success' => true, 'order_id' => $orderId, 'event' => $event]);

} catch (Exception $e) {
    error_log("BoraUm Webhook Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

/**
 * Adicionar entrada na timeline
 */
function addTimeline($db, $orderId, $status, $title, $description) {
    try {
        $stmt = $db->prepare("
            INSERT INTO om_order_timeline (order_id, status, title, description, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$orderId, $status, $title, $description]);
    } catch (Exception $e) {
        error_log("Timeline Error: " . $e->getMessage());
    }
}

/**
 * Notificar cliente
 */
function notifyCustomer($db, $order, $type, $data) {
    try {
        $messages = [
            'driver_assigned' => 'Seu pedido está a caminho! Motorista ' . ($data['driver_name'] ?? '') . ' está indo buscar.',
            'picked_up' => 'O motorista coletou seu pedido e está a caminho. Chegada em ~' . ($data['eta_minutes'] ?? 20) . ' min.',
            'arrived' => 'O motorista chegou! Prepare-se para receber seu pedido.',
            'delivered' => 'Seu pedido foi entregue! Obrigado por comprar conosco.'
        ];

        $message = $messages[$type] ?? '';
        if (!$message) return;

        $stmt = $db->prepare("
            INSERT INTO om_notifications (user_type, user_id, title, body, type, data, created_at)
            VALUES ('customer', ?, 'Atualização do Pedido', ?, 'delivery_update', ?, NOW())
        ");
        $stmt->execute([
            $order['customer_id'],
            $message,
            json_encode(['order_id' => $order['order_id'], 'type' => $type])
        ]);
    } catch (Exception $e) {
        error_log("Notify Customer Error: " . $e->getMessage());
    }
}
