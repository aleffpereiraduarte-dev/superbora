<?php
/**
 * API: Delivery Location - GPS do entregador em tempo real
 * GET: /api/delivery-location.php?order_id=X
 * POST: /api/delivery-location.php (delivery_id, lat, lng)
 */
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }

require_once dirname(__DIR__) . "/config/database.php";

try {
    $pdo = getPDO();
} catch (PDOException $e) {
    echo json_encode(["success" => false]); exit;
}

// GET - Buscar localização
if ($_SERVER["REQUEST_METHOD"] === "GET") {
    $order_id = (int)($_GET["order_id"] ?? 0);
    
    if (!$order_id) {
        echo json_encode(["success" => false, "error" => "order_id required"]); exit;
    }
    
    // Buscar delivery do pedido
    $stmt = $pdo->prepare("
        SELECT d.delivery_id, d.name, d.lat, d.lng, d.last_location_at, d.vehicle_type,
               o.customer_lat, o.customer_lng, o.status
        FROM om_market_orders o
        JOIN om_market_deliveries d ON o.delivery_id = d.delivery_id
        WHERE o.order_id = ?
    ");
    $stmt->execute([$order_id]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$data) {
        echo json_encode(["success" => false, "error" => "Pedido ou delivery não encontrado"]); exit;
    }
    
    // Calcular distância e ETA
    $distance = null;
    $eta = null;
    
    if ($data['lat'] && $data['lng'] && $data['customer_lat'] && $data['customer_lng']) {
        $lat1 = deg2rad($data['lat']);
        $lat2 = deg2rad($data['customer_lat']);
        $lng1 = deg2rad($data['lng']);
        $lng2 = deg2rad($data['customer_lng']);
        
        $dlat = $lat2 - $lat1;
        $dlng = $lng2 - $lng1;
        
        $a = sin($dlat/2)**2 + cos($lat1) * cos($lat2) * sin($dlng/2)**2;
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        $distance = round(6371 * $c, 2); // km
        
        $speed = $data['vehicle_type'] === 'bike' ? 15 : ($data['vehicle_type'] === 'moto' ? 25 : 20);
        $eta = ceil(($distance / $speed) * 60); // minutos
    }
    
    echo json_encode([
        "success" => true,
        "delivery" => [
            "name" => $data['name'],
            "vehicle" => $data['vehicle_type'],
            "lat" => (float)$data['lat'],
            "lng" => (float)$data['lng'],
            "updated_at" => $data['last_location_at']
        ],
        "destination" => [
            "lat" => (float)$data['customer_lat'],
            "lng" => (float)$data['customer_lng']
        ],
        "distance_km" => $distance,
        "eta_minutes" => $eta,
        "order_status" => $data['status']
    ]);
    exit;
}

// POST - Atualizar localização
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $input = json_decode(file_get_contents("php://input"), true) ?: $_POST;
    
    $delivery_id = (int)($input["delivery_id"] ?? 0);
    $lat = (float)($input["lat"] ?? 0);
    $lng = (float)($input["lng"] ?? 0);
    
    if (!$delivery_id || !$lat || !$lng) {
        echo json_encode(["success" => false, "error" => "Dados incompletos"]); exit;
    }
    
    $stmt = $pdo->prepare("
        UPDATE om_market_deliveries 
        SET lat = ?, lng = ?, last_location_at = NOW()
        WHERE delivery_id = ?
    ");
    $stmt->execute([$lat, $lng, $delivery_id]);
    
    // Log de localização
    try {
        $stmt = $pdo->prepare("
            INSERT INTO om_delivery_locations (delivery_id, lat, lng, created_at)
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$delivery_id, $lat, $lng]);
    } catch (Exception $e) {}
    
    echo json_encode(["success" => true]);
}
