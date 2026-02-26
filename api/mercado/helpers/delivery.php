<?php
/**
 * Helper de despacho de entrega (auto-chamar driver BoraUm)
 * Uso: require_once e chamar dispatchToBoraUm()
 */

/**
 * Carrega variavel do .env
 */
function loadEnvVar(string $key): string {
    if (!empty($_ENV[$key])) return $_ENV[$key];
    if (!empty(getenv($key))) return getenv($key);

    // SECURITY: Cache parsed .env in static var to avoid re-parsing on every call
    static $envCache = null;
    if ($envCache === null) {
        $envCache = [];
        $envFile = dirname(__DIR__, 3) . '/.env';
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos(trim($line), '#') === 0) continue;
                if (strpos($line, '=') !== false) {
                    list($k, $v) = explode('=', $line, 2);
                    $v = trim($v);
                    // Strip surrounding quotes
                    $v = trim($v, '"\'');
                    $envCache[trim($k)] = $v;
                }
            }
        }
    }
    return $envCache[$key] ?? '';
}

/**
 * Despacha entrega para o BoraUm automaticamente
 *
 * @param PDO $db
 * @param array $pedido Array com dados do pedido (order_id, partner_id, customer_id, etc)
 * @return array ['success' => bool, 'entrega_id' => int|null, 'message' => string]
 */
function dispatchToBoraUm(PDO $db, array $pedido): array {
    try {
        $order_id = (int)($pedido['order_id'] ?? 0);
        $partner_id = (int)($pedido['partner_id'] ?? 0);
        $customer_id = (int)($pedido['customer_id'] ?? 0);

        if (!$order_id || !$partner_id) {
            return ['success' => false, 'entrega_id' => null, 'message' => 'Dados do pedido incompletos'];
        }

        // Wrap in transaction to prevent duplicate deliveries
        $hadTransaction = $db->inTransaction();
        if (!$hadTransaction) {
            $db->beginTransaction();
        }

        // Lock order row to prevent concurrent dispatch
        $stmtLock = $db->prepare("SELECT order_id FROM om_market_orders WHERE order_id = ? FOR UPDATE");
        $stmtLock->execute([$order_id]);
        if (!$stmtLock->fetch()) {
            if (!$hadTransaction) $db->rollBack();
            return ['success' => false, 'entrega_id' => null, 'message' => 'Pedido nao encontrado'];
        }

        // Verificar se ja existe entrega para este pedido (with lock held)
        $stmt = $db->prepare("SELECT id FROM om_entregas WHERE referencia_id = ? AND origem_sistema = 'mercado' LIMIT 1");
        $stmt->execute([$order_id]);
        if ($stmt->fetch()) {
            if (!$hadTransaction) $db->rollBack();
            return ['success' => false, 'entrega_id' => null, 'message' => 'Entrega ja criada para este pedido'];
        }

        // Buscar dados do parceiro
        $stmt = $db->prepare("SELECT partner_id, name, trade_name, phone, telefone, address, endereco, latitude, lat, longitude, lng, city, state FROM om_market_partners WHERE partner_id = ?");
        $stmt->execute([$partner_id]);
        $parceiro = $stmt->fetch();
        if (!$parceiro) {
            if (!$hadTransaction) $db->rollBack();
            return ['success' => false, 'entrega_id' => null, 'message' => 'Parceiro nao encontrado'];
        }

        // Dados do pedido
        $endereco_cliente = $pedido['delivery_address'] ?? $pedido['shipping_address'] ?? '';
        $lat_cliente = $pedido['latitude_entrega'] ?? $pedido['shipping_latitude'] ?? null;
        $lng_cliente = $pedido['longitude_entrega'] ?? $pedido['shipping_longitude'] ?? null;
        $valor_produto = floatval($pedido['subtotal'] ?? $pedido['total'] ?? 0);
        $valor_frete = floatval($pedido['delivery_fee'] ?? 0);

        // Gerar codigos
        $qr_coleta = strtoupper(bin2hex(random_bytes(3)));
        $pin_entrega = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // Criar entrega na tabela om_entregas (registro local)
        $stmt = $db->prepare("
            INSERT INTO om_entregas
            (tipo, origem_sistema, referencia_id, remetente_tipo, remetente_id, remetente_nome, remetente_telefone,
             coleta_endereco, coleta_lat, coleta_lng,
             destinatario_nome, destinatario_telefone,
             entrega_endereco, entrega_lat, entrega_lng,
             descricao, valor_declarado, valor_frete,
             qr_coleta, pin_entrega, metodo_entrega, status)
            VALUES ('envio', 'mercado', ?, 'loja', ?, ?, ?,
                    ?, ?, ?,
                    ?, ?,
                    ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, 'driver', 'pendente')
            RETURNING id
        ");
        $stmt->execute([
            $order_id, $partner_id,
            $parceiro['name'] ?? $parceiro['nome'] ?? '', $parceiro['phone'] ?? $parceiro['telefone'] ?? '',
            $parceiro['address'] ?? $parceiro['endereco'] ?? '',
            $parceiro['latitude'] ?? $parceiro['lat'] ?? null,
            $parceiro['longitude'] ?? $parceiro['lng'] ?? null,
            $pedido['customer_name'] ?? '', $pedido['customer_phone'] ?? '',
            $endereco_cliente, $lat_cliente, $lng_cliente,
            "Pedido #" . ($pedido['order_number'] ?? $order_id), $valor_produto, $valor_frete,
            $qr_coleta, $pin_entrega
        ]);

        $entrega_id = (int)$stmt->fetchColumn();

        // Atualizar pedido com status de entrega
        $stmt = $db->prepare("UPDATE om_market_orders SET status = 'aguardando_entregador', delivery_type = 'boraum' WHERE order_id = ?");
        $stmt->execute([$order_id]);

        // Commit local DB work before API call (don't hold locks during external call)
        if (!$hadTransaction) {
            $db->commit();
        }

        error_log("[delivery] Entrega #$entrega_id criada para pedido #$order_id | BoraUm dispatch");

        // =====================================================================
        // CHAMAR API REAL DO BORAUM
        // =====================================================================
        $boraUmKey = loadEnvVar('BORAUM_API_KEY');

        if (empty($boraUmKey)) {
            error_log("[delivery] BORAUM_API_KEY nao configurada. Entrega criada localmente sem despacho API.");
            return [
                'success' => true,
                'entrega_id' => $entrega_id,
                'qr_coleta' => $qr_coleta,
                'pin_entrega' => $pin_entrega,
                'boraum_dispatched' => false,
                'message' => 'Entrega criada localmente (API key nao configurada)'
            ];
        }

        $pickupAddress = $parceiro['address'] ?? $parceiro['endereco'] ?? '';
        $pickupLat = (float)($parceiro['latitude'] ?? $parceiro['lat'] ?? 0);
        $pickupLng = (float)($parceiro['longitude'] ?? $parceiro['lng'] ?? 0);
        $dropoffLat = (float)($lat_cliente ?: 0);
        $dropoffLng = (float)($lng_cliente ?: 0);

        // Validar coordenadas: nao enviar motorista pra Null Island (0,0)
        if (($pickupLat == 0 && $pickupLng == 0) || ($dropoffLat == 0 && $dropoffLng == 0)) {
            error_log("[delivery] WARN: Coordenadas invalidas para pedido #$order_id — pickup=({$pickupLat},{$pickupLng}), dropoff=({$dropoffLat},{$dropoffLng}). Entrega criada sem dispatch API.");
            return [
                'success' => true,
                'entrega_id' => $entrega_id,
                'qr_coleta' => $qr_coleta,
                'pin_entrega' => $pin_entrega,
                'boraum_dispatched' => false,
                'message' => 'Coordenadas incompletas — entrega criada localmente, dispatch manual necessario'
            ];
        }

        // Calcular custo real BoraUm por distancia (nao repassar frete cheio do cliente)
        $boraum_cost = calcularCustoBoraUm($pickupLat, $pickupLng, $dropoffLat, $dropoffLng);
        $delivery_margin = max(0, round($valor_frete - $boraum_cost, 2));

        // Salvar margem na entrega local
        try {
            $db->prepare("UPDATE om_entregas SET taxa_plataforma = ?, distancia_km = ? WHERE id = ?")
               ->execute([$delivery_margin, calcularDistanciaKm($pickupLat, $pickupLng, $dropoffLat, $dropoffLng), $entrega_id]);
        } catch (Exception $e) {
            error_log("[delivery] Erro salvar margem: " . $e->getMessage());
        }

        error_log("[delivery] Pedido #$order_id: frete_cliente=R$$valor_frete, custo_boraum=R$$boraum_cost, margem=R$$delivery_margin");

        $payload = [
            'external_id' => $pedido['order_number'] ?? ('SB-' . $order_id),
            'pickup' => [
                'address' => $pickupAddress,
                'lat' => $pickupLat,
                'lng' => $pickupLng,
                'contact_name' => $parceiro['name'] ?? $parceiro['nome'] ?? '',
                'contact_phone' => $parceiro['phone'] ?? $parceiro['telefone'] ?? ''
            ],
            'dropoff' => [
                'address' => $endereco_cliente,
                'lat' => $dropoffLat,
                'lng' => $dropoffLng,
                'contact_name' => $pedido['customer_name'] ?? '',
                'contact_phone' => $pedido['customer_phone'] ?? ''
            ],
            'package' => [
                'description' => 'Pedido #' . ($pedido['order_number'] ?? $order_id),
                'weight_kg' => 2.0
            ],
            'price' => $boraum_cost,
            'vehicle_type' => 'moto',
            'pickup_code' => $qr_coleta,
            'delivery_code' => $pin_entrega
        ];

        $ch = curl_init('https://boraum.com.br/api/partner/deliveries');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-API-Key: ' . $boraUmKey
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            error_log("[delivery] BoraUm cURL error: $curlErr (pedido #$order_id)");
            return [
                'success' => true,
                'entrega_id' => $entrega_id,
                'qr_coleta' => $qr_coleta,
                'pin_entrega' => $pin_entrega,
                'boraum_dispatched' => false,
                'message' => 'Entrega criada, erro ao contactar BoraUm: ' . $curlErr
            ];
        }

        $data = json_decode($response, true);
        $safeLog = ["delivery_id" => $data["delivery_id"] ?? null, "status" => $data["status"] ?? null, "error" => $data["error"] ?? $data["message"] ?? null];
        error_log("[delivery] BoraUm HTTP $httpCode | Response: " . json_encode($safeLog));

        if ($httpCode >= 200 && $httpCode < 300 && !empty($data['delivery_id'])) {
            // Sucesso - salvar delivery_id do BoraUm
            $db->prepare("UPDATE om_entregas SET boraum_delivery_id = ?, boraum_status = ? WHERE id = ?")
               ->execute([$data['delivery_id'], $data['status'] ?? 'searching', $entrega_id]);

            $db->prepare("UPDATE om_market_orders SET boraum_pedido_id = ? WHERE order_id = ?")
               ->execute([$data['delivery_id'], $order_id]);

            error_log("[delivery] BoraUm dispatch OK: delivery_id={$data['delivery_id']} para pedido #$order_id");

            return [
                'success' => true,
                'entrega_id' => $entrega_id,
                'qr_coleta' => $qr_coleta,
                'pin_entrega' => $pin_entrega,
                'boraum_dispatched' => true,
                'boraum_delivery_id' => $data['delivery_id'],
                'message' => 'Entrega criada e despachada para BoraUm'
            ];
        }

        // API retornou erro mas entrega local foi criada
        $errMsg = $data['message'] ?? $data['error'] ?? "HTTP $httpCode";
        error_log("[delivery] BoraUm dispatch falhou: $errMsg (pedido #$order_id)");

        return [
            'success' => true,
            'entrega_id' => $entrega_id,
            'qr_coleta' => $qr_coleta,
            'pin_entrega' => $pin_entrega,
            'boraum_dispatched' => false,
            'message' => 'Entrega criada, BoraUm retornou: ' . $errMsg
        ];

    } catch (Exception $e) {
        if ($db->inTransaction() && !$hadTransaction) {
            $db->rollBack();
        }
        error_log("[delivery] Erro ao despachar: " . $e->getMessage());
        return ['success' => false, 'entrega_id' => null, 'message' => 'Erro ao criar entrega'];
    }
}

/**
 * Cancela entrega BoraUm (quando pedido e cancelado)
 */
function cancelBoraUmDelivery(PDO $db, int $orderId): array {
    try {
        // Buscar entrega BoraUm ativa para este pedido
        $stmt = $db->prepare("
            SELECT id, boraum_delivery_id, boraum_status
            FROM om_entregas
            WHERE referencia_id = ? AND origem_sistema = 'mercado' AND boraum_delivery_id IS NOT NULL
            LIMIT 1
        ");
        $stmt->execute([$orderId]);
        $entrega = $stmt->fetch();

        if (!$entrega || empty($entrega['boraum_delivery_id'])) {
            return ['success' => true, 'message' => 'Sem entrega BoraUm para cancelar'];
        }

        // Se ja foi entregue ou cancelada, nao fazer nada
        if (in_array($entrega['boraum_status'], ['delivered', 'cancelled', 'canceled'])) {
            return ['success' => true, 'message' => 'Entrega BoraUm ja finalizada'];
        }

        $boraUmKey = loadEnvVar('BORAUM_API_KEY');
        if (empty($boraUmKey)) {
            // Apenas atualizar status local
            $db->prepare("UPDATE om_entregas SET status = 'cancelado', boraum_status = 'cancelled' WHERE id = ?")
               ->execute([$entrega['id']]);
            return ['success' => true, 'message' => 'Entrega cancelada localmente (sem API key)'];
        }

        // Chamar API do BoraUm para cancelar
        $ch = curl_init('https://boraum.com.br/api/partner/deliveries/' . $entrega['boraum_delivery_id'] . '/cancel');
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-API-Key: ' . $boraUmKey
            ],
            CURLOPT_POSTFIELDS => json_encode(['reason' => 'Pedido cancelado pelo cliente']),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Atualizar status local independente do resultado da API
        $db->prepare("UPDATE om_entregas SET status = 'cancelado', boraum_status = 'cancelled' WHERE id = ?")
           ->execute([$entrega['id']]);

        error_log("[delivery] BoraUm cancel pedido #$orderId: HTTP $httpCode | " . substr($response, 0, 200));

        return [
            'success' => true,
            'boraum_cancelled' => ($httpCode >= 200 && $httpCode < 300),
            'message' => 'Entrega BoraUm cancelada'
        ];

    } catch (Exception $e) {
        error_log("[delivery] Erro cancelar BoraUm pedido #$orderId: " . $e->getMessage());
        return ['success' => false, 'message' => 'Erro ao cancelar entrega: ' . $e->getMessage()];
    }
}

/**
 * Calcula distancia em km entre dois pontos (Haversine)
 */
function calcularDistanciaKm(float $lat1, float $lng1, float $lat2, float $lng2): float {
    if ($lat1 == 0 || $lng1 == 0 || $lat2 == 0 || $lng2 == 0) return 3.0; // fallback 3km

    $R = 6371; // Raio da Terra em km
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) * sin($dLat / 2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLng / 2) * sin($dLng / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return round($R * $c, 2);
}

/**
 * Calcula custo real do BoraUm baseado em distancia
 * Delegado para OmPricing (fonte unica de verdade)
 */
function calcularCustoBoraUm(float $pickupLat, float $pickupLng, float $dropoffLat, float $dropoffLng): float {
    require_once dirname(__DIR__, 3) . '/includes/classes/OmPricing.php';
    $distancia = calcularDistanciaKm($pickupLat, $pickupLng, $dropoffLat, $dropoffLng);
    return OmPricing::calcularCustoBoraUm($distancia);
}
