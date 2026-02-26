<?php
/**
 * ╔══════════════════════════════════════════════════════════════════════════════╗
 * ║  API GPS DELIVERY - ATUALIZAR LOCALIZAÇÃO                                    ║
 * ╠══════════════════════════════════════════════════════════════════════════════╣
 * ║  POST /api/delivery_gps.php - Enviar posição                                 ║
 * ║  GET  /api/delivery_gps.php?order_id=X - Buscar posição do delivery          ║
 * ╚══════════════════════════════════════════════════════════════════════════════╝
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/database.php';

try {
    $pdo = getPDO();
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Erro de conexão']);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════════
// GET - BUSCAR LOCALIZAÇÃO DO DELIVERY
// ═══════════════════════════════════════════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $order_id = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
    
    if (!$order_id) {
        echo json_encode(['success' => false, 'error' => 'order_id obrigatório']);
        exit;
    }
    
    // Buscar pedido e delivery
    $stmt = $pdo->prepare("
        SELECT o.order_id, o.status, o.delivery_id, o.delivery_name, o.delivery_code,
               o.shipping_lat, o.shipping_lng, o.shipping_address, o.shipping_number,
               d.lat, d.lng, d.last_location_at, d.vehicle_type
        FROM om_market_orders o
        LEFT JOIN om_market_deliveries d ON o.delivery_id = d.delivery_id
        WHERE o.order_id = ?
    ");
    $stmt->execute([$order_id]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$data) {
        echo json_encode(['success' => false, 'error' => 'Pedido não encontrado']);
        exit;
    }
    
    // Calcular distância e ETA se tiver coordenadas
    $distance = null;
    $eta_minutes = null;
    
    if ($data['lat'] && $data['lng'] && $data['shipping_lat'] && $data['shipping_lng']) {
        // Fórmula de Haversine simplificada
        $lat1 = deg2rad($data['lat']);
        $lng1 = deg2rad($data['lng']);
        $lat2 = deg2rad($data['shipping_lat']);
        $lng2 = deg2rad($data['shipping_lng']);
        
        $dlat = $lat2 - $lat1;
        $dlng = $lng2 - $lng1;
        
        $a = sin($dlat/2) * sin($dlat/2) + cos($lat1) * cos($lat2) * sin($dlng/2) * sin($dlng/2);
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        $distance = 6371000 * $c; // metros
        
        // Velocidade média por tipo de veículo
        $speeds = [
            'moto' => 25, // km/h em área urbana
            'bike' => 15,
            'car' => 20,
            'foot' => 5
        ];
        $speed_kmh = $speeds[$data['vehicle_type']] ?? 20;
        $speed_mps = $speed_kmh * 1000 / 3600;
        
        $eta_minutes = ceil($distance / $speed_mps / 60);
    }
    
    echo json_encode([
        'success' => true,
        'order_id' => $data['order_id'],
        'status' => $data['status'],
        'delivery' => [
            'id' => $data['delivery_id'],
            'name' => $data['delivery_name'],
            'lat' => $data['lat'] ? (float)$data['lat'] : null,
            'lng' => $data['lng'] ? (float)$data['lng'] : null,
            'last_update' => $data['last_location_at'],
            'vehicle_type' => $data['vehicle_type']
        ],
        'destination' => [
            'lat' => $data['shipping_lat'] ? (float)$data['shipping_lat'] : null,
            'lng' => $data['shipping_lng'] ? (float)$data['shipping_lng'] : null,
            'address' => $data['shipping_address'] . ', ' . $data['shipping_number']
        ],
        'distance_meters' => $distance ? round($distance) : null,
        'eta_minutes' => $eta_minutes
    ]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════════
// POST - ATUALIZAR LOCALIZAÇÃO
// ═══════════════════════════════════════════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) $input = $_POST;
    
    $delivery_id = isset($input['delivery_id']) ? (int)$input['delivery_id'] : 0;
    $lat = isset($input['lat']) ? (float)$input['lat'] : null;
    $lng = isset($input['lng']) ? (float)$input['lng'] : null;
    
    if (!$delivery_id || !$lat || !$lng) {
        echo json_encode(['success' => false, 'error' => 'delivery_id, lat e lng obrigatórios']);
        exit;
    }
    
    // Validar coordenadas
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        echo json_encode(['success' => false, 'error' => 'Coordenadas inválidas']);
        exit;
    }
    
    try {
        // Atualizar localização do delivery
        $stmt = $pdo->prepare("
            UPDATE om_market_deliveries SET
                lat = ?,
                lng = ?,
                last_location_at = NOW(),
                is_online = 1
            WHERE delivery_id = ?
        ");
        $stmt->execute([$lat, $lng, $delivery_id]);
        
        // Salvar histórico (opcional - criar tabela se quiser tracking completo)
        // $pdo->prepare("INSERT INTO om_delivery_gps_history (delivery_id, lat, lng, created_at) VALUES (?, ?, ?, NOW())")->execute([$delivery_id, $lat, $lng]);
        
        // Buscar pedido atual do delivery para notificar
        $stmt = $pdo->prepare("
            SELECT order_id, customer_id, shipping_lat, shipping_lng
            FROM om_market_orders
            WHERE delivery_id = ? AND status = 'delivering'
            LIMIT 1
        ");
        $stmt->execute([$delivery_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $distance = null;
        $eta = null;
        $arriving = false;
        
        if ($order && $order['shipping_lat'] && $order['shipping_lng']) {
            // Calcular distância
            $lat1 = deg2rad($lat);
            $lng1 = deg2rad($lng);
            $lat2 = deg2rad($order['shipping_lat']);
            $lng2 = deg2rad($order['shipping_lng']);
            
            $dlat = $lat2 - $lat1;
            $dlng = $lng2 - $lng1;
            
            $a = sin($dlat/2) * sin($dlat/2) + cos($lat1) * cos($lat2) * sin($dlng/2) * sin($dlng/2);
            $c = 2 * atan2(sqrt($a), sqrt(1-$a));
            $distance = 6371000 * $c;
            
            $eta = ceil($distance / (20 * 1000 / 3600) / 60); // ~20km/h
            
            // Se estiver a menos de 200m, está chegando!
            if ($distance < 200) {
                $arriving = true;
            }
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Localização atualizada',
            'current_order' => $order ? $order['order_id'] : null,
            'distance_meters' => $distance ? round($distance) : null,
            'eta_minutes' => $eta,
            'arriving' => $arriving
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Método não suportado']);
