<?php
/**
 * GET /api/mercado/pedido/status.php?order_id=1
 * Status do pedido (polling)
 * Otimizado com cache curto (TTL: 10 seg) e prepared statements
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 2) . "/cache/CacheHelper.php";
require_once __DIR__ . "/../helpers/eta-calculator.php";
setCorsHeaders();

try {
    $order_id = (int)($_GET["order_id"] ?? 0);
    $partner_id = (int)($_GET["partner_id"] ?? 0);

    // If partner_id provided (no order_id), find most recent active order for this customer at this partner
    if (!$order_id && $partner_id) {
        $customer_id = getCustomerIdFromToken();
        if (!$customer_id) response(false, null, "Nenhum pedido ativo", 404);
        $db = getDB();
        $stmtFind = $db->prepare("
            SELECT order_id FROM om_market_orders
            WHERE partner_id = ? AND customer_id = ?
              AND status NOT IN ('entregue', 'retirado', 'cancelado', 'cancelled', 'refunded')
            ORDER BY date_added DESC LIMIT 1
        ");
        $stmtFind->execute([$partner_id, $customer_id]);
        $found = $stmtFind->fetch();
        if (!$found) response(false, null, "Nenhum pedido ativo", 404);
        $order_id = (int)$found['order_id'];
    }

    if (!$order_id) response(false, null, "order_id obrigatório", 400);

    // SECURITY: Autenticacao obrigatoria — verificar ownership
    $customer_id = $customer_id ?? getCustomerIdFromToken();
    if (!$customer_id) {
        response(false, null, "Autenticacao obrigatoria", 401);
    }
    if (!isset($db)) $db = getDB();
    $stmtCheck = $db->prepare("SELECT customer_id FROM om_market_orders WHERE order_id = ?");
    $stmtCheck->execute([$order_id]);
    $ownerRow = $stmtCheck->fetch();
    if (!$ownerRow || (int)$ownerRow['customer_id'] !== $customer_id) {
        response(false, null, "Pedido nao encontrado", 404);
    }

    $cacheKey = "pedido_status_{$order_id}_{$customer_id}";

    $data = CacheHelper::remember($cacheKey, 10, function() use ($order_id) {
        $db = getDB();

        $stmt = $db->prepare("SELECT o.*, p.name as parceiro_nome, p.logo as parceiro_logo,
                              p.latitude as parceiro_lat, p.longitude as parceiro_lng,
                              s.name as shopper_nome, s.phone as shopper_telefone, s.photo as shopper_foto,
                              s.latitude as shopper_lat, s.longitude as shopper_lng
                              FROM om_market_orders o
                              LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
                              LEFT JOIN om_market_shoppers s ON o.shopper_id = s.shopper_id
                              WHERE o.order_id = ?");
        $stmt->execute([$order_id]);
        $pedido = $stmt->fetch();

        if (!$pedido) return null;

        // Itens do pedido
        $stmtItens = $db->prepare("SELECT i.*, p.name, p.image FROM om_market_order_items i
                             INNER JOIN om_market_products p ON i.product_id = p.product_id
                             WHERE i.order_id = ?");
        $stmtItens->execute([$order_id]);
        $itens = $stmtItens->fetchAll();

        // ── BoraUm driver data ──────────────────────────────────────────
        $driver = null;
        $isBoraum = ($pedido['delivery_type'] ?? '') === 'boraum';

        if ($isBoraum) {
            // 1) Driver info from om_market_orders (set by webhook on driver_accepted)
            $driverName  = $pedido['driver_name'] ?? null;
            $driverPhone = $pedido['driver_phone'] ?? null;
            $driverPhoto = $pedido['driver_photo'] ?? null;
            $driverVehicle = null;

            // 2) Enrich from om_entregas (has motorista_veiculo and is the source of truth)
            try {
                $stmtEntrega = $db->prepare("
                    SELECT motorista_nome, motorista_telefone, motorista_foto, motorista_veiculo,
                           boraum_status, status AS delivery_status
                    FROM om_entregas
                    WHERE referencia_id = ? AND origem_sistema = 'mercado'
                    ORDER BY id DESC LIMIT 1
                ");
                $stmtEntrega->execute([$order_id]);
                $entrega = $stmtEntrega->fetch();
                if ($entrega) {
                    $driverName    = $entrega['motorista_nome']     ?: $driverName;
                    $driverPhone   = $entrega['motorista_telefone'] ?: $driverPhone;
                    $driverPhoto   = $entrega['motorista_foto']     ?: $driverPhoto;
                    $driverVehicle = $entrega['motorista_veiculo']  ?: null;
                }
            } catch (Exception $e) {
                // om_entregas may not exist yet, ignore
            }

            // 3) Latest real-time position: prefer om_delivery_tracking_live, fallback om_market_delivery_tracking
            $driverLat = null;
            $driverLng = null;
            $locationUpdatedAt = null;

            try {
                $stmtLive = $db->prepare("
                    SELECT latitude, longitude, heading, speed, eta_minutes, distance_km, updated_at
                    FROM om_delivery_tracking_live
                    WHERE order_id = ?
                ");
                $stmtLive->execute([$order_id]);
                $live = $stmtLive->fetch();
                if ($live && $live['latitude']) {
                    $driverLat = (float)$live['latitude'];
                    $driverLng = (float)$live['longitude'];
                    $locationUpdatedAt = $live['updated_at'];
                }
            } catch (Exception $e) {
                // table may not exist
            }

            // Fallback to om_market_delivery_tracking
            if (!$driverLat) {
                try {
                    $stmtTrack = $db->prepare("
                        SELECT last_lat, last_lng, last_location_at
                        FROM om_market_delivery_tracking
                        WHERE order_id = ?
                        ORDER BY last_location_at DESC
                        LIMIT 1
                    ");
                    $stmtTrack->execute([$order_id]);
                    $trackRow = $stmtTrack->fetch();
                    if ($trackRow && $trackRow['last_lat']) {
                        $driverLat = (float)$trackRow['last_lat'];
                        $driverLng = (float)$trackRow['last_lng'];
                        $locationUpdatedAt = $trackRow['last_location_at'];
                    }
                } catch (Exception $e) {
                    // table may not exist
                }
            }

            // Build driver object only if we have at least a name
            if ($driverName) {
                // Calculate ETA if we have driver position and delivery address coords
                $eta = null;
                $distance = null;
                if ($driverLat && $pedido['shipping_lat']) {
                    $R = 6371;
                    $dLat = deg2rad($pedido['shipping_lat'] - $driverLat);
                    $dLng = deg2rad($pedido['shipping_lng'] - $driverLng);
                    $a = sin($dLat / 2) ** 2
                       + cos(deg2rad($driverLat)) * cos(deg2rad($pedido['shipping_lat']))
                       * sin($dLng / 2) ** 2;
                    $distance = round($R * 2 * atan2(sqrt($a), sqrt(1 - $a)), 2);
                    $eta = max(1, (int)round($distance * 4)); // ~15 km/h urban avg
                }

                // Use live ETA if available and more accurate
                if (isset($live) && $live && $live['eta_minutes']) {
                    $eta = (int)$live['eta_minutes'];
                }
                if (isset($live) && $live && $live['distance_km']) {
                    $distance = (float)$live['distance_km'];
                }

                $driver = [
                    'name'       => $driverName,
                    'phone'      => $driverPhone,
                    'photo'      => $driverPhoto,
                    'vehicle'    => $driverVehicle,
                    'latitude'   => $driverLat,
                    'longitude'  => $driverLng,
                    'location_updated_at' => $locationUpdatedAt,
                    'eta_minutes' => $eta,
                    'distance_km' => $distance,
                ];
            }
        }

        return ['pedido' => $pedido, 'itens' => $itens, 'driver' => $driver];
    });

    if (!$data) response(false, null, "Pedido não encontrado", 404);

    $pedido = $data['pedido'];
    $itens = $data['itens'];
    $driver = $data['driver'] ?? null;

    // Calcular tempo restante
    $tempo_restante = null;
    if ($pedido["timer_expires"]) {
        $fim = strtotime($pedido["timer_expires"]);
        $agora = time();
        $tempo_restante = max(0, round(($fim - $agora) / 60));
    }

    $isBoraum = ($pedido['delivery_type'] ?? '') === 'boraum';

    // Smart ETA: use driver ETA if available, otherwise calculate from historical data
    $etaMinutes = null;
    $activeStatuses = ['pendente', 'confirmado', 'aceito', 'preparando', 'em_preparo', 'pronto', 'saiu_entrega', 'em_entrega', 'coletando'];
    if (in_array($pedido['status'], $activeStatuses)) {
        if ($driver && !empty($driver['eta_minutes'])) {
            $etaMinutes = (int)$driver['eta_minutes'];
        } else {
            $distKm = isset($pedido['distancia_km']) ? (float)$pedido['distancia_km'] : 5.0;
            $etaMinutes = calculateSmartETA($db, (int)$pedido['partner_id'], $distKm, $pedido['status']);
        }
    }

    response(true, [
        "order_id" => $pedido["order_id"],
        "status" => $pedido["status"],
        "delivery_type" => $pedido["delivery_type"] ?? null,
        "codigo_entrega" => in_array($pedido["status"], ['em_entrega', 'saiu_entrega', 'entregue', 'retirado']) ? $pedido["codigo_entrega"] : null,
        "total" => floatval($pedido["total"]),
        "tempo_restante_min" => $tempo_restante,
        "eta_minutes" => $etaMinutes,
        "parceiro" => [
            "nome" => $pedido["parceiro_nome"],
            "logo" => $pedido["parceiro_logo"],
            "latitude" => isset($pedido["parceiro_lat"]) ? (float)$pedido["parceiro_lat"] : null,
            "longitude" => isset($pedido["parceiro_lng"]) ? (float)$pedido["parceiro_lng"] : null
        ],
        "shopper" => $pedido["shopper_id"] ? [
            "nome" => $pedido["shopper_nome"],
            "telefone" => $pedido["shopper_telefone"],
            "foto" => $pedido["shopper_foto"],
            "latitude" => in_array($pedido["status"], ['coletando', 'em_coleta', 'em_entrega', 'saiu_entrega']) ? $pedido["shopper_lat"] : null,
            "longitude" => in_array($pedido["status"], ['coletando', 'em_coleta', 'em_entrega', 'saiu_entrega']) ? $pedido["shopper_lng"] : null,
        ] : null,
        "driver" => $driver,
        "endereco" => $pedido["delivery_address"],
        "endereco_lat" => isset($pedido["shipping_lat"]) ? (float)$pedido["shipping_lat"] : null,
        "endereco_lng" => isset($pedido["shipping_lng"]) ? (float)$pedido["shipping_lng"] : null,
        "itens" => $itens,
        "timeline" => [
            "criado" => $pedido["date_added"],
            "aceito" => $pedido["accepted_at"] ?? null,
            "coleta_inicio" => $pedido["coleta_iniciada_em"],
            "coleta_fim" => $pedido["coleta_finalizada_em"],
            "entrega_inicio" => $pedido["entrega_iniciada_em"],
            "entregue" => $pedido["entrega_finalizada_em"]
        ]
    ]);
    
} catch (Exception $e) {
    error_log("[pedido/status] Erro: " . $e->getMessage());
    response(false, null, 'Erro interno do servidor', 500);
}
