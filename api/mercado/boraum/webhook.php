<?php
/**
 * POST /api/mercado/boraum/webhook.php
 * Webhook que o BoraUm chama para atualizar status de entregas.
 *
 * Auth: Header X-Webhook-Secret (shared secret)
 *
 * Body: {
 *   "event": "driver_accepted|driver_arrived_pickup|picked_up|driver_arrived_dropoff|delivered|cancelled|driver_chat|driver_location|driver_arrived_stop",
 *   "delivery_id": 123,
 *   "external_id": "SB-456",
 *   "driver": {
 *     "id": 10,
 *     "name": "Joao Silva",
 *     "phone": "11999998888",
 *     "photo": "https://...",
 *     "vehicle": "Moto",
 *     "plate": "ABC-1234",
 *     "lat": -23.55,
 *     "lng": -46.63
 *   },
 *   "timestamp": "2026-02-03T16:00:00-03:00",
 *   "reason": "motivo do cancelamento (se event=cancelled)"
 * }
 *
 * Fluxo de status:
 *   driver_accepted         → aguardando_entregador (motorista aceitou, indo buscar)
 *   driver_arrived_pickup   → (motorista chegou na loja, aguardando handoff)
 *   picked_up               → em_entrega (motorista retirou, saiu pra entregar)
 *   driver_arrived_dropoff  → (motorista chegou no cliente)
 *   delivered               → delivered (entrega confirmada)
 *   cancelled               → (motorista cancelou, precisa redespachar)
 *   driver_location         → (localizacao GPS a cada ~30s, broadcast via WS)
 *   driver_arrived_stop     → (motorista chegou em parada de rota multi-stop)
 */

// Load env manually (avoid rate limiting for webhooks)
if (file_exists(__DIR__ . '/../../../.env')) {
    $envFile = file(__DIR__ . '/../../../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($envFile as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($value);
        }
    }
}

require_once __DIR__ . '/../config/database.php';

// CORS - Restrict to specific BoraUm webhook origins
header('Content-Type: application/json; charset=utf-8');

// SECURITY: Only allow specific origins for webhook (BoraUm servers)
$allowedWebhookOrigins = array_filter(array_map('trim',
    explode(',', $_ENV['BORAUM_WEBHOOK_ORIGINS'] ?? 'https://api.boraum.com.br,https://webhook.boraum.com.br')
));

$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';

// For webhooks, we're more restrictive - only allow known BoraUm origins
// If no origin header (server-to-server), that's acceptable for webhooks
if (!empty($requestOrigin)) {
    if (in_array($requestOrigin, $allowedWebhookOrigins, true)) {
        header('Access-Control-Allow-Origin: ' . $requestOrigin);
    } else {
        // Log but don't block - webhooks may come from various IPs
        error_log("[boraum-webhook] Unknown origin: " . $requestOrigin);
    }
}
// No Access-Control-Allow-Origin header if origin is empty (server-to-server calls)

header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Webhook-Secret');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    // =========================================================================
    // 1. Validar webhook secret (REQUIRED - must be configured)
    // =========================================================================
    $webhookSecret = $_ENV['BORAUM_WEBHOOK_SECRET'] ?? '';
    $receivedSecret = $_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? '';

    // SECURITY: Webhook secret MUST be configured - reject if empty
    if (empty($webhookSecret)) {
        error_log("[boraum-webhook] CRITICAL: BORAUM_WEBHOOK_SECRET not configured in environment");
        http_response_code(503);
        echo json_encode(['success' => false, 'message' => 'Webhook not configured']);
        exit;
    }

    // Validate received secret matches expected (timing-safe comparison)
    if (empty($receivedSecret) || !hash_equals($webhookSecret, $receivedSecret)) {
        error_log("[boraum-webhook] Secret invalido. Recebido: " . (empty($receivedSecret) ? '(empty)' : substr($receivedSecret, 0, 10) . "..."));
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    // =========================================================================
    // 2. Parsear body
    // =========================================================================
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);

    if (!$input || empty($input['event'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid payload']);
        exit;
    }

    $event = $input['event'];
    $deliveryId = $input['delivery_id'] ?? null;
    $externalId = $input['external_id'] ?? '';
    $driver = $input['driver'] ?? [];
    $timestamp = $input['timestamp'] ?? date('c');
    $reason = $input['reason'] ?? '';

    // SECURITY: Validate timestamp is within reasonable range (prevent replay attacks)
    // Allow timestamps from 5 minutes in the past to 1 minute in the future
    $tsUnix = strtotime($timestamp);
    if ($tsUnix === false) {
        error_log("[boraum-webhook] Timestamp invalido/unparseable: $timestamp");
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid timestamp format']);
        exit;
    }

    $now = time();
    $maxAge = 5 * 60; // 5 minutes
    $maxFuture = 60;  // 1 minute

    if ($tsUnix < ($now - $maxAge)) {
        error_log("[boraum-webhook] Timestamp muito antigo: $timestamp (diff=" . ($now - $tsUnix) . "s)");
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Timestamp expired']);
        exit;
    }

    if ($tsUnix > ($now + $maxFuture)) {
        error_log("[boraum-webhook] Timestamp no futuro: $timestamp (diff=" . ($tsUnix - $now) . "s)");
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Timestamp in future']);
        exit;
    }

    // Fotos e codigos de verificacao
    $pickupPhoto = $input['pickup_photo'] ?? null;
    $pickupCode = $input['pickup_code'] ?? null;
    $deliveryPhoto = $input['delivery_photo'] ?? null;
    $deliveryCode = $input['delivery_code'] ?? null;

    error_log("[boraum-webhook] Evento: $event | delivery_id: $deliveryId | external_id: $externalId");

    $db = getDB();

    // =========================================================================
    // 3. Localizar pedido(s) pelo external_id ou delivery_id
    //    Route deliveries: external_id = "ROUTE-{id}", multiple entregas share boraum_delivery_id
    //    Single deliveries: external_id = order_number (e.g. SB00456)
    // =========================================================================
    $entrega = null;
    $orderId = null;
    $isRouteDelivery = false;
    $routeId = null;
    $allRouteEntregas = []; // All entregas for this route delivery

    // Check if this is a route delivery by external_id format
    if ($externalId && preg_match('/^ROUTE-(\d+)$/', $externalId, $routeMatch)) {
        $isRouteDelivery = true;
        $routeId = (int)$routeMatch[1];
        error_log("[boraum-webhook] Detected route delivery: route_id=$routeId");
    }

    if ($deliveryId) {
        // For route deliveries, there are MULTIPLE entregas with the same boraum_delivery_id
        $stmt = $db->prepare("SELECT * FROM om_entregas WHERE boraum_delivery_id = ? ORDER BY id ASC");
        $stmt->execute([$deliveryId]);
        $allRouteEntregas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($allRouteEntregas)) {
            $entrega = $allRouteEntregas[0]; // Primary entrega for single-order events
            if (count($allRouteEntregas) > 1) {
                $isRouteDelivery = true;
                $routeId = $routeId ?: (int)($entrega['route_id'] ?? 0);
            }
        }
    }

    if (!$entrega && $externalId && !$isRouteDelivery) {
        // Single order: external_id = order_number (ex: SB-456)
        $stmt = $db->prepare("SELECT order_id FROM om_market_orders WHERE order_number = ? LIMIT 1");
        $stmt->execute([$externalId]);
        $order = $stmt->fetch();
        if ($order) {
            $orderId = (int)$order['order_id'];
            $stmt = $db->prepare("SELECT * FROM om_entregas WHERE referencia_id = ? AND origem_sistema = 'mercado' LIMIT 1");
            $stmt->execute([$orderId]);
            $entrega = $stmt->fetch();
            if ($entrega) {
                $allRouteEntregas = [$entrega];
            }
        }
    }

    if (!$entrega && $isRouteDelivery && $routeId) {
        // Route delivery but no entrega found by delivery_id — try by route_id
        $stmt = $db->prepare("SELECT * FROM om_entregas WHERE route_id = ? AND origem_sistema = 'mercado' ORDER BY id ASC");
        $stmt->execute([$routeId]);
        $allRouteEntregas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($allRouteEntregas)) {
            $entrega = $allRouteEntregas[0];
        }
    }

    if (!$entrega) {
        error_log("[boraum-webhook] Entrega nao encontrada para delivery_id=$deliveryId, external_id=$externalId");
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Delivery not found']);
        exit;
    }

    $orderId = $orderId ?: (int)($entrega['referencia_id'] ?? 0);

    // Buscar pedido (primary order for the entrega)
    $stmt = $db->prepare("SELECT * FROM om_market_orders WHERE order_id = ?");
    $stmt->execute([$orderId]);
    $pedido = $stmt->fetch();

    if (!$pedido) {
        error_log("[boraum-webhook] Pedido #$orderId nao encontrado");
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Order not found']);
        exit;
    }

    // For route deliveries, identify which specific stop this event is for
    // BoraUm may send stop_external_id (order_number) or stop_sequence in the payload
    $stopExternalId = $input['stop_external_id'] ?? null;
    $stopSequence = isset($input['stop_sequence']) ? (int)$input['stop_sequence'] : null;

    // =========================================================================
    // 4. Processar evento
    // =========================================================================
    $validEvents = ['driver_accepted', 'driver_arrived_pickup', 'picked_up', 'driver_arrived_dropoff', 'delivered', 'cancelled', 'driver_chat', 'driver_location', 'driver_arrived_stop'];
    if (!in_array($event, $validEvents)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Unknown event: $event"]);
        exit;
    }

    // ── Evento especial: mensagem do motorista no chat ──
    if ($event === 'driver_chat') {
        $chatMessage = strip_tags(trim($input['message'] ?? ''));
        $chatImage = trim($input['image_url'] ?? '') ?: null;
        // Validate image URL if provided (prevent javascript: or data: URLs)
        if ($chatImage && !preg_match('#^https?://#i', $chatImage)) {
            $chatImage = null;
        }
        $chatDriverName = $driver['name'] ?? 'Entregador';
        $chatDriverId = isset($driver['id']) ? (int)$driver['id'] : 0;

        if (empty($chatMessage) && empty($chatImage)) {
            echo json_encode(['success' => false, 'message' => 'message or image_url required']);
            exit;
        }

        $msgType = $chatImage ? 'image' : 'text';

        $db->prepare("
            INSERT INTO om_order_chat (order_id, sender_type, sender_id, sender_name, message, message_type, image_url, chat_type, is_read, created_at)
            VALUES (?, 'delivery', ?, ?, ?, ?, ?, 'delivery', 0, NOW())
        ")->execute([
            $orderId, $chatDriverId, $chatDriverName,
            $chatMessage ?: '[Imagem]', $msgType, $chatImage
        ]);

        // Notificar cliente via WhatsApp
        try {
            $stmtPhone = $db->prepare("SELECT customer_phone FROM om_market_orders WHERE order_id = ?");
            $stmtPhone->execute([$orderId]);
            $phone = $stmtPhone->fetchColumn();
            if ($phone && !empty($chatMessage)) {
                require_once __DIR__ . '/../helpers/zapi-whatsapp.php';
                sendWhatsApp($phone, "Mensagem do entregador: " . $chatMessage);
            }
        } catch (Exception $e) {}

        echo json_encode(['success' => true, 'message' => 'Chat message saved', 'order_id' => $orderId]);
        exit;
    }

    // ── Evento especial: localizacao do motorista (alta frequencia, sem transacao) ──
    if ($event === 'driver_location') {
        $locLat = isset($driver['lat']) ? (float)$driver['lat'] : null;
        $locLng = isset($driver['lng']) ? (float)$driver['lng'] : null;
        $locDriverName = $driver['name'] ?? 'Entregador';

        if (!$locLat || !$locLng) {
            echo json_encode(['success' => false, 'message' => 'driver.lat and driver.lng required']);
            exit;
        }

        // Update om_entregas with current driver coordinates
        try {
            $db->prepare("
                UPDATE om_entregas SET
                    motorista_lat = ?,
                    motorista_lng = ?,
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$locLat, $locLng, $entrega['id']]);
        } catch (Exception $e) {
            // Column may not exist yet — log and continue with tracking + broadcast
            error_log("[boraum-webhook] driver_location update om_entregas failed (columns may not exist): " . $e->getMessage());
        }

        // Insert into tracking history
        try {
            $db->prepare("
                INSERT INTO om_delivery_tracking (delivery_id, order_id, latitude, longitude, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ")->execute([$entrega['id'], $orderId, $locLat, $locLng]);
        } catch (Exception $e) {
            error_log("[boraum-webhook] driver_location tracking insert failed: " . $e->getMessage());
        }

        // Broadcast to customer and order channel via WebSocket
        try {
            require_once __DIR__ . '/../helpers/ws-customer-broadcast.php';

            $customerId = (int)$pedido['customer_id'];
            $locationPayload = [
                'order_id' => $orderId,
                'driver' => [
                    'lat' => $locLat,
                    'lng' => $locLng,
                    'name' => $locDriverName,
                ],
            ];

            wsBroadcastToCustomer($customerId, 'driver_location', $locationPayload);
            wsBroadcastToOrder($orderId, 'driver_location', $locationPayload);

            // For route deliveries, broadcast to all customers in the route
            if ($isRouteDelivery && count($allRouteEntregas) > 1) {
                $notifiedCustomers = [$customerId];
                foreach ($allRouteEntregas as $re) {
                    $reOrderId = (int)$re['referencia_id'];
                    if ($reOrderId === $orderId) continue;
                    $stmtPed = $db->prepare("SELECT customer_id FROM om_market_orders WHERE order_id = ?");
                    $stmtPed->execute([$reOrderId]);
                    $rePedido = $stmtPed->fetch();
                    if ($rePedido) {
                        $cid = (int)$rePedido['customer_id'];
                        if (!in_array($cid, $notifiedCustomers)) {
                            $routeLocationPayload = $locationPayload;
                            $routeLocationPayload['order_id'] = $reOrderId;
                            wsBroadcastToCustomer($cid, 'driver_location', $routeLocationPayload);
                            $notifiedCustomers[] = $cid;
                        }
                    }
                    wsBroadcastToOrder($reOrderId, 'driver_location', array_merge($locationPayload, ['order_id' => $reOrderId]));
                }
            }
        } catch (Exception $e) {
            error_log("[boraum-webhook] driver_location WS broadcast failed: " . $e->getMessage());
        }

        echo json_encode(['success' => true, 'message' => 'Location updated', 'order_id' => $orderId]);
        exit;
    }

    $driverId = isset($driver['id']) ? (int)$driver['id'] : null;
    $driverName = $driver['name'] ?? null;
    $driverPhone = $driver['phone'] ?? null;
    $driverPhoto = $driver['photo'] ?? null;
    $driverPlate = $driver['plate'] ?? null;
    $driverLat = isset($driver['lat']) ? (float)$driver['lat'] : null;
    $driverLng = isset($driver['lng']) ? (float)$driver['lng'] : null;

    $db->beginTransaction();

    try {
        // Re-fetch order AND entrega with FOR UPDATE locks inside transaction to prevent race conditions
        $stmt = $db->prepare("SELECT * FROM om_market_orders WHERE order_id = ? FOR UPDATE");
        $stmt->execute([$orderId]);
        $pedido = $stmt->fetch();
        if (!$pedido) {
            $db->rollBack();
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Order not found']);
            exit;
        }

        // Re-fetch entrega with FOR UPDATE to prevent stale data from concurrent webhooks
        $stmt = $db->prepare("SELECT * FROM om_entregas WHERE id = ? FOR UPDATE");
        $stmt->execute([$entrega['id']]);
        $entrega = $stmt->fetch();
        if (!$entrega) {
            $db->rollBack();
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Delivery record not found']);
            exit;
        }

        switch ($event) {
            // -----------------------------------------------------------------
            case 'driver_accepted':
                // Motorista aceitou a corrida, indo buscar na loja
                if ($isRouteDelivery && count($allRouteEntregas) > 1) {
                    // Route delivery: update ALL entregas and orders in the route
                    foreach ($allRouteEntregas as $re) {
                        $db->prepare("
                            UPDATE om_entregas SET
                                boraum_status = 'accepted',
                                driver_id = ?,
                                motorista_nome = ?,
                                motorista_telefone = ?,
                                motorista_foto = ?,
                                motorista_veiculo = ?,
                                status = 'motorista_aceito',
                                updated_at = NOW()
                            WHERE id = ?
                        ")->execute([$driverId, $driverName, $driverPhone, $driverPhoto, $driverPlate ?? '', $re['id']]);

                        $reOrderId = (int)$re['referencia_id'];
                        $db->prepare("
                            UPDATE om_market_orders SET
                                driver_id = ?,
                                driver_name = ?,
                                driver_phone = ?,
                                driver_photo = ?,
                                delivery_accepted_at = NOW(),
                                updated_at = NOW(),
                                date_modified = NOW()
                            WHERE order_id = ? AND status NOT IN ('entregue', 'retirado', 'cancelado')
                        ")->execute([$driverId, $driverName, $driverPhone, $driverPhoto, $reOrderId]);

                        logEvent($db, $reOrderId, 'driver_accepted', "Motorista $driverName aceitou a entrega (rota #$routeId)");
                    }

                    // Notify all distinct customers in the route
                    $notifiedCustomers = [];
                    foreach ($allRouteEntregas as $re) {
                        $reOrderId = (int)$re['referencia_id'];
                        $stmtPed = $db->prepare("SELECT * FROM om_market_orders WHERE order_id = ?");
                        $stmtPed->execute([$reOrderId]);
                        $rePedido = $stmtPed->fetch();
                        if ($rePedido) {
                            $cid = (int)$rePedido['customer_id'];
                            if (!in_array($cid, $notifiedCustomers)) {
                                notifyCustomer($db, $rePedido, 'Entregador a caminho!', "O motorista $driverName esta indo buscar seus pedidos (rota com " . count($allRouteEntregas) . " paradas).");
                                $notifiedCustomers[] = $cid;
                            }
                        }
                    }

                    // Update route status
                    if ($routeId) {
                        $db->prepare("UPDATE om_delivery_routes SET boraum_status = 'accepted', status = 'in_progress' WHERE route_id = ?")->execute([$routeId]);
                    }
                } else {
                    // Single order delivery
                    $db->prepare("
                        UPDATE om_entregas SET
                            boraum_status = 'accepted',
                            driver_id = ?,
                            motorista_nome = ?,
                            motorista_telefone = ?,
                            motorista_foto = ?,
                            motorista_veiculo = ?,
                            status = 'motorista_aceito',
                            updated_at = NOW()
                        WHERE id = ?
                    ")->execute([$driverId, $driverName, $driverPhone, $driverPhoto, $driverPlate ?? '', $entrega['id']]);

                    $db->prepare("
                        UPDATE om_market_orders SET
                            driver_id = ?,
                            driver_name = ?,
                            driver_phone = ?,
                            driver_photo = ?,
                            delivery_accepted_at = NOW(),
                            updated_at = NOW(),
                            date_modified = NOW()
                        WHERE order_id = ?
                    ")->execute([$driverId, $driverName, $driverPhone, $driverPhoto, $orderId]);

                    logEvent($db, $orderId, 'driver_accepted', "Motorista $driverName aceitou a entrega");
                    notifyCustomer($db, $pedido, 'Entregador a caminho!', "O motorista $driverName esta indo buscar seu pedido.");
                }
                break;

            // -----------------------------------------------------------------
            case 'driver_arrived_pickup':
                // Motorista chegou na loja
                // For route: identify which stop the driver arrived at
                if ($isRouteDelivery && $stopExternalId) {
                    // Find the specific entrega for this stop
                    $stmtStopOrder = $db->prepare("SELECT order_id FROM om_market_orders WHERE order_number = ? LIMIT 1");
                    $stmtStopOrder->execute([$stopExternalId]);
                    $stopOrder = $stmtStopOrder->fetch();
                    if ($stopOrder) {
                        $stopOrderId = (int)$stopOrder['order_id'];
                        $db->prepare("
                            UPDATE om_entregas SET boraum_status = 'arrived_pickup', status = 'motorista_no_local', updated_at = NOW()
                            WHERE referencia_id = ? AND route_id = ?
                        ")->execute([$stopOrderId, $routeId]);
                        logEvent($db, $stopOrderId, 'driver_arrived_pickup', "Motorista chegou no estabelecimento (rota #$routeId)");

                        // Update route stop status
                        $db->prepare("UPDATE om_delivery_route_stops SET status = 'driver_arrived', arrived_at = NOW() WHERE route_id = ? AND order_id = ?")
                           ->execute([$routeId, $stopOrderId]);

                        // Notify customer for this specific stop
                        $stmtStopPed = $db->prepare("SELECT * FROM om_market_orders WHERE order_id = ?");
                        $stmtStopPed->execute([$stopOrderId]);
                        $stopPedido = $stmtStopPed->fetch();
                        if ($stopPedido) {
                            notifyCustomer($db, $stopPedido, 'Motorista chegou na loja!', "O motorista $driverName chegou no estabelecimento para retirar seu pedido #{$stopPedido['order_number']}.");
                        }
                    }
                } else {
                    $db->prepare("
                        UPDATE om_entregas SET boraum_status = 'arrived_pickup', status = 'motorista_no_local', updated_at = NOW()
                        WHERE id = ?
                    ")->execute([$entrega['id']]);

                    logEvent($db, $orderId, 'driver_arrived_pickup', "Motorista chegou no estabelecimento");

                    // Send push notification to customer
                    notifyCustomer($db, $pedido, 'Motorista chegou na loja!', "O motorista $driverName chegou no estabelecimento para retirar seu pedido.");

                    // If route delivery without stop info, update all
                    if ($isRouteDelivery && $routeId) {
                        foreach ($allRouteEntregas as $re) {
                            if ((int)$re['id'] !== (int)$entrega['id']) {
                                $db->prepare("
                                    UPDATE om_entregas SET boraum_status = 'arrived_pickup', status = 'motorista_no_local', updated_at = NOW()
                                    WHERE id = ? AND boraum_status NOT IN ('in_transit', 'delivered')
                                ")->execute([$re['id']]);
                            }
                        }
                    }
                }

                // WebSocket broadcast for driver_arrived_pickup
                try {
                    require_once __DIR__ . '/../helpers/ws-customer-broadcast.php';
                    $wsPayload = [
                        'order_id' => $orderId,
                        'status' => 'driver_arrived_pickup',
                        'driver_name' => $driverName ?? 'Entregador',
                    ];
                    wsBroadcastToCustomer((int)$pedido['customer_id'], 'order_update', $wsPayload);
                    wsBroadcastToOrder($orderId, 'order_update', $wsPayload);
                } catch (Exception $e) {
                    error_log("[boraum-webhook] driver_arrived_pickup WS broadcast failed: " . $e->getMessage());
                }
                break;

            // -----------------------------------------------------------------
            case 'driver_arrived_stop':
                // Motorista chegou em uma parada especifica da rota (multi-stop)
                $arrivedStopOrderId = null;
                $arrivedStopPedido = null;

                // Identify which stop the driver arrived at
                if ($stopExternalId) {
                    $stmtStopOrd = $db->prepare("SELECT order_id FROM om_market_orders WHERE order_number = ? LIMIT 1");
                    $stmtStopOrd->execute([$stopExternalId]);
                    $stopOrd = $stmtStopOrd->fetch();
                    if ($stopOrd) {
                        $arrivedStopOrderId = (int)$stopOrd['order_id'];
                    }
                } elseif ($stopSequence !== null && $routeId) {
                    $stmtStop = $db->prepare("SELECT order_id FROM om_delivery_route_stops WHERE route_id = ? AND COALESCE(stop_sequence, stop_order) = ? LIMIT 1");
                    $stmtStop->execute([$routeId, $stopSequence]);
                    $stopMatch = $stmtStop->fetch();
                    if ($stopMatch) {
                        $arrivedStopOrderId = (int)$stopMatch['order_id'];
                    }
                }

                if ($arrivedStopOrderId) {
                    // Update the specific entrega for this stop
                    $db->prepare("
                        UPDATE om_entregas SET boraum_status = 'arrived_stop', updated_at = NOW()
                        WHERE referencia_id = ? AND origem_sistema = 'mercado'
                          AND boraum_status NOT IN ('delivered', 'in_transit')
                    ")->execute([$arrivedStopOrderId]);

                    // Update route stop
                    if ($routeId) {
                        $db->prepare("
                            UPDATE om_delivery_route_stops SET status = 'driver_arrived', arrived_at = NOW()
                            WHERE route_id = ? AND order_id = ?
                        ")->execute([$routeId, $arrivedStopOrderId]);
                    }

                    logEvent($db, $arrivedStopOrderId, 'driver_arrived_stop', "Motorista chegou na parada" . ($routeId ? " (rota #$routeId)" : ""));

                    // Notify the customer for this stop
                    $stmtStopPed = $db->prepare("SELECT * FROM om_market_orders WHERE order_id = ?");
                    $stmtStopPed->execute([$arrivedStopOrderId]);
                    $arrivedStopPedido = $stmtStopPed->fetch();
                    if ($arrivedStopPedido) {
                        notifyCustomer($db, $arrivedStopPedido, 'Entregador na parada!', "O motorista " . ($driverName ?? 'Entregador') . " chegou na parada do seu pedido #{$arrivedStopPedido['order_number']}.");

                        // WebSocket broadcast
                        try {
                            require_once __DIR__ . '/../helpers/ws-customer-broadcast.php';
                            $cid = (int)$arrivedStopPedido['customer_id'];
                            $wsPayload = [
                                'order_id' => $arrivedStopOrderId,
                                'order_number' => $arrivedStopPedido['order_number'],
                                'status' => 'driver_arrived_stop',
                                'driver_name' => $driverName ?? 'Entregador',
                                'route_id' => $routeId,
                            ];
                            wsBroadcastToCustomer($cid, 'order_update', $wsPayload);
                            wsBroadcastToOrder($arrivedStopOrderId, 'order_update', $wsPayload);
                        } catch (Exception $e) {
                            error_log("[boraum-webhook] driver_arrived_stop WS broadcast failed: " . $e->getMessage());
                        }
                    }
                } else {
                    // No specific stop identified — log and acknowledge
                    error_log("[boraum-webhook] driver_arrived_stop: could not identify stop (stop_external_id=$stopExternalId, stop_sequence=" . ($stopSequence ?? 'null') . ", route_id=" . ($routeId ?? 'null') . ")");
                    logEvent($db, $orderId, 'driver_arrived_stop', "Motorista chegou em uma parada (parada nao identificada)");
                }
                break;

            // -----------------------------------------------------------------
            case 'picked_up':
                // Motorista retirou o pedido de uma parada

                if ($isRouteDelivery) {
                    // ========================================================
                    // ROUTE PICKUP: Identify which stop was picked up
                    // ========================================================
                    $pickedUpEntrega = $entrega; // default to first
                    $pickedUpOrderId = $orderId;
                    $pickedUpPedido = $pedido;

                    // Try to find the specific stop entrega
                    if ($stopExternalId) {
                        $stmtStopOrd = $db->prepare("SELECT order_id FROM om_market_orders WHERE order_number = ? LIMIT 1");
                        $stmtStopOrd->execute([$stopExternalId]);
                        $stopOrd = $stmtStopOrd->fetch();
                        if ($stopOrd) {
                            $pickedUpOrderId = (int)$stopOrd['order_id'];
                            foreach ($allRouteEntregas as $re) {
                                if ((int)$re['referencia_id'] === $pickedUpOrderId) {
                                    $pickedUpEntrega = $re;
                                    break;
                                }
                            }
                            $stmtPed = $db->prepare("SELECT * FROM om_market_orders WHERE order_id = ? FOR UPDATE");
                            $stmtPed->execute([$pickedUpOrderId]);
                            $pickedUpPedido = $stmtPed->fetch() ?: $pedido;
                        }
                    } elseif ($stopSequence !== null) {
                        // Find by stop_sequence
                        foreach ($allRouteEntregas as $re) {
                            if ((int)($re['route_stop_id'] ?? 0)) {
                                $stmtStop = $db->prepare("SELECT order_id FROM om_delivery_route_stops WHERE stop_id = ? AND COALESCE(stop_sequence, stop_order) = ?");
                                $stmtStop->execute([$re['route_stop_id'], $stopSequence]);
                                $stopMatch = $stmtStop->fetch();
                                if ($stopMatch) {
                                    $pickedUpOrderId = (int)$stopMatch['order_id'];
                                    $pickedUpEntrega = $re;
                                    $stmtPed = $db->prepare("SELECT * FROM om_market_orders WHERE order_id = ? FOR UPDATE");
                                    $stmtPed->execute([$pickedUpOrderId]);
                                    $pickedUpPedido = $stmtPed->fetch() ?: $pedido;
                                    break;
                                }
                            }
                        }
                    }

                    // Validate pickup code against THIS specific stop's entrega
                    $codeValid = false;
                    if ($pickupCode && !empty($pickedUpEntrega['qr_coleta'])) {
                        $codeValid = strtoupper($pickupCode) === strtoupper($pickedUpEntrega['qr_coleta']);
                        if (!$codeValid) {
                            error_log("[boraum-webhook] Route pickup codigo INVALIDO! stop_order=$pickedUpOrderId Esperado={$pickedUpEntrega['qr_coleta']} Recebido=$pickupCode");
                        }
                    }

                    // Update THIS stop's entrega
                    $updateFields = "boraum_status = 'in_transit', status = 'em_transito', coletado_em = NOW(), updated_at = NOW()";
                    $params = [];
                    if ($pickupPhoto) { $updateFields .= ", foto_coleta = ?"; $params[] = $pickupPhoto; }
                    if ($codeValid) { $updateFields .= ", codigo_coleta_validado = 1"; }
                    $params[] = $pickedUpEntrega['id'];
                    $db->prepare("UPDATE om_entregas SET $updateFields WHERE id = ?")->execute($params);

                    // Mark THIS order as em_entrega (picked up from this store)
                    $db->prepare("
                        UPDATE om_market_orders SET
                            status = 'em_entrega',
                            started_delivery_at = NOW(),
                            picked_up_at = NOW(),
                            updated_at = NOW(),
                            date_modified = NOW()
                        WHERE order_id = ? AND status NOT IN ('entregue', 'retirado', 'cancelado')
                    ")->execute([$pickedUpOrderId]);

                    // Update route stop as completed
                    if ($routeId) {
                        $db->prepare("
                            UPDATE om_delivery_route_stops SET status = 'picked_up', completed_at = NOW()
                            WHERE route_id = ? AND order_id = ?
                        ")->execute([$routeId, $pickedUpOrderId]);
                    }

                    $pickupMsg = "Motorista retirou o pedido (rota #$routeId, parada #{$pickedUpOrderId})";
                    if ($codeValid) $pickupMsg .= " (codigo verificado)";
                    if ($pickupPhoto) $pickupMsg .= " (foto registrada)";
                    logEvent($db, $pickedUpOrderId, 'picked_up', $pickupMsg);

                    // Count how many stops are left
                    $stmtRemaining = $db->prepare("
                        SELECT COUNT(*) FROM om_delivery_route_stops
                        WHERE route_id = ? AND status NOT IN ('picked_up', 'completed', 'cancelled')
                    ");
                    $stmtRemaining->execute([$routeId]);
                    $remainingStops = (int)$stmtRemaining->fetchColumn();

                    $stmtTotal = $db->prepare("
                        SELECT COUNT(*) FROM om_delivery_route_stops
                        WHERE route_id = ? AND status NOT IN ('cancelled')
                    ");
                    $stmtTotal->execute([$routeId]);
                    $totalStops = (int)$stmtTotal->fetchColumn();

                    $pickedUpCount = $totalStops - $remainingStops;

                    $notifBody = "Pedido #{$pickedUpPedido['order_number']} coletado pelo motorista $driverName.";
                    if ($remainingStops > 0) {
                        $notifBody .= " Faltam $remainingStops de $totalStops paradas.";
                    } else {
                        $notifBody .= " Todas as paradas coletadas! A caminho da entrega.";
                    }
                    if ($codeValid) $notifBody .= "\n Codigo verificado.";
                    notifyCustomer($db, $pickedUpPedido, 'Pedido coletado!', $notifBody);

                    if ($pickupPhoto) {
                        sendPhotoToCustomer($pickedUpPedido, $pickupPhoto, "Foto da coleta do pedido #{$pickedUpPedido['order_number']}");
                    }

                    error_log("[boraum-webhook] Route #$routeId picked_up: order #$pickedUpOrderId ($pickedUpCount/$totalStops coletados, $remainingStops restantes)");
                } else {
                    // ========================================================
                    // SINGLE ORDER PICKUP (original logic)
                    // ========================================================
                    $codeValid = false;
                    if ($pickupCode && !empty($entrega['qr_coleta'])) {
                        $codeValid = strtoupper($pickupCode) === strtoupper($entrega['qr_coleta']);
                        if (!$codeValid) {
                            error_log("[boraum-webhook] Codigo coleta INVALIDO! Esperado={$entrega['qr_coleta']} Recebido=$pickupCode");
                        }
                    }

                    $updateFields = "boraum_status = 'in_transit', status = 'em_transito', coletado_em = NOW(), updated_at = NOW()";
                    $params = [];
                    if ($pickupPhoto) { $updateFields .= ", foto_coleta = ?"; $params[] = $pickupPhoto; }
                    if ($codeValid) { $updateFields .= ", codigo_coleta_validado = 1"; }
                    $params[] = $entrega['id'];
                    $db->prepare("UPDATE om_entregas SET $updateFields WHERE id = ?")->execute($params);

                    $stmtPickup = $db->prepare("
                        UPDATE om_market_orders SET
                            status = 'em_entrega',
                            started_delivery_at = NOW(),
                            picked_up_at = NOW(),
                            updated_at = NOW(),
                            date_modified = NOW()
                        WHERE order_id = ? AND status NOT IN ('entregue', 'retirado', 'cancelado')
                    ");
                    $stmtPickup->execute([$orderId]);

                    $pickupMsg = "Motorista retirou o pedido";
                    if ($codeValid) $pickupMsg .= " (codigo verificado)";
                    if ($pickupPhoto) $pickupMsg .= " (foto registrada)";
                    logEvent($db, $orderId, 'picked_up', $pickupMsg);

                    $notifBody = "Seu pedido saiu para entrega com o motorista $driverName.";
                    if ($pickupPhoto) { $notifBody .= "\n\nFoto da coleta registrada."; }
                    if ($codeValid) { $notifBody .= "\nCodigo verificado com sucesso."; }
                    notifyCustomer($db, $pedido, 'Pedido a caminho!', $notifBody);

                    if ($pickupPhoto) {
                        sendPhotoToCustomer($pedido, $pickupPhoto, "Foto da coleta do seu pedido #{$pedido['order_number']}");
                    }
                }
                break;

            // -----------------------------------------------------------------
            case 'driver_arrived_dropoff':
                // Motorista chegou no endereco do cliente
                if ($isRouteDelivery && count($allRouteEntregas) > 1) {
                    // Route: update all entregas for this route
                    foreach ($allRouteEntregas as $re) {
                        $db->prepare("
                            UPDATE om_entregas SET boraum_status = 'arrived_dropoff', updated_at = NOW()
                            WHERE id = ? AND boraum_status NOT IN ('delivered')
                        ")->execute([$re['id']]);
                    }
                    // Notify all customers in the route
                    $notifiedCustomers = [];
                    foreach ($allRouteEntregas as $re) {
                        $reOrderId = (int)$re['referencia_id'];
                        $stmtPed = $db->prepare("SELECT * FROM om_market_orders WHERE order_id = ?");
                        $stmtPed->execute([$reOrderId]);
                        $rePedido = $stmtPed->fetch();
                        if ($rePedido) {
                            $cid = (int)$rePedido['customer_id'];
                            if (!in_array($cid, $notifiedCustomers)) {
                                notifyCustomer($db, $rePedido, 'Entregador chegou!', "O motorista $driverName chegou no seu endereco com seus pedidos.");
                                $notifiedCustomers[] = $cid;
                            }
                            logEvent($db, $reOrderId, 'driver_arrived_dropoff', "Motorista chegou no endereco de entrega (rota #$routeId)");
                        }
                    }
                } else {
                    $db->prepare("
                        UPDATE om_entregas SET boraum_status = 'arrived_dropoff', updated_at = NOW()
                        WHERE id = ?
                    ")->execute([$entrega['id']]);
                    logEvent($db, $orderId, 'driver_arrived_dropoff', "Motorista chegou no endereco de entrega");
                    notifyCustomer($db, $pedido, 'Entregador chegou!', "O motorista $driverName chegou no seu endereco.");
                }

                // WebSocket broadcast for driver_arrived_dropoff
                try {
                    require_once __DIR__ . '/../helpers/ws-customer-broadcast.php';
                    $wsPayload = [
                        'order_id' => $orderId,
                        'status' => 'driver_arrived_dropoff',
                        'driver_name' => $driverName ?? 'Entregador',
                    ];
                    wsBroadcastToCustomer((int)$pedido['customer_id'], 'order_update', $wsPayload);
                    wsBroadcastToOrder($orderId, 'order_update', $wsPayload);

                    // For route deliveries, broadcast to all customers
                    if ($isRouteDelivery && count($allRouteEntregas) > 1) {
                        $wsBroadcastedCustomers = [(int)$pedido['customer_id']];
                        foreach ($allRouteEntregas as $re) {
                            $reOrderId = (int)$re['referencia_id'];
                            if ($reOrderId === $orderId) continue;
                            $stmtWsPed = $db->prepare("SELECT customer_id FROM om_market_orders WHERE order_id = ?");
                            $stmtWsPed->execute([$reOrderId]);
                            $wsRow = $stmtWsPed->fetch();
                            if ($wsRow) {
                                $wsCid = (int)$wsRow['customer_id'];
                                if (!in_array($wsCid, $wsBroadcastedCustomers)) {
                                    wsBroadcastToCustomer($wsCid, 'order_update', array_merge($wsPayload, ['order_id' => $reOrderId]));
                                    $wsBroadcastedCustomers[] = $wsCid;
                                }
                            }
                            wsBroadcastToOrder($reOrderId, 'order_update', array_merge($wsPayload, ['order_id' => $reOrderId]));
                        }
                    }
                } catch (Exception $e) {
                    error_log("[boraum-webhook] driver_arrived_dropoff WS broadcast failed: " . $e->getMessage());
                }
                break;

            // -----------------------------------------------------------------
            case 'delivered':
                // Entrega confirmada pelo motorista

                // Geofence: verify driver is near delivery address (max 500m)
                $deliveryLat = isset($pedido['latitude_entrega']) ? (float)$pedido['latitude_entrega'] : null;
                $deliveryLng = isset($pedido['longitude_entrega']) ? (float)$pedido['longitude_entrega'] : null;

                // For route deliveries, use route customer coords if order coords missing
                if ($isRouteDelivery && !$deliveryLat && !$deliveryLng && $routeId) {
                    $stmtRouteCoords = $db->prepare("SELECT customer_lat, customer_lng FROM om_delivery_routes WHERE route_id = ?");
                    $stmtRouteCoords->execute([$routeId]);
                    $routeCoords = $stmtRouteCoords->fetch();
                    if ($routeCoords) {
                        $deliveryLat = (float)($routeCoords['customer_lat'] ?? 0) ?: null;
                        $deliveryLng = (float)($routeCoords['customer_lng'] ?? 0) ?: null;
                    }
                }

                $geofenceValid = false;
                if ($driverLat && $driverLng && $deliveryLat && $deliveryLng) {
                    // Haversine formula
                    $earthRadius = 6371000; // meters
                    $dLat = deg2rad($deliveryLat - $driverLat);
                    $dLng = deg2rad($deliveryLng - $driverLng);
                    $a = sin($dLat / 2) * sin($dLat / 2) +
                         cos(deg2rad($driverLat)) * cos(deg2rad($deliveryLat)) *
                         sin($dLng / 2) * sin($dLng / 2);
                    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
                    $distanceMeters = $earthRadius * $c;

                    if ($distanceMeters > 500) {
                        error_log("[boraum-webhook] Geofence REJEITADO: motorista a {$distanceMeters}m do endereco de entrega (order_id=$orderId)");
                        $db->rollBack();
                        http_response_code(400);
                        echo json_encode([
                            'success' => false,
                            'message' => "Motorista muito longe do endereco de entrega ({$distanceMeters}m). Maximo: 500m.",
                            'distance_meters' => round($distanceMeters, 1)
                        ]);
                        exit;
                    }
                    $geofenceValid = true;
                } else {
                    error_log("[boraum-webhook] AVISO: Coordenadas ausentes para geofence (order_id=$orderId) — exigindo codigo de entrega");
                }

                // Validar codigo de entrega (for routes, validate against ANY entrega's pin)
                $codeValid = false;
                if ($deliveryCode) {
                    if (!empty($entrega['pin_entrega']) && $deliveryCode === $entrega['pin_entrega']) {
                        $codeValid = true;
                    } elseif ($isRouteDelivery) {
                        // Try all entregas in route for pin match
                        foreach ($allRouteEntregas as $re) {
                            if (!empty($re['pin_entrega']) && $deliveryCode === $re['pin_entrega']) {
                                $codeValid = true;
                                break;
                            }
                        }
                    }
                    if (!$codeValid) {
                        error_log("[boraum-webhook] Codigo entrega INVALIDO! Recebido=$deliveryCode (order_id=$orderId)");
                    }
                }

                // SECURITY: Se geofence nao validou, exigir codigo de entrega valido
                if (!$geofenceValid && !$codeValid) {
                    error_log("[boraum-webhook] SECURITY: Entrega rejeitada — sem geofence E sem codigo valido (order_id=$orderId)");
                    $db->rollBack();
                    http_response_code(400);
                    echo json_encode([
                        'success' => false,
                        'message' => 'Coordenadas indisponiveis e codigo de entrega invalido. Entrega nao pode ser confirmada.'
                    ]);
                    exit;
                }

                if ($isRouteDelivery && count($allRouteEntregas) > 1) {
                    // ========================================================
                    // ROUTE DELIVERED: Mark ALL orders in the route as entregue
                    // ========================================================
                    error_log("[boraum-webhook] Route #$routeId delivered: marking all " . count($allRouteEntregas) . " orders as entregue");

                    $deliveredOrderIds = [];
                    foreach ($allRouteEntregas as $re) {
                        $reOrderId = (int)$re['referencia_id'];

                        // Update entrega
                        $updateFields = "boraum_status = 'delivered', status = 'entregue', entregue_em = NOW(), updated_at = NOW()";
                        $params = [];
                        if ($deliveryPhoto) { $updateFields .= ", foto_entrega = ?"; $params[] = $deliveryPhoto; }
                        if ($codeValid) { $updateFields .= ", codigo_entrega_validado = 1"; }
                        $params[] = $re['id'];
                        $db->prepare("UPDATE om_entregas SET $updateFields WHERE id = ?")->execute($params);

                        // Update order
                        $orderUpdateSql = "UPDATE om_market_orders SET status = 'entregue', delivered_at = NOW(), updated_at = NOW(), date_modified = NOW()";
                        $orderParams = [];
                        if ($deliveryPhoto) {
                            $orderUpdateSql .= ", delivery_photo = ?, delivery_photo_at = NOW()";
                            $orderParams[] = $deliveryPhoto;
                            if ($driverLat) { $orderUpdateSql .= ", delivery_photo_lat = ?"; $orderParams[] = $driverLat; }
                            if ($driverLng) { $orderUpdateSql .= ", delivery_photo_lng = ?"; $orderParams[] = $driverLng; }
                        }
                        $orderUpdateSql .= " WHERE order_id = ? AND status NOT IN ('entregue', 'retirado', 'cancelado')";
                        $orderParams[] = $reOrderId;
                        $db->prepare($orderUpdateSql)->execute($orderParams);

                        // Update route stop
                        if ($routeId) {
                            $db->prepare("UPDATE om_delivery_route_stops SET status = 'completed', completed_at = NOW() WHERE route_id = ? AND order_id = ?")
                               ->execute([$routeId, $reOrderId]);
                        }

                        $deliverMsg = "Pedido entregue pelo motorista BoraUm (rota #$routeId)";
                        if ($codeValid) $deliverMsg .= " (codigo verificado)";
                        if ($deliveryPhoto) $deliverMsg .= " (foto registrada)";
                        logEvent($db, $reOrderId, 'delivered', $deliverMsg);

                        $deliveredOrderIds[] = $reOrderId;
                    }

                    // Update route status
                    if ($routeId) {
                        $db->prepare("UPDATE om_delivery_routes SET boraum_status = 'delivered', status = 'completed', finished_at = NOW() WHERE route_id = ?")->execute([$routeId]);
                    }

                    // Notify all distinct customers
                    $notifiedCustomers = [];
                    foreach ($deliveredOrderIds as $doid) {
                        $stmtPed = $db->prepare("SELECT * FROM om_market_orders WHERE order_id = ?");
                        $stmtPed->execute([$doid]);
                        $rePedido = $stmtPed->fetch();
                        if ($rePedido) {
                            $cid = (int)$rePedido['customer_id'];
                            if (!in_array($cid, $notifiedCustomers)) {
                                $notifBody = "Todos os seus pedidos da rota foram entregues. Bom apetite! Avalie sua experiencia.";
                                if ($deliveryPhoto) $notifBody .= "\n\nFoto da entrega registrada.";
                                if ($codeValid) $notifBody .= "\nCodigo de entrega verificado.";
                                notifyCustomer($db, $rePedido, 'Pedidos entregues!', $notifBody);
                                $notifiedCustomers[] = $cid;
                            }
                            if ($deliveryPhoto) {
                                sendPhotoToCustomer($rePedido, $deliveryPhoto, "Foto da entrega do pedido #{$rePedido['order_number']}");
                            }
                        }
                    }

                    // Process repasse for EACH order in the route
                    foreach ($deliveredOrderIds as $doid) {
                        processOrderRepasse($db, $doid, $allRouteEntregas);
                    }
                } else {
                    // ========================================================
                    // SINGLE ORDER DELIVERED (original logic)
                    // ========================================================
                    $updateFields = "boraum_status = 'delivered', status = 'entregue', entregue_em = NOW(), updated_at = NOW()";
                    $params = [];
                    if ($deliveryPhoto) { $updateFields .= ", foto_entrega = ?"; $params[] = $deliveryPhoto; }
                    if ($codeValid) { $updateFields .= ", codigo_entrega_validado = 1"; }
                    $params[] = $entrega['id'];
                    $db->prepare("UPDATE om_entregas SET $updateFields WHERE id = ?")->execute($params);

                    // Atualizar pedido
                    $orderUpdateSql = "
                        UPDATE om_market_orders SET
                            status = 'entregue',
                            delivered_at = NOW(),
                            updated_at = NOW(),
                            date_modified = NOW()";
                    $orderParams = [];
                    if ($deliveryPhoto) {
                        $orderUpdateSql .= ", delivery_photo = ?, delivery_photo_at = NOW()";
                        $orderParams[] = $deliveryPhoto;
                        if ($driverLat) { $orderUpdateSql .= ", delivery_photo_lat = ?"; $orderParams[] = $driverLat; }
                        if ($driverLng) { $orderUpdateSql .= ", delivery_photo_lng = ?"; $orderParams[] = $driverLng; }
                    }
                    $orderUpdateSql .= " WHERE order_id = ? AND status NOT IN ('entregue', 'retirado', 'cancelado')";
                    $orderParams[] = $orderId;
                    $stmtOrderUpd = $db->prepare($orderUpdateSql);
                    $stmtOrderUpd->execute($orderParams);
                    if ($stmtOrderUpd->rowCount() === 0) {
                        $db->commit();
                        response(true, null, "Order already in terminal state, skipped");
                    }

                    $deliverMsg = "Pedido entregue pelo motorista BoraUm";
                    if ($codeValid) $deliverMsg .= " (codigo verificado)";
                    if ($deliveryPhoto) $deliverMsg .= " (foto registrada)";
                    logEvent($db, $orderId, 'delivered', $deliverMsg);

                    $notifBody = "Seu pedido foi entregue. Bom apetite! Avalie sua experiencia.";
                    if ($deliveryPhoto) { $notifBody .= "\n\nFoto da entrega registrada."; }
                    if ($codeValid) { $notifBody .= "\nCodigo de entrega verificado."; }
                    notifyCustomer($db, $pedido, 'Pedido entregue!', $notifBody);

                    if ($deliveryPhoto) {
                        sendPhotoToCustomer($pedido, $deliveryPhoto, "Foto da entrega do seu pedido #{$pedido['order_number']}");
                    }

                    // Process repasse for single order
                    processOrderRepasse($db, $orderId, [$entrega]);
                }
                break;

            // -----------------------------------------------------------------
            case 'cancelled':
                // Motorista cancelou - precisa redespachar
                if ($isRouteDelivery && count($allRouteEntregas) > 1) {
                    // Route cancellation: reset ALL entregas and orders in the route
                    foreach ($allRouteEntregas as $re) {
                        $reOrderId = (int)$re['referencia_id'];
                        $db->prepare("
                            UPDATE om_entregas SET
                                boraum_status = 'cancelled',
                                boraum_delivery_id = NULL,
                                status = 'buscando_entregador',
                                motorista_nome = NULL,
                                motorista_telefone = NULL,
                                motorista_foto = NULL,
                                motorista_veiculo = NULL,
                                updated_at = NOW()
                            WHERE id = ?
                        ")->execute([$re['id']]);

                        $db->prepare("
                            UPDATE om_market_orders SET
                                status = 'aguardando_entregador',
                                driver_name = NULL,
                                driver_phone = NULL,
                                driver_photo = NULL,
                                updated_at = NOW(),
                                date_modified = NOW()
                            WHERE order_id = ? AND status NOT IN ('entregue', 'cancelado')
                        ")->execute([$reOrderId]);

                        logEvent($db, $reOrderId, 'driver_cancelled', "Motorista cancelou rota #$routeId: $reason. Buscando novo entregador.");
                    }

                    // Reset route stops
                    if ($routeId) {
                        $db->prepare("UPDATE om_delivery_route_stops SET status = 'pending', arrived_at = NULL, completed_at = NULL WHERE route_id = ? AND status NOT IN ('cancelled')")
                           ->execute([$routeId]);
                        $db->prepare("UPDATE om_delivery_routes SET boraum_delivery_id = NULL, boraum_status = 'cancelled', status = 'pending' WHERE route_id = ?")
                           ->execute([$routeId]);
                    }

                    // Notify all customers
                    $notifiedCustomers = [];
                    foreach ($allRouteEntregas as $re) {
                        $reOrderId = (int)$re['referencia_id'];
                        $stmtPed = $db->prepare("SELECT * FROM om_market_orders WHERE order_id = ?");
                        $stmtPed->execute([$reOrderId]);
                        $rePedido = $stmtPed->fetch();
                        if ($rePedido) {
                            $cid = (int)$rePedido['customer_id'];
                            if (!in_array($cid, $notifiedCustomers)) {
                                notifyCustomer($db, $rePedido, 'Entregador indisponivel', 'Estamos buscando um novo entregador para seus pedidos. Voce sera notificado quando um novo motorista aceitar.');
                                $notifiedCustomers[] = $cid;
                            }
                        }
                    }

                    // Try to redispatch the entire route
                    $redispatchOk = false;
                    try {
                        require_once __DIR__ . '/../helpers/delivery.php';
                        // Delete old entregas to allow fresh dispatch
                        foreach ($allRouteEntregas as $re) {
                            $db->prepare("DELETE FROM om_entregas WHERE id = ?")->execute([$re['id']]);
                        }
                        $dispatchResult = dispatchRouteToBoraUm($db, $routeId);
                        if (!empty($dispatchResult['success']) && !empty($dispatchResult['boraum_dispatched'])) {
                            $redispatchOk = true;
                            foreach ($allRouteEntregas as $re) {
                                logEvent($db, (int)$re['referencia_id'], 'delivery_redispatched', 'Rota redespachada automaticamente');
                            }
                        } else {
                            error_log("[boraum-webhook] Route redispatch falhou rota #$routeId: " . ($dispatchResult['message'] ?? 'unknown'));
                        }
                    } catch (Exception $retryErr) {
                        error_log("[boraum-webhook] Erro route redispatch: " . $retryErr->getMessage());
                    }

                    // Auto-refund if redispatch failed and orders are old
                    if (!$redispatchOk) {
                        $orderAge = (time() - strtotime($pedido['date_added'])) / 3600;
                        if ($orderAge > 2) {
                            error_log("[boraum-webhook] Rota #$routeId sem entregador ha >2h — iniciando auto-refund para todos os pedidos");
                            foreach ($allRouteEntregas as $re) {
                                $reOrderId = (int)$re['referencia_id'];
                                try {
                                    $stmtPed = $db->prepare("SELECT * FROM om_market_orders WHERE order_id = ?");
                                    $stmtPed->execute([$reOrderId]);
                                    $rePedido = $stmtPed->fetch();
                                    if ($rePedido && !in_array($rePedido['status'], ['cancelado', 'entregue'])) {
                                        triggerAutoRefundFromWebhook($db, $rePedido, "Rota cancelada pelo BoraUm e nao foi possivel redespachar. Motivo: $reason");
                                    }
                                } catch (Exception $refundErr) {
                                    error_log("[boraum-webhook] Erro auto-refund pedido #$reOrderId (rota #$routeId): " . $refundErr->getMessage());
                                }
                            }
                        }
                    }
                } else {
                    // Single order cancellation (original logic)
                    $db->prepare("
                        UPDATE om_entregas SET
                            boraum_status = 'cancelled',
                            boraum_delivery_id = NULL,
                            status = 'buscando_entregador',
                            motorista_nome = NULL,
                            motorista_telefone = NULL,
                            motorista_foto = NULL,
                            motorista_veiculo = NULL,
                            updated_at = NOW()
                        WHERE id = ?
                    ")->execute([$entrega['id']]);

                    $db->prepare("
                        UPDATE om_market_orders SET
                            status = 'aguardando_entregador',
                            driver_name = NULL,
                            driver_phone = NULL,
                            driver_photo = NULL,
                            updated_at = NOW(),
                            date_modified = NOW()
                        WHERE order_id = ?
                    ")->execute([$orderId]);

                    logEvent($db, $orderId, 'driver_cancelled', "Motorista cancelou: $reason. Buscando novo entregador.");
                    notifyCustomer($db, $pedido, 'Entregador indisponivel', 'Estamos buscando um novo entregador para seu pedido. Voce sera notificado quando um novo motorista aceitar.');

                    // Tentar redespachar automaticamente
                    $redispatchOk = false;
                    try {
                        require_once __DIR__ . '/../helpers/delivery.php';
                        $stmtRetry = $db->prepare("SELECT * FROM om_market_orders WHERE order_id = ?");
                        $stmtRetry->execute([$orderId]);
                        $retryOrder = $stmtRetry->fetch();
                        if ($retryOrder) {
                            $db->prepare("DELETE FROM om_entregas WHERE id = ?")->execute([$entrega['id']]);
                            $dispatchResult = dispatchToBoraUm($db, $retryOrder);
                            if (!empty($dispatchResult['success'])) {
                                $redispatchOk = true;
                                logEvent($db, $orderId, 'delivery_redispatched', 'Novo entregador solicitado automaticamente');
                            } else {
                                error_log("[boraum-webhook] Redispatch falhou pedido #$orderId: " . ($dispatchResult['message'] ?? 'unknown'));
                            }
                        }
                    } catch (Exception $retryErr) {
                        error_log("[boraum-webhook] Erro redispatch: " . $retryErr->getMessage());
                    }

                    if (!$redispatchOk) {
                        $orderAge = (time() - strtotime($pedido['date_added'])) / 3600;
                        if ($orderAge > 2) {
                            error_log("[boraum-webhook] Pedido #$orderId sem entregador ha >2h — iniciando auto-refund");
                            try {
                                triggerAutoRefundFromWebhook($db, $pedido, "Entrega cancelada pelo BoraUm e nao foi possivel redespachar. Motivo: $reason");
                            } catch (Exception $refundErr) {
                                error_log("[boraum-webhook] Erro auto-refund pedido #$orderId: " . $refundErr->getMessage());
                            }
                        }
                    }
                }
                break;
        }

        // Atualizar localizacao do motorista (se disponivel)
        if ($driverLat && $driverLng && $event !== 'cancelled') {
            try {
                $db->prepare("
                    INSERT INTO om_delivery_tracking (delivery_id, order_id, latitude, longitude, created_at)
                    VALUES (?, ?, ?, ?, NOW())
                ")->execute([$entrega['id'], $orderId, $driverLat, $driverLng]);
            } catch (Exception $e) {
                error_log("[boraum-webhook] Erro tracking: " . $e->getMessage());
            }
        }

        // Commit - handle case where OmRepasse already committed the outer transaction
        if ($db->inTransaction()) {
            $db->commit();
        }

        // Post-commit: Process pending Stripe/PIX refunds from auto-refund (external API calls outside transaction)
        if ($event === 'cancelled') {
            // For route cancellations, process refunds for ALL orders in the route
            $refundOrderIds = [$orderId];
            if ($isRouteDelivery && count($allRouteEntregas) > 1) {
                $refundOrderIds = array_map(function($re) { return (int)$re['referencia_id']; }, $allRouteEntregas);
                $refundOrderIds = array_unique($refundOrderIds);
            }

            foreach ($refundOrderIds as $refundOid) {
                try {
                    $stmtNotes = $db->prepare("SELECT notes, order_id FROM om_market_orders WHERE order_id = ?");
                    $stmtNotes->execute([$refundOid]);
                    $notesRow = $stmtNotes->fetch();
                    $orderNotes = $notesRow['notes'] ?? '';

                    // Stripe refund
                    if (preg_match('/\[PENDING_STRIPE_REFUND:([^\]]+)\]/', $orderNotes, $m)) {
                        $pendingPi = $m[1];
                        try {
                            processPostCommitStripeRefund($db, $pendingPi, $refundOid);
                            $db->prepare("UPDATE om_market_orders SET notes = REPLACE(notes, ?, ' [AUTO-REFUND STRIPE PROCESSED]') WHERE order_id = ?")
                               ->execute(["[PENDING_STRIPE_REFUND:$pendingPi]", $refundOid]);
                        } catch (Exception $stripeErr) {
                            error_log("[boraum-webhook] Post-commit Stripe refund error pedido #$refundOid: " . $stripeErr->getMessage());
                        }
                    }

                    // PIX refund
                    if (strpos($orderNotes, '[PENDING_PIX_REFUND]') !== false) {
                        try {
                            $txStmt = $db->prepare("SELECT pagarme_order_id FROM om_pagarme_transacoes WHERE pedido_id = ? AND tipo = 'pix' ORDER BY created_at DESC LIMIT 1");
                            $txStmt->execute([$refundOid]);
                            $txRow = $txStmt->fetch();
                            if (!empty($txRow['pagarme_order_id'])) {
                                processPostCommitPixRefund($db, $txRow['pagarme_order_id'], $refundOid);
                                $db->prepare("UPDATE om_market_orders SET notes = REPLACE(notes, '[PENDING_PIX_REFUND]', '[AUTO-REFUND PIX PROCESSED]') WHERE order_id = ?")
                                   ->execute([$refundOid]);
                            }
                        } catch (Exception $pixErr) {
                            error_log("[boraum-webhook] Post-commit PIX refund error pedido #$refundOid: " . $pixErr->getMessage());
                        }
                    }
                } catch (Exception $postErr) {
                    error_log("[boraum-webhook] Post-commit refund processing error pedido #$refundOid: " . $postErr->getMessage());
                }
            }
        }

        // Post-commit: Auto-create dispute for cancelled deliveries
        if ($event === 'cancelled') {
            try {
                $disputeOrderIds = [$orderId];
                if ($isRouteDelivery && count($allRouteEntregas) > 1) {
                    $disputeOrderIds = array_unique(array_map(function($re) { return (int)$re['referencia_id']; }, $allRouteEntregas));
                }
                foreach ($disputeOrderIds as $dispOid) {
                    // Check for duplicate dispute before inserting
                    $stmtDupCheck = $db->prepare("SELECT id FROM om_boraum_disputes WHERE order_id = ? AND category = 'cancelled_by_driver' AND status NOT IN ('resolved', 'rejected') LIMIT 1");
                    $stmtDupCheck->execute([$dispOid]);
                    if ($stmtDupCheck->fetch()) continue;

                    $stmtDispOrder = $db->prepare("SELECT order_number, total, delivery_fee FROM om_market_orders WHERE order_id = ?");
                    $stmtDispOrder->execute([$dispOid]);
                    $dispOrder = $stmtDispOrder->fetch();
                    $dispOrderNum = $dispOrder['order_number'] ?? $dispOid;
                    $dispOrderTotal = $dispOrder ? round((float)$dispOrder['total'], 2) : null;
                    $dispDeliveryCost = $dispOrder ? round((float)$dispOrder['delivery_fee'], 2) : null;

                    // Find entrega for this order
                    $stmtDispE = $db->prepare("SELECT id, boraum_delivery_id, valor_frete FROM om_entregas WHERE referencia_id = ? AND origem_sistema = 'mercado' ORDER BY id DESC LIMIT 1");
                    $stmtDispE->execute([$dispOid]);
                    $dispEntrega = $stmtDispE->fetch();
                    $dispEntregaId = $dispEntrega ? (int)$dispEntrega['id'] : null;
                    $dispBoraumId = $dispEntrega['boraum_delivery_id'] ?? $deliveryId;
                    if ($dispEntrega && $dispEntrega['valor_frete']) {
                        $dispDeliveryCost = round((float)$dispEntrega['valor_frete'], 2);
                    }

                    $db->prepare("
                        INSERT INTO om_boraum_disputes (order_id, entrega_id, boraum_delivery_id, opened_by, category, severity, title, description, order_total, delivery_cost, refund_requested, who_pays, status, created_at, updated_at)
                        VALUES (?, ?, ?, 'system', 'cancelled_by_driver', 'high', ?, ?, ?, ?, ?, 'boraum', 'opened', NOW(), NOW())
                    ")->execute([
                        $dispOid, $dispEntregaId, $dispBoraumId,
                        "Entrega cancelada pelo motorista - Pedido #{$dispOrderNum}",
                        "Motivo: " . ($reason ?: 'Nao informado') . ($isRouteDelivery ? " (Rota #{$routeId})" : ''),
                        $dispOrderTotal, $dispDeliveryCost, $dispDeliveryCost,
                    ]);
                    error_log("[boraum-webhook] Auto-dispute created for cancelled delivery on order #{$dispOid}");
                }
            } catch (\Throwable $e) {
                error_log("[boraum-webhook] Auto-dispute creation failed: " . $e->getMessage());
            }
        }

        // Post-commit: Auto-create dispute for expired deliveries (no driver accepted)
        if ($event === 'expired' || (isset($input['sub_event']) && $input['sub_event'] === 'expired')) {
            try {
                $stmtDupCheck = $db->prepare("SELECT id FROM om_boraum_disputes WHERE order_id = ? AND category = 'not_delivered' AND status NOT IN ('resolved', 'rejected') LIMIT 1");
                $stmtDupCheck->execute([$orderId]);
                if (!$stmtDupCheck->fetch()) {
                    $stmtDispOrder = $db->prepare("SELECT order_number, total, delivery_fee FROM om_market_orders WHERE order_id = ?");
                    $stmtDispOrder->execute([$orderId]);
                    $dispOrder = $stmtDispOrder->fetch();
                    $dispOrderNum = $dispOrder['order_number'] ?? $orderId;
                    $dispOrderTotal = $dispOrder ? round((float)$dispOrder['total'], 2) : null;
                    $dispDeliveryCost = $dispOrder ? round((float)$dispOrder['delivery_fee'], 2) : null;

                    $db->prepare("
                        INSERT INTO om_boraum_disputes (order_id, entrega_id, boraum_delivery_id, opened_by, category, severity, title, description, order_total, delivery_cost, refund_requested, who_pays, status, created_at, updated_at)
                        VALUES (?, ?, ?, 'system', 'not_delivered', 'low', ?, ?, ?, ?, ?, 'boraum', 'opened', NOW(), NOW())
                    ")->execute([
                        $orderId, $entrega['id'] ?? null, $deliveryId,
                        "Nenhum motorista aceitou a entrega - Pedido #{$dispOrderNum}",
                        "Entrega expirou sem nenhum motorista disponivel aceitar.",
                        $dispOrderTotal, $dispDeliveryCost, $dispDeliveryCost,
                    ]);
                    error_log("[boraum-webhook] Auto-dispute created for expired delivery on order #{$orderId}");
                }
            } catch (\Throwable $e) {
                error_log("[boraum-webhook] Auto-dispute creation (expired) failed: " . $e->getMessage());
            }
        }

        $responsePayload = ['success' => true, 'message' => "Event $event processed", 'order_id' => $orderId];
        if ($isRouteDelivery) {
            $responsePayload['route_id'] = $routeId;
            $responsePayload['route_orders'] = count($allRouteEntregas);
        }
        echo json_encode($responsePayload);

    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

} catch (Exception $e) {
    error_log("[boraum-webhook] Erro: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro interno do servidor']);
}

// =========================================================================
// Helpers
// =========================================================================

/**
 * Process repasse (commission) for a single order after delivery.
 * Extracted from inline code so it can be called per-order in route deliveries.
 */
function processOrderRepasse(PDO $db, int $orderId, array $entregas): void {
    try {
        require_once dirname(__DIR__, 3) . '/includes/classes/OmRepasse.php';
        require_once dirname(__DIR__, 3) . '/includes/classes/PusherService.php';

        // Load order data
        $stmtPed = $db->prepare("SELECT * FROM om_market_orders WHERE order_id = ?");
        $stmtPed->execute([$orderId]);
        $pedido = $stmtPed->fetch();
        if (!$pedido) return;

        // Idempotency check
        $stmtRepasseCheck = $db->prepare("SELECT id FROM om_repasses WHERE order_id = ? AND order_type = 'mercado' LIMIT 1");
        $stmtRepasseCheck->execute([$orderId]);
        if ($stmtRepasseCheck->fetch()) {
            error_log("[boraum-webhook] Repasse ja existe para pedido #$orderId — pulando (idempotencia)");
            return;
        }

        // Find entrega for this order
        $entrega = null;
        foreach ($entregas as $e) {
            if ((int)($e['referencia_id'] ?? 0) === $orderId) {
                $entrega = $e;
                break;
            }
        }
        if (!$entrega) {
            // Fallback: query directly
            $stmtE = $db->prepare("SELECT * FROM om_entregas WHERE referencia_id = ? AND origem_sistema = 'mercado' LIMIT 1");
            $stmtE->execute([$orderId]);
            $entrega = $stmtE->fetch();
        }

        $partnerId = (int)($pedido['partner_id'] ?? 0);
        $subtotal = (float)($pedido['subtotal'] ?? 0);
        $deliveryFee = (float)($pedido['delivery_fee'] ?? 0);
        $serviceFee = (float)($pedido['service_fee'] ?? 0);
        $expressFee = (float)($pedido['express_fee'] ?? 0);

        require_once dirname(__DIR__, 3) . '/includes/classes/OmPricing.php';
        $usaBoraUm = $entrega && !empty($entrega['boraum_delivery_id']);
        $comissao = OmPricing::calcularComissao($subtotal, $usaBoraUm ? 'boraum' : 'proprio');
        $comissaoPct = $comissao['taxa'];
        $comissaoValor = $comissao['valor'];
        $valorParceiro = round($subtotal - $comissaoValor, 2);

        $deliveryFeeBase = max(0, $deliveryFee - $expressFee);
        if (!$usaBoraUm && $deliveryFeeBase > 0) {
            $valorParceiro += $deliveryFeeBase;
        }

        if ($partnerId && $valorParceiro > 0) {
            $repasse = om_repasse()->setDb($db);
            $repasse->criar(
                $orderId,
                'mercado',
                $partnerId,
                $valorParceiro,
                [
                    'subtotal' => $subtotal,
                    'comissao_pct' => $comissaoPct * 100,
                    'comissao_valor' => $comissaoValor,
                    'delivery_fee' => $deliveryFee,
                    'delivery_fee_base' => $deliveryFeeBase,
                    'express_fee' => $expressFee,
                    'delivery_fee_destino' => $usaBoraUm ? 'boraum' : 'parceiro',
                    'service_fee' => $serviceFee,
                    'tier' => $usaBoraUm ? 'boraum_18pct' : 'proprio_10pct',
                    'receita_plataforma' => round($comissaoValor + $serviceFee + $expressFee, 2),
                ]
            );

            $db->prepare("UPDATE om_market_orders SET commission_rate = ?, commission_amount = ?, repasse_valor = ? WHERE order_id = ?")
               ->execute([$comissaoPct * 100, $comissaoValor, $valorParceiro, $orderId]);

            PusherService::walletUpdate($partnerId, [
                'order_id' => $orderId,
                'valor' => $valorParceiro,
                'comissao' => $comissaoValor,
                'status' => 'hold',
                'hold_hours' => 2
            ]);

            error_log("[boraum-webhook] Repasse criado: parceiro=$partnerId valor=R$$valorParceiro comissao=" . ($comissaoPct * 100) . "% pedido=#$orderId");
        }
    } catch (Exception $payErr) {
        error_log("[boraum-webhook] Erro repasse pedido #$orderId: " . $payErr->getMessage());
    }
}

function logEvent(PDO $db, int $orderId, string $type, string $message): void {
    try {
        $db->prepare("INSERT INTO om_market_order_events (order_id, event_type, message, created_by, created_at) VALUES (?, ?, ?, 'boraum_webhook', NOW())")
            ->execute([$orderId, $type, $message]);
    } catch (Exception $e) {
        error_log("[boraum-webhook] Erro log event: " . $e->getMessage());
    }
}

function notifyCustomer(PDO $db, array $pedido, string $title, string $body): void {
    try {
        require_once __DIR__ . '/../helpers/NotificationSender.php';
        $sender = NotificationSender::getInstance($db);
        $sender->notifyCustomer(
            (int)$pedido['customer_id'],
            $title,
            $body,
            [
                'order_id' => $pedido['order_id'],
                'order_number' => $pedido['order_number'] ?? '',
                'type' => 'delivery_update',
            ]
        );
    } catch (Exception $e) {
        error_log("[boraum-webhook] Erro notificacao: " . $e->getMessage());
    }

    // WhatsApp
    try {
        $phone = $pedido['customer_phone'] ?? '';
        if ($phone) {
            require_once __DIR__ . '/../helpers/zapi-whatsapp.php';
            sendWhatsApp($phone, "$title\n\n$body");
        }
    } catch (Exception $e) {
        error_log("[boraum-webhook] Erro WhatsApp: " . $e->getMessage());
    }
}

function sendPhotoToCustomer(array $pedido, string $photoUrl, string $caption): void {
    try {
        $phone = $pedido['customer_phone'] ?? '';
        if (!$phone || !$photoUrl) return;
        require_once __DIR__ . '/../helpers/zapi-whatsapp.php';
        sendWhatsAppImage($phone, $photoUrl, $caption);
    } catch (Exception $e) {
        error_log("[boraum-webhook] Erro enviar foto: " . $e->getMessage());
    }
}

/**
 * Trigger automatic refund when BoraUm delivery is cancelled and redispatch fails.
 * Called inside the webhook transaction — must NOT start a new transaction.
 */
function triggerAutoRefundFromWebhook(PDO $db, array $pedido, string $cancelReason): void {
    $orderId = (int)$pedido['order_id'];
    $customerId = (int)($pedido['customer_id'] ?? 0);
    $orderTotal = (float)($pedido['total'] ?? $pedido['final_total'] ?? 0);

    // Cancel the order (already inside transaction with FOR UPDATE lock)
    $notaCancel = " | Auto-refund (BoraUm cancelamento): $cancelReason";
    $db->prepare("
        UPDATE om_market_orders
        SET status = 'cancelado',
            cancel_reason = ?,
            cancelled_at = NOW(),
            notes = COALESCE(notes, '') || ?,
            date_modified = NOW()
        WHERE order_id = ?
    ")->execute([$cancelReason, $notaCancel, $orderId]);

    // Restore stock
    $stmtItens = $db->prepare("SELECT product_id, quantity FROM om_market_order_items WHERE order_id = ?");
    $stmtItens->execute([$orderId]);
    foreach ($stmtItens->fetchAll() as $item) {
        if ($item['product_id']) {
            $db->prepare("UPDATE om_market_products SET quantity = quantity + ? WHERE product_id = ?")
               ->execute([$item['quantity'], $item['product_id']]);
        }
    }

    // Restore cashback
    $cashbackUsed = (float)($pedido['cashback_discount'] ?? 0);
    if ($cashbackUsed > 0) {
        require_once __DIR__ . '/../helpers/cashback.php';
        refundCashback($db, $orderId);
    }

    // Restore loyalty points
    $pointsUsed = (int)($pedido['loyalty_points_used'] ?? 0);
    if ($pointsUsed > 0) {
        $db->prepare("UPDATE om_market_loyalty_points SET current_points = current_points + ?, updated_at = NOW() WHERE customer_id = ?")
           ->execute([$pointsUsed, $customerId]);
    }

    // Restore coupon
    $couponId = (int)($pedido['coupon_id'] ?? 0);
    if ($couponId) {
        $db->prepare("DELETE FROM om_market_coupon_usage WHERE coupon_id = ? AND customer_id = ? AND order_id = ?")
           ->execute([$couponId, $customerId, $orderId]);
        $db->prepare("UPDATE om_market_coupons SET current_uses = GREATEST(0, current_uses - 1) WHERE id = ?")
           ->execute([$couponId]);
    }

    // Release shopper
    if (!empty($pedido['shopper_id'])) {
        $db->prepare("UPDATE om_market_shoppers SET disponivel = 1, pedido_atual_id = NULL WHERE shopper_id = ?")
           ->execute([$pedido['shopper_id']]);
    }

    // Log event
    logEvent($db, $orderId, 'auto_refund', "Auto-refund: $cancelReason");

    // Register refund record
    try {
        $db->prepare("
            INSERT INTO om_market_refunds (order_id, customer_id, amount, reason, status, admin_note, reviewed_at, created_at)
            VALUES (?, ?, ?, ?, 'approved', 'Auto-refund (entrega BoraUm cancelada sem redespacho)', NOW(), NOW())
        ")->execute([$orderId, $customerId, $orderTotal, $cancelReason]);
    } catch (Exception $e) {
        error_log("[boraum-webhook] Erro registrar refund pedido #$orderId: " . $e->getMessage());
    }

    // Notify customer
    notifyCustomer($db, $pedido, 'Pedido cancelado — reembolso automatico',
        "Seu pedido #{$pedido['order_number']} foi cancelado porque nao foi possivel encontrar um entregador. "
        . "O reembolso de R$ " . number_format($orderTotal, 2, ',', '.') . " sera processado automaticamente.");

    // Queue Stripe/PIX refund to be processed after transaction commit
    // Store in order notes for the auto-refund cron to pick up if needed
    $paymentMethod = $pedido['forma_pagamento'] ?? $pedido['payment_method'] ?? '';
    $stripePi = $pedido['stripe_payment_intent_id'] ?? $pedido['payment_id'] ?? '';

    if (in_array($paymentMethod, ['stripe_card', 'stripe_wallet', 'credito']) && $stripePi) {
        $db->prepare("UPDATE om_market_orders SET notes = COALESCE(notes, '') || ? WHERE order_id = ?")
           ->execute([" [PENDING_STRIPE_REFUND:$stripePi]", $orderId]);
    }

    $needsPixRefund = ($paymentMethod === 'pix') && (
        ($pedido['pagamento_status'] ?? '') === 'pago' ||
        ($pedido['payment_status'] ?? '') === 'paid' ||
        ($pedido['pix_paid'] ?? false)
    );
    if ($needsPixRefund) {
        $db->prepare("UPDATE om_market_orders SET notes = COALESCE(notes, '') || ' [PENDING_PIX_REFUND]' WHERE order_id = ?")
           ->execute([$orderId]);
    }

    error_log("[boraum-webhook] Auto-refund pedido #$orderId total=R$" . number_format($orderTotal, 2, ',', '.') . " payment=$paymentMethod");
}

function processPayments(PDO $db, int $orderId, array $entrega): void {
    // Processar pagamentos via API interna
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'http://localhost/api/financeiro/processar-entrega.php',
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'order_id' => $orderId,
            'order_type' => 'mercado',
            'entrega_id' => $entrega['id'],
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        // SECURITY: Add connection and execution timeouts to prevent hanging
        CURLOPT_CONNECTTIMEOUT => 5,  // 5 seconds to establish connection
        CURLOPT_TIMEOUT => 10         // 10 seconds total execution time
    ]);
    $result = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        error_log("[boraum-webhook] Curl error in processPayments: " . $curlError);
    }

    if ($result) {
        $data = json_decode($result, true);
        if (!$data || !($data['success'] ?? false)) {
            error_log("[boraum-webhook] Pagamento falhou: " . ($data['message'] ?? 'unknown'));
        }
    }
}

/**
 * Process Stripe refund after transaction commit (auto-refund from BoraUm cancellation)
 */
function processPostCommitStripeRefund(PDO $db, string $paymentIntentId, int $orderId): void {
    $stripeEnv = dirname(__DIR__, 3) . '/.env.stripe';
    $STRIPE_SK = '';
    if (file_exists($stripeEnv)) {
        foreach (file($stripeEnv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            if (strpos($line, '=') !== false) {
                [$key, $value] = explode('=', $line, 2);
                if (trim($key) === 'STRIPE_SECRET_KEY') $STRIPE_SK = trim($value);
            }
        }
    }
    if (empty($STRIPE_SK)) return;

    $idempotencyKey = "refund_boraum_cancel_{$orderId}_{$paymentIntentId}";
    $ch = curl_init("https://api.stripe.com/v1/refunds");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'payment_intent' => $paymentIntentId,
            'reason' => 'requested_by_customer',
            'metadata[order_id]' => $orderId,
            'metadata[source]' => 'superbora_boraum_cancel_refund',
        ]),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $STRIPE_SK,
            'Content-Type: application/x-www-form-urlencoded',
            'Stripe-Version: 2023-10-16',
            'Idempotency-Key: ' . $idempotencyKey,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $refundData = json_decode($response, true);
    $refundOk = $httpCode >= 200 && $httpCode < 300 && !empty($refundData['id']);

    if ($refundOk) {
        error_log("[boraum-webhook] Stripe refund OK pedido #$orderId PI=$paymentIntentId refund_id={$refundData['id']}");
        $db->prepare("UPDATE om_market_orders SET notes = COALESCE(notes, '') || ? WHERE order_id = ?")
           ->execute([" [STRIPE REFUND OK: {$refundData['id']}]", $orderId]);
    } else {
        error_log("[boraum-webhook] Stripe refund FAILED pedido #$orderId PI=$paymentIntentId HTTP=$httpCode");
        $db->prepare("UPDATE om_market_orders SET notes = COALESCE(notes, '') || ? WHERE order_id = ?")
           ->execute([" [STRIPE REFUND FAILED: HTTP $httpCode]", $orderId]);
    }
}

/**
 * Process PIX refund after transaction commit (auto-refund from BoraUm cancellation)
 */
function processPostCommitPixRefund(PDO $db, string $correlationId, int $orderId): void {
    try {
        require_once dirname(__DIR__, 3) . '/includes/classes/EfiClient.php';
        $efi = new EfiClient();

        // Find e2eId: try payment_id on order first
        $stmt = $db->prepare("SELECT payment_id, total FROM om_market_orders WHERE order_id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        $e2eId = $order['payment_id'] ?? '';
        $amount = (float)($order['total'] ?? 0);

        // If no e2eId, look up via EFI charge status using txid
        if (empty($e2eId)) {
            // Try om_pix_intents
            $stmt = $db->prepare("SELECT correlation_id FROM om_pix_intents WHERE order_id = ? ORDER BY created_at DESC LIMIT 1");
            $stmt->execute([$orderId]);
            $intentRow = $stmt->fetch();
            $txid = $intentRow['correlation_id'] ?? $correlationId;

            if (!empty($txid)) {
                $chargeStatus = $efi->checkChargeStatus($txid);
                $e2eId = $chargeStatus['e2e_id'] ?? '';
            }
        }

        if (!empty($e2eId)) {
            $efi->refundPix($e2eId, $amount);
            $db->prepare("UPDATE om_pagarme_transacoes SET status = 'refunded' WHERE pedido_id = ? AND tipo = 'pix'")->execute([$orderId]);
            error_log("[boraum-webhook] PIX refund OK pedido #$orderId e2eId=$e2eId");
        } else {
            error_log("[boraum-webhook] PIX refund FAILED pedido #$orderId — no e2eId found");
            $db->prepare("UPDATE om_pagarme_transacoes SET status = 'refund_failed' WHERE pedido_id = ? AND tipo = 'pix'")->execute([$orderId]);
            $db->prepare("UPDATE om_market_orders SET notes = COALESCE(notes, '') || ' [PIX REFUND FAILED - NO E2EID]' WHERE order_id = ?")->execute([$orderId]);
        }
    } catch (Exception $e) {
        error_log("[boraum-webhook] PIX refund error pedido #$orderId: " . $e->getMessage());
    }
}
