<?php
/**
 * POST /api/mercado/boraum/webhook.php
 * Webhook que o BoraUm chama para atualizar status de entregas.
 *
 * Auth: Header X-Webhook-Secret (shared secret)
 *
 * Body: {
 *   "event": "driver_accepted|driver_arrived_pickup|picked_up|driver_arrived_dropoff|delivered|cancelled",
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
    // 3. Localizar pedido pelo external_id (order_number) ou delivery_id
    // =========================================================================
    $entrega = null;
    $orderId = null;

    if ($deliveryId) {
        $stmt = $db->prepare("SELECT * FROM om_entregas WHERE boraum_delivery_id = ? LIMIT 1");
        $stmt->execute([$deliveryId]);
        $entrega = $stmt->fetch();
    }

    if (!$entrega && $externalId) {
        // external_id = order_number (ex: SB-456)
        $stmt = $db->prepare("SELECT order_id FROM om_market_orders WHERE order_number = ? LIMIT 1");
        $stmt->execute([$externalId]);
        $order = $stmt->fetch();
        if ($order) {
            $orderId = (int)$order['order_id'];
            $stmt = $db->prepare("SELECT * FROM om_entregas WHERE referencia_id = ? AND origem_sistema = 'mercado' LIMIT 1");
            $stmt->execute([$orderId]);
            $entrega = $stmt->fetch();
        }
    }

    if (!$entrega) {
        error_log("[boraum-webhook] Entrega nao encontrada para delivery_id=$deliveryId, external_id=$externalId");
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Delivery not found']);
        exit;
    }

    $orderId = $orderId ?: (int)$entrega['referencia_id'];

    // Buscar pedido
    $stmt = $db->prepare("SELECT * FROM om_market_orders WHERE order_id = ?");
    $stmt->execute([$orderId]);
    $pedido = $stmt->fetch();

    if (!$pedido) {
        error_log("[boraum-webhook] Pedido #$orderId nao encontrado");
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Order not found']);
        exit;
    }

    // =========================================================================
    // 4. Processar evento
    // =========================================================================
    $validEvents = ['driver_accepted', 'driver_arrived_pickup', 'picked_up', 'driver_arrived_dropoff', 'delivered', 'cancelled', 'driver_chat'];
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

    $driverId = isset($driver['id']) ? (int)$driver['id'] : null;
    $driverName = $driver['name'] ?? null;
    $driverPhone = $driver['phone'] ?? null;
    $driverPhoto = $driver['photo'] ?? null;
    $driverPlate = $driver['plate'] ?? null;
    $driverLat = isset($driver['lat']) ? (float)$driver['lat'] : null;
    $driverLng = isset($driver['lng']) ? (float)$driver['lng'] : null;

    $db->beginTransaction();

    try {
        // Re-fetch order with FOR UPDATE lock inside transaction to prevent race conditions
        $stmt = $db->prepare("SELECT * FROM om_market_orders WHERE order_id = ? FOR UPDATE");
        $stmt->execute([$orderId]);
        $pedido = $stmt->fetch();
        if (!$pedido) {
            $db->rollBack();
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Order not found']);
            exit;
        }

        switch ($event) {
            // -----------------------------------------------------------------
            case 'driver_accepted':
                // Motorista aceitou a corrida, indo buscar na loja
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

                // Atualizar pedido com driver_id pra processar pagamento depois
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
                break;

            // -----------------------------------------------------------------
            case 'driver_arrived_pickup':
                // Motorista chegou na loja
                $db->prepare("
                    UPDATE om_entregas SET boraum_status = 'arrived_pickup', status = 'motorista_no_local', updated_at = NOW()
                    WHERE id = ?
                ")->execute([$entrega['id']]);

                logEvent($db, $orderId, 'driver_arrived_pickup', "Motorista chegou no estabelecimento");
                break;

            // -----------------------------------------------------------------
            case 'picked_up':
                // Motorista retirou o pedido, saiu pra entregar
                // Validar codigo de coleta
                $codeValid = false;
                if ($pickupCode && !empty($entrega['qr_coleta'])) {
                    $codeValid = strtoupper($pickupCode) === strtoupper($entrega['qr_coleta']);
                    if (!$codeValid) {
                        error_log("[boraum-webhook] Codigo coleta INVALIDO! Esperado={$entrega['qr_coleta']} Recebido=$pickupCode");
                    }
                }

                $updateFields = "boraum_status = 'in_transit', status = 'em_transito', coletado_em = NOW(), updated_at = NOW()";
                $params = [];

                if ($pickupPhoto) {
                    $updateFields .= ", foto_coleta = ?";
                    $params[] = $pickupPhoto;
                }
                if ($codeValid) {
                    $updateFields .= ", codigo_coleta_validado = 1";
                }
                $params[] = $entrega['id'];

                $db->prepare("UPDATE om_entregas SET $updateFields WHERE id = ?")->execute($params);

                $db->prepare("
                    UPDATE om_market_orders SET
                        status = 'em_entrega',
                        started_delivery_at = NOW(),
                        picked_up_at = NOW(),
                        updated_at = NOW(),
                        date_modified = NOW()
                    WHERE order_id = ?
                ")->execute([$orderId]);

                $pickupMsg = "Motorista retirou o pedido";
                if ($codeValid) $pickupMsg .= " (codigo verificado)";
                if ($pickupPhoto) $pickupMsg .= " (foto registrada)";
                logEvent($db, $orderId, 'picked_up', $pickupMsg);

                $notifBody = "Seu pedido saiu para entrega com o motorista $driverName.";
                if ($pickupPhoto) {
                    $notifBody .= "\n\n📸 Foto da coleta registrada.";
                }
                if ($codeValid) {
                    $notifBody .= "\n✅ Codigo verificado com sucesso.";
                }
                notifyCustomer($db, $pedido, 'Pedido a caminho!', $notifBody);

                // Enviar foto da coleta pro cliente via WhatsApp
                if ($pickupPhoto) {
                    sendPhotoToCustomer($pedido, $pickupPhoto, "📦 Foto da coleta do seu pedido #{$pedido['order_number']}");
                }
                break;

            // -----------------------------------------------------------------
            case 'driver_arrived_dropoff':
                // Motorista chegou no endereco do cliente
                $db->prepare("
                    UPDATE om_entregas SET boraum_status = 'arrived_dropoff', updated_at = NOW()
                    WHERE id = ?
                ")->execute([$entrega['id']]);

                logEvent($db, $orderId, 'driver_arrived_dropoff', "Motorista chegou no endereco de entrega");
                notifyCustomer($db, $pedido, 'Entregador chegou!', "O motorista $driverName chegou no seu endereco.");
                break;

            // -----------------------------------------------------------------
            case 'delivered':
                // Entrega confirmada pelo motorista

                // Geofence: verify driver is near delivery address (max 500m)
                $deliveryLat = isset($pedido['latitude_entrega']) ? (float)$pedido['latitude_entrega'] : null;
                $deliveryLng = isset($pedido['longitude_entrega']) ? (float)$pedido['longitude_entrega'] : null;

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

                // Validar codigo de entrega
                $codeValid = false;
                if ($deliveryCode && !empty($entrega['pin_entrega'])) {
                    $codeValid = $deliveryCode === $entrega['pin_entrega'];
                    if (!$codeValid) {
                        error_log("[boraum-webhook] Codigo entrega INVALIDO! Esperado={$entrega['pin_entrega']} Recebido=$deliveryCode");
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

                $updateFields = "boraum_status = 'delivered', status = 'entregue', entregue_em = NOW(), updated_at = NOW()";
                $params = [];

                if ($deliveryPhoto) {
                    $updateFields .= ", foto_entrega = ?";
                    $params[] = $deliveryPhoto;
                }
                if ($codeValid) {
                    $updateFields .= ", codigo_entrega_validado = 1";
                }
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
                    if ($driverLat) {
                        $orderUpdateSql .= ", delivery_photo_lat = ?";
                        $orderParams[] = $driverLat;
                    }
                    if ($driverLng) {
                        $orderUpdateSql .= ", delivery_photo_lng = ?";
                        $orderParams[] = $driverLng;
                    }
                }
                $orderUpdateSql .= " WHERE order_id = ?";
                $orderParams[] = $orderId;
                $db->prepare($orderUpdateSql)->execute($orderParams);

                $deliverMsg = "Pedido entregue pelo motorista BoraUm";
                if ($codeValid) $deliverMsg .= " (codigo verificado)";
                if ($deliveryPhoto) $deliverMsg .= " (foto registrada)";
                logEvent($db, $orderId, 'delivered', $deliverMsg);

                $notifBody = "Seu pedido foi entregue. Bom apetite! Avalie sua experiencia.";
                if ($deliveryPhoto) {
                    $notifBody .= "\n\n📸 Foto da entrega registrada.";
                }
                if ($codeValid) {
                    $notifBody .= "\n✅ Codigo de entrega verificado.";
                }
                notifyCustomer($db, $pedido, 'Pedido entregue!', $notifBody);

                // Enviar foto da entrega pro cliente via WhatsApp
                if ($deliveryPhoto) {
                    sendPhotoToCustomer($pedido, $deliveryPhoto, "✅ Foto da entrega do seu pedido #{$pedido['order_number']}");
                }

                // Processar repasse ao parceiro com comissao (IDEMPOTENTE)
                // confirmar-entrega.php tambem cria repasse — so criar se ainda nao existe
                try {
                    require_once dirname(__DIR__, 3) . '/includes/classes/OmRepasse.php';
                    require_once dirname(__DIR__, 3) . '/includes/classes/PusherService.php';

                    // Verificar se repasse ja existe para este pedido (idempotencia)
                    $stmtRepasseCheck = $db->prepare("SELECT id FROM om_repasses WHERE order_id = ? AND order_type = 'mercado' LIMIT 1");
                    $stmtRepasseCheck->execute([$orderId]);
                    $repasseExists = $stmtRepasseCheck->fetch();

                    if ($repasseExists) {
                        error_log("[boraum-webhook] Repasse ja existe para pedido #$orderId — pulando (idempotencia)");
                    } else {
                        $partnerId = (int)($pedido['partner_id'] ?? 0);
                        $subtotal = (float)($pedido['subtotal'] ?? 0);
                        $deliveryFee = (float)($pedido['delivery_fee'] ?? 0);
                        $serviceFee = (float)($pedido['service_fee'] ?? 0);
                        $expressFee = (float)($pedido['express_fee'] ?? 0);

                        // Usar OmPricing para comissao centralizada
                        require_once dirname(__DIR__, 3) . '/includes/classes/OmPricing.php';
                        $usaBoraUm = !empty($entrega['boraum_delivery_id']);
                        $comissao = OmPricing::calcularComissao($subtotal, $usaBoraUm ? 'boraum' : 'proprio');
                        $comissaoPct = $comissao['taxa'];
                        $comissaoValor = $comissao['valor'];
                        $valorParceiro = round($subtotal - $comissaoValor, 2);

                        // Se entregador proprio, parceiro tambem recebe a taxa de entrega BASE (sem express)
                        $deliveryFeeBase = max(0, $deliveryFee - $expressFee);
                        if (!$usaBoraUm && $deliveryFeeBase > 0) {
                            $valorParceiro += $deliveryFeeBase;
                        }

                        if ($partnerId && $valorParceiro > 0) {
                            $repasse = om_repasse()->setDb($db);
                            $resultRepasse = $repasse->criar(
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

                            // Salvar comissao no pedido
                            $db->prepare("UPDATE om_market_orders SET commission_rate = ?, commission_amount = ?, repasse_valor = ? WHERE order_id = ?")
                               ->execute([$comissaoPct * 100, $comissaoValor, $valorParceiro, $orderId]);

                            // Notificar parceiro via Pusher sobre wallet update
                            PusherService::walletUpdate($partnerId, [
                                'order_id' => $orderId,
                                'valor' => $valorParceiro,
                                'comissao' => $comissaoValor,
                                'status' => 'hold',
                                'hold_hours' => 2
                            ]);

                            error_log("[boraum-webhook] Repasse criado: parceiro=$partnerId valor=R$$valorParceiro comissao=" . ($comissaoPct * 100) . "% pedido=#$orderId");
                        }
                    }
                } catch (Exception $payErr) {
                    error_log("[boraum-webhook] Erro repasse: " . $payErr->getMessage());
                }
                break;

            // -----------------------------------------------------------------
            case 'cancelled':
                // Motorista cancelou - precisa redespachar
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

                // Tentar redespachar automaticamente
                try {
                    require_once __DIR__ . '/../helpers/delivery.php';
                    $stmtRetry = $db->prepare("SELECT * FROM om_market_orders WHERE order_id = ?");
                    $stmtRetry->execute([$orderId]);
                    $retryOrder = $stmtRetry->fetch();
                    if ($retryOrder) {
                        // Limpar entrega anterior pra criar nova
                        $db->prepare("DELETE FROM om_entregas WHERE id = ?")->execute([$entrega['id']]);
                        dispatchToBoraUm($db, $retryOrder);
                        logEvent($db, $orderId, 'delivery_redispatched', 'Novo entregador solicitado automaticamente');
                    }
                } catch (Exception $retryErr) {
                    error_log("[boraum-webhook] Erro redispatch: " . $retryErr->getMessage());
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

        echo json_encode(['success' => true, 'message' => "Event $event processed", 'order_id' => $orderId]);

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
