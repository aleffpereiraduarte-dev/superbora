<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * GET /api/mercado/vitrine/order-tracking.php?order_id=X
 * Retorna dados completos de rastreamento para o cliente
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * REQUER: Autenticacao de Customer (JWT)
 * Header: Authorization: Bearer <token>
 *
 * Response: {
 *   "success": true,
 *   "data": {
 *     "order": { ... },
 *     "driver": { ... },
 *     "tracking": { ... },
 *     "pusher": { ... },
 *     "route": { ... }
 *   }
 * }
 */

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../helpers/rate-limit.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/PusherService.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    // Rate limiting: 20 tracking requests per 15 minutes per IP
    $ip = getRateLimitIP();
    if (!checkRateLimit("order_tracking_{$ip}", 20, 15)) {
        response(false, null, "Muitas requisicoes. Tente novamente em 15 minutos.", 429);
    }

    // ═══════════════════════════════════════════════════════════════════
    // AUTENTICACAO - Requer JWT de customer
    // ═══════════════════════════════════════════════════════════════════
    $customerId = null;

    // Autenticacao por token JWT
    $token = om_auth()->getTokenFromRequest();
    if ($token) {
        $payload = om_auth()->validateToken($token);
        if ($payload && $payload['type'] === 'customer') {
            $customerId = (int)$payload['uid'];
        }
    }

    if (!$customerId) {
        response(false, null, "Autenticacao necessaria", 401);
    }

    $orderId = (int)($_GET['order_id'] ?? 0);
    if (!$orderId) {
        response(false, null, "order_id e obrigatorio", 400);
    }

    // ═══════════════════════════════════════════════════════════════════
    // BUSCAR PEDIDO
    // ═══════════════════════════════════════════════════════════════════
    $sql = "
        SELECT o.order_id, o.order_number, o.status, o.shopper_id,
               o.shipping_lat, o.shipping_lng, o.delivery_address,
               o.partner_id, o.customer_id,
               o.route_id, o.route_stop_sequence,
               o.created_at, COALESCE(o.date_modified, o.created_at) AS updated_at,
               p.latitude AS partner_lat, p.longitude AS partner_lng,
               p.name AS partner_name, p.logo AS partner_logo, p.address AS partner_address
        FROM om_market_orders o
        LEFT JOIN om_market_partners p ON p.partner_id = o.partner_id
        WHERE o.order_id = ?
    ";

    // Verificacao de ownership
    $sql .= " AND o.customer_id = ?";
    $stmt = $db->prepare($sql);
    $stmt->execute([$orderId, $customerId]);

    $order = $stmt->fetch();

    if (!$order) {
        response(false, null, "Pedido nao encontrado ou acesso negado", 404);
    }

    // ═══════════════════════════════════════════════════════════════════
    // MONTAR RESPOSTA BASE
    // ═══════════════════════════════════════════════════════════════════
    $data = [
        'order' => [
            'id' => (int)$order['order_id'],
            'number' => $order['order_number'],
            'status' => $order['status'],
            'status_label' => getStatusLabel($order['status']),
            'created_at' => $order['created_at'],
            'updated_at' => $order['updated_at']
        ],
        'destination' => [
            'lat' => (float)$order['shipping_lat'],
            'lng' => (float)$order['shipping_lng'],
            'address' => $order['delivery_address']
        ],
        'partner' => [
            'id' => (int)$order['partner_id'],
            'name' => $order['partner_name'],
            'logo' => $order['partner_logo'],
            'address' => $order['partner_address'],
            'lat' => (float)$order['partner_lat'],
            'lng' => (float)$order['partner_lng']
        ],
        'driver' => null,
        'tracking' => null,
        'pusher' => PusherService::isConfigured() ? [
            'channel' => "order-{$orderId}",
            'events' => ['location-update', 'status-update', 'driver-arriving', 'driver-arrived']
        ] : null,
        'route' => null
    ];

    // ═══════════════════════════════════════════════════════════════════
    // BUSCAR DADOS DO ENTREGADOR
    // ═══════════════════════════════════════════════════════════════════
    if ($order['shopper_id']) {
        $stmt = $db->prepare("
            SELECT s.shopper_id, s.nome, s.foto, s.telefone,
                   s.veiculo, s.placa, s.cor_veiculo, s.avaliacao_media,
                   s.latitude, s.longitude, s.ultima_atividade
            FROM om_market_shoppers s
            WHERE s.shopper_id = ?
        ");
        $stmt->execute([$order['shopper_id']]);
        $driver = $stmt->fetch();

        if ($driver) {
            $data['driver'] = [
                'id' => (int)$driver['shopper_id'],
                'name' => $driver['nome'],
                'photo' => $driver['foto'],
                'phone' => maskPhone($driver['telefone']),
                'vehicle' => [
                    'type' => $driver['veiculo'] ?? 'moto',
                    'plate' => $driver['placa'],
                    'color' => $driver['cor_veiculo']
                ],
                'rating' => (float)($driver['avaliacao_media'] ?? 5.0),
                'current_location' => [
                    'lat' => (float)$driver['latitude'],
                    'lng' => (float)$driver['longitude'],
                    'updated_at' => $driver['ultima_atividade']
                ]
            ];
        }

        // ═══════════════════════════════════════════════════════════════════
        // BUSCAR TRACKING LIVE (ultima posicao em tempo real)
        // ═══════════════════════════════════════════════════════════════════
        $stmt = $db->prepare("
            SELECT latitude, longitude, heading, speed, accuracy,
                   eta_minutes, distance_km, status, updated_at
            FROM om_delivery_tracking_live
            WHERE order_id = ?
        ");
        $stmt->execute([$orderId]);
        $tracking = $stmt->fetch();

        if ($tracking) {
            $data['tracking'] = [
                'lat' => (float)$tracking['latitude'],
                'lng' => (float)$tracking['longitude'],
                'heading' => $tracking['heading'] ? (int)$tracking['heading'] : null,
                'speed' => $tracking['speed'] ? (float)$tracking['speed'] : null,
                'accuracy' => $tracking['accuracy'] ? (float)$tracking['accuracy'] : null,
                'eta_minutes' => $tracking['eta_minutes'] ? (int)$tracking['eta_minutes'] : null,
                'distance_km' => $tracking['distance_km'] ? (float)$tracking['distance_km'] : null,
                'status' => $tracking['status'],
                'updated_at' => $tracking['updated_at']
            ];

            // Atualizar localizacao do driver com dados mais recentes
            if ($data['driver']) {
                $data['driver']['current_location'] = [
                    'lat' => (float)$tracking['latitude'],
                    'lng' => (float)$tracking['longitude'],
                    'heading' => $tracking['heading'] ? (int)$tracking['heading'] : null,
                    'updated_at' => $tracking['updated_at']
                ];
            }
        } else {
            // Fallback: buscar da tabela antiga
            $stmt = $db->prepare("
                SELECT last_lat, last_lng, last_location_at
                FROM om_market_delivery_tracking
                WHERE order_id = ?
            ");
            $stmt->execute([$orderId]);
            $oldTracking = $stmt->fetch();

            if ($oldTracking && $oldTracking['last_lat']) {
                // Calcular ETA
                $eta = null;
                $distance = null;
                if ($order['shipping_lat'] && $oldTracking['last_lat']) {
                    $R = 6371;
                    $dLat = deg2rad($order['shipping_lat'] - $oldTracking['last_lat']);
                    $dLng = deg2rad($order['shipping_lng'] - $oldTracking['last_lng']);
                    $a = sin($dLat / 2) ** 2 +
                         cos(deg2rad($oldTracking['last_lat'])) *
                         cos(deg2rad($order['shipping_lat'])) *
                         sin($dLng / 2) ** 2;
                    $distance = round($R * 2 * atan2(sqrt($a), sqrt(1 - $a)), 2);
                    $eta = max(1, (int)round($distance * 4));
                }

                $data['tracking'] = [
                    'lat' => (float)$oldTracking['last_lat'],
                    'lng' => (float)$oldTracking['last_lng'],
                    'heading' => null,
                    'speed' => null,
                    'eta_minutes' => $eta,
                    'distance_km' => $distance,
                    'status' => $order['status'],
                    'updated_at' => $oldTracking['last_location_at']
                ];
            }
        }

        // ═══════════════════════════════════════════════════════════════════
        // CALCULAR ROTA (pontos para desenhar no mapa)
        // ═══════════════════════════════════════════════════════════════════
        if ($data['tracking'] && $data['destination']['lat']) {
            $data['route'] = [
                'origin' => [
                    'lat' => $data['tracking']['lat'],
                    'lng' => $data['tracking']['lng']
                ],
                'destination' => [
                    'lat' => $data['destination']['lat'],
                    'lng' => $data['destination']['lng']
                ],
                'waypoints' => []
            ];

            // Buscar waypoints da rota multi-stop
            if ($order['route_id']) {
                $stmtWp = $db->prepare("
                    SELECT rs.stop_sequence, rs.partner_lat, rs.partner_lng,
                           rs.partner_name, rs.stop_type, rs.status,
                           rs.order_id AS stop_order_id
                    FROM om_delivery_route_stops rs
                    WHERE rs.route_id = ?
                    ORDER BY rs.stop_sequence ASC
                ");
                $stmtWp->execute([$order['route_id']]);
                $stops = $stmtWp->fetchAll();

                if ($stops) {
                    $data['route']['waypoints'] = array_map(function($s) {
                        return [
                            'lat' => (float)$s['partner_lat'],
                            'lng' => (float)$s['partner_lng'],
                            'label' => $s['partner_name'],
                            'stop_sequence' => (int)$s['stop_sequence'],
                            'stop_type' => $s['stop_type'],
                            'status' => $s['status'],
                            'order_id' => (int)$s['stop_order_id'],
                        ];
                    }, $stops);
                }
            }
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // BUSCAR HISTORICO DE LOCALIZACOES (ultimos 10 pontos para smooth animation)
    // ═══════════════════════════════════════════════════════════════════
    $stmt = $db->prepare("
        SELECT lat, lng, heading, speed, recorded_at
        FROM om_delivery_locations
        WHERE order_id = ?
        ORDER BY recorded_at DESC
        LIMIT 10
    ");
    $stmt->execute([$orderId]);
    $history = $stmt->fetchAll();

    if ($history) {
        $data['location_history'] = array_map(function($loc) {
            return [
                'lat' => (float)$loc['lat'],
                'lng' => (float)$loc['lng'],
                'heading' => $loc['heading'] ? (int)$loc['heading'] : null,
                'speed' => $loc['speed'] ? (float)$loc['speed'] : null,
                'timestamp' => $loc['recorded_at']
            ];
        }, array_reverse($history));
    }

    response(true, $data);

} catch (Exception $e) {
    error_log("[vitrine/order-tracking] Erro: " . $e->getMessage());
    response(false, null, "Erro ao buscar rastreamento", 500);
}

// ═══════════════════════════════════════════════════════════════════
// FUNCOES AUXILIARES
// ═══════════════════════════════════════════════════════════════════

function getStatusLabel(string $status): string {
    $labels = [
        'pending' => 'Aguardando confirmacao',
        'pendente' => 'Aguardando confirmacao',
        'confirmed' => 'Confirmado',
        'confirmado' => 'Confirmado',
        'preparing' => 'Em preparo',
        'preparando' => 'Em preparo',
        'aceito' => 'Aceito pelo entregador',
        'coletando' => 'Entregador coletando',
        'coleta_finalizada' => 'Coleta finalizada',
        'em_entrega' => 'A caminho',
        'ready' => 'Pronto para entrega',
        'pronto' => 'Pronto para entrega',
        'out_for_delivery' => 'Saiu para entrega',
        'delivered' => 'Entregue',
        'entregue' => 'Entregue',
        'cancelled' => 'Cancelado',
        'cancelado' => 'Cancelado'
    ];

    return $labels[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

function maskPhone(?string $phone): ?string {
    if (!$phone) return null;
    // Mostrar apenas ultimos 4 digitos: (**) *****-1234
    $clean = preg_replace('/\D/', '', $phone);
    if (strlen($clean) < 4) return $phone;
    $last4 = substr($clean, -4);
    return "(**) *****-{$last4}";
}
