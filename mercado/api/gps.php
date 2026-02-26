<?php
/**
 * API GPS Tracking - OneMundo Market
 * Rastreamento em tempo real do entregador
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once dirname(__DIR__) . '/config/database.php';

try {
    $db = getPDO();
} catch (PDOException $e) {
    die(json_encode(['success' => false, 'error' => 'DB Error']));
}

function haversine($lat1, $lon1, $lat2, $lon2) {
    $r = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) * sin($dLon/2);
    return $r * 2 * atan2(sqrt($a), sqrt(1-$a));
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$params = array_merge($_GET, $_POST, $input);

switch ($action) {
    
    case 'update':
        // Entregador envia sua localização
        $workerId = intval($params['worker_id'] ?? 0);
        $orderId = intval($params['order_id'] ?? 0);
        $lat = floatval($params['lat'] ?? 0);
        $lng = floatval($params['lng'] ?? 0);
        $accuracy = floatval($params['accuracy'] ?? 0);
        $speed = floatval($params['speed'] ?? 0);
        $heading = floatval($params['heading'] ?? 0);
        $battery = intval($params['battery'] ?? 100);
        
        if (!$workerId || !$lat || !$lng) {
            echo json_encode(['success' => false, 'error' => 'Missing params']);
            break;
        }
        
        // Atualizar localização atual
        $stmt = $db->prepare("INSERT INTO om_delivery_locations (worker_id, lat, lng, accuracy, speed, heading, is_online) 
            VALUES (?, ?, ?, ?, ?, ?, 1) 
            ON DUPLICATE KEY UPDATE lat = VALUES(lat), lng = VALUES(lng), accuracy = VALUES(accuracy), 
            speed = VALUES(speed), heading = VALUES(heading), is_online = 1");
        $stmt->execute([$workerId, $lat, $lng, $accuracy, $speed, $heading]);
        
        // Se tem pedido ativo, salvar histórico e calcular ETA
        if ($orderId) {
            $stmt = $db->prepare("INSERT INTO om_delivery_tracking 
                (order_id, worker_id, lat, lng, accuracy, speed, heading, battery_level) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$orderId, $workerId, $lat, $lng, $accuracy, $speed, $heading, $battery]);
            
            // Calcular ETA (buscar destino do pedido)
            $stmt = $db->prepare("SELECT a.lat, a.lng FROM om_market_orders o 
                LEFT JOIN oc_address a ON o.shipping_address_id = a.address_id 
                WHERE o.order_id = ?");
            $stmt->execute([$orderId]);
            $dest = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($dest && $dest['lat']) {
                $distance = haversine($lat, $lng, $dest['lat'], $dest['lng']);
                $avgSpeed = $speed > 5 ? $speed : 25; // km/h
                $eta = ceil(($distance / $avgSpeed) * 60); // minutos
                
                $stmt = $db->prepare("UPDATE om_market_orders SET eta_minutes = ? WHERE order_id = ?");
                $stmt->execute([$eta, $orderId]);
            }
        }
        
        echo json_encode(['success' => true]);
        break;
        
    case 'track':
        // Cliente acompanha entregador
        $orderId = intval($params['order_id'] ?? 0);
        
        $stmt = $db->prepare("SELECT o.order_id, o.status, o.eta_minutes, o.delivery_id,
            dl.lat, dl.lng, dl.speed, dl.heading, dl.last_update
            FROM om_market_orders o
            LEFT JOIN om_delivery_locations dl ON o.delivery_id = dl.worker_id
            WHERE o.order_id = ?");
        $stmt->execute([$orderId]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$data) {
            echo json_encode(['success' => false, 'error' => 'Order not found']);
            break;
        }
        
        // Buscar destino
        $stmt = $db->prepare("SELECT a.lat, a.lng FROM om_market_orders o 
            LEFT JOIN oc_address a ON o.shipping_address_id = a.address_id 
            WHERE o.order_id = ?");
        $stmt->execute([$orderId]);
        $dest = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'order_id' => $orderId,
            'status' => $data['status'],
            'eta_minutes' => $data['eta_minutes'],
            'delivery' => [
                'lat' => floatval($data['lat']),
                'lng' => floatval($data['lng']),
                'speed' => floatval($data['speed']),
                'heading' => floatval($data['heading']),
                'last_update' => $data['last_update']
            ],
            'destination' => [
                'lat' => floatval($dest['lat'] ?? 0),
                'lng' => floatval($dest['lng'] ?? 0)
            ]
        ]);
        break;
        
    case 'history':
        // Histórico de rota
        $orderId = intval($params['order_id'] ?? 0);
        $stmt = $db->prepare("SELECT lat, lng, recorded_at FROM om_delivery_tracking 
            WHERE order_id = ? ORDER BY recorded_at ASC LIMIT 1000");
        $stmt->execute([$orderId]);
        
        echo json_encode([
            'success' => true,
            'points' => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ]);
        break;
        
    default:
        echo json_encode(['api' => 'GPS Tracking', 'actions' => ['update', 'track', 'history']]);
}