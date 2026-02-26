<?php
require_once dirname(__DIR__) . '/config/database.php';
/**
 * 🗺️ API DE RASTREAMENTO EM TEMPO REAL
 *
 * Endpoints:
 * - GET ?action=get_location&order_id=X - Posição atual do delivery
 * - POST action=update_location - Delivery atualiza sua posição
 * - GET ?action=get_route&order_id=X - Rota completa
 */

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    exit(0);
}

try {
    $pdo = getPDO();
} catch (PDOException $e) {
    die(json_encode(array("success" => false, "error" => "DB Error")));
}

$action = isset($_GET["action"]) ? $_GET["action"] : "";
if (!$action && $_SERVER["REQUEST_METHOD"] === "POST") {
    $input = json_decode(file_get_contents("php://input"), true);
    $action = isset($input["action"]) ? $input["action"] : "";
}

switch ($action) {
    
    // ══════════════════════════════════════════════════════════════════════════
    // CLIENTE: Obter localização do delivery
    // ══════════════════════════════════════════════════════════════════════════
    case "get_location":
        $order_id = isset($_GET["order_id"]) ? intval($_GET["order_id"]) : 0;
        
        if (!$order_id) {
            echo json_encode(array("success" => false, "error" => "order_id required"));
            exit;
        }
        
        // Buscar pedido com dados do delivery
        $stmt = $pdo->prepare("
            SELECT 
                o.order_id, o.status, o.delivery_id,
                o.delivery_latitude, o.delivery_longitude,
                o.customer_latitude, o.customer_longitude,
                o.customer_address,
                d.name as delivery_name, d.phone as delivery_phone,
                d.current_latitude, d.current_longitude,
                d.vehicle_type,
                p.name as partner_name, p.latitude as partner_lat, p.longitude as partner_lng
            FROM om_market_orders o
            LEFT JOIN om_market_deliveries d ON o.delivery_id = d.delivery_id
            LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
            WHERE o.order_id = ?
        ");
        $stmt->execute(array($order_id));
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            echo json_encode(array("success" => false, "error" => "Order not found"));
            exit;
        }
        
        // Se não tem delivery ainda
        if (!$order["delivery_id"]) {
            echo json_encode(array(
                "success" => true,
                "tracking" => false,
                "status" => $order["status"],
                "message" => "Aguardando entregador"
            ));
            exit;
        }
        
        // Calcular distância e ETA
        $delivery_lat = $order["current_latitude"] ? $order["current_latitude"] : $order["delivery_latitude"];
        $delivery_lng = $order["current_longitude"] ? $order["current_longitude"] : $order["delivery_longitude"];
        $customer_lat = $order["customer_latitude"];
        $customer_lng = $order["customer_longitude"];
        
        $distance = null;
        $eta_minutes = null;
        
        if ($delivery_lat && $delivery_lng && $customer_lat && $customer_lng) {
            // Haversine
            $lat1 = deg2rad($delivery_lat);
            $lat2 = deg2rad($customer_lat);
            $dlat = deg2rad($customer_lat - $delivery_lat);
            $dlng = deg2rad($customer_lng - $delivery_lng);
            
            $a = sin($dlat/2) * sin($dlat/2) + cos($lat1) * cos($lat2) * sin($dlng/2) * sin($dlng/2);
            $c = 2 * atan2(sqrt($a), sqrt(1-$a));
            $distance = 6371 * $c; // km
            
            // ETA baseado no veículo (velocidade média em km/h)
            $speed = $order["vehicle_type"] == "carro" ? 30 : 25; // cidade
            $eta_minutes = ceil(($distance / $speed) * 60);
        }
        
        echo json_encode(array(
            "success" => true,
            "tracking" => true,
            "order_id" => $order["order_id"],
            "status" => $order["status"],
            "delivery" => array(
                "id" => $order["delivery_id"],
                "name" => $order["delivery_name"],
                "phone" => $order["delivery_phone"],
                "vehicle" => $order["vehicle_type"],
                "latitude" => floatval($delivery_lat),
                "longitude" => floatval($delivery_lng)
            ),
            "customer" => array(
                "latitude" => floatval($customer_lat),
                "longitude" => floatval($customer_lng),
                "address" => $order["customer_address"]
            ),
            "partner" => array(
                "name" => $order["partner_name"],
                "latitude" => floatval($order["partner_lat"]),
                "longitude" => floatval($order["partner_lng"])
            ),
            "distance_km" => $distance ? round($distance, 2) : null,
            "eta_minutes" => $eta_minutes,
            "updated_at" => date("Y-m-d H:i:s")
        ));
        break;
    
    // ══════════════════════════════════════════════════════════════════════════
    // DELIVERY: Atualizar localização
    // ══════════════════════════════════════════════════════════════════════════
    case "update_location":
        $input = json_decode(file_get_contents("php://input"), true);
        
        $delivery_id = isset($input["delivery_id"]) ? intval($input["delivery_id"]) : 0;
        $latitude = isset($input["latitude"]) ? floatval($input["latitude"]) : 0;
        $longitude = isset($input["longitude"]) ? floatval($input["longitude"]) : 0;
        $order_id = isset($input["order_id"]) ? intval($input["order_id"]) : 0;
        
        if (!$delivery_id || !$latitude || !$longitude) {
            echo json_encode(array("success" => false, "error" => "Missing params"));
            exit;
        }
        
        // Atualizar posição do delivery
        $stmt = $pdo->prepare("
            UPDATE om_market_deliveries 
            SET current_latitude = ?, current_longitude = ?, last_location_update = NOW()
            WHERE delivery_id = ?
        ");
        $stmt->execute(array($latitude, $longitude, $delivery_id));
        
        // Se tem order_id, atualizar também no pedido
        if ($order_id) {
            $stmt = $pdo->prepare("
                UPDATE om_market_orders 
                SET delivery_latitude = ?, delivery_longitude = ?
                WHERE order_id = ? AND delivery_id = ?
            ");
            $stmt->execute(array($latitude, $longitude, $order_id, $delivery_id));
            
            // Salvar histórico de rota
            $stmt = $pdo->prepare("
                INSERT INTO om_delivery_tracking (order_id, delivery_id, latitude, longitude)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute(array($order_id, $delivery_id, $latitude, $longitude));
        }
        
        echo json_encode(array("success" => true));
        break;
    
    // ══════════════════════════════════════════════════════════════════════════
    // CLIENTE: Obter histórico de rota
    // ══════════════════════════════════════════════════════════════════════════
    case "get_route":
        $order_id = isset($_GET["order_id"]) ? intval($_GET["order_id"]) : 0;
        
        if (!$order_id) {
            echo json_encode(array("success" => false, "error" => "order_id required"));
            exit;
        }
        
        $stmt = $pdo->prepare("
            SELECT latitude, longitude, created_at
            FROM om_delivery_tracking
            WHERE order_id = ?
            ORDER BY created_at ASC
        ");
        $stmt->execute(array($order_id));
        $points = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(array(
            "success" => true,
            "route" => $points
        ));
        break;
    
    default:
        echo json_encode(array("success" => false, "error" => "Invalid action"));
}
