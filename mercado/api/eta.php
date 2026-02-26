<?php
/**
 * ⏱️ API DE TEMPO ESTIMADO (ETA)
 */
header("Content-Type: application/json");

require_once __DIR__ . '/../config/database.php';

try {
    $pdo = getPDO();
} catch (PDOException $e) {
    die(json_encode(array("success" => false, "error" => "DB Error")));
}

$action = isset($_GET["action"]) ? $_GET["action"] : "";

switch ($action) {
    
    // ══════════════════════════════════════════════════════════════════════════
    // CALCULAR ETA DO PEDIDO
    // ══════════════════════════════════════════════════════════════════════════
    case "calculate":
        $order_id = isset($_GET["order_id"]) ? intval($_GET["order_id"]) : 0;
        
        if (!$order_id) {
            echo json_encode(array("success" => false, "error" => "order_id required"));
            exit;
        }
        
        // Buscar pedido
        $stmt = $pdo->prepare("
            SELECT o.*, 
                   p.latitude as partner_lat, p.longitude as partner_lng,
                   d.current_latitude as delivery_lat, d.current_longitude as delivery_lng,
                   d.vehicle_type,
                   (SELECT COUNT(*) FROM om_market_order_items WHERE order_id = o.order_id) as item_count
            FROM om_market_orders o
            LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
            LEFT JOIN om_market_deliveries d ON o.delivery_id = d.delivery_id
            WHERE o.order_id = ?
        ");
        $stmt->execute(array($order_id));
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            echo json_encode(array("success" => false, "error" => "Pedido não encontrado"));
            exit;
        }
        
        // Buscar config
        $stmt = $pdo->prepare("SELECT * FROM om_eta_config WHERE partner_id = ? OR partner_id IS NULL ORDER BY partner_id DESC LIMIT 1");
        $stmt->execute(array($order["partner_id"]));
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$config) {
            $config = array(
                "avg_acceptance_time" => 5,
                "avg_shopping_time_per_item" => 2,
                "avg_shopping_base_time" => 10,
                "avg_delivery_speed_kmh" => 25,
                "avg_handoff_time" => 5,
                "rush_hour_multiplier" => 1.3,
                "night_multiplier" => 1.1
            );
        }
        
        // Calcular ETA baseado no status atual
        $eta = calculateETA($order, $config);
        
        // Atualizar no banco
        $stmt = $pdo->prepare("UPDATE om_market_orders SET eta_minutes = ?, eta_updated_at = NOW(), estimated_delivery_at = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE order_id = ?");
        $stmt->execute(array($eta["total_minutes"], $eta["total_minutes"], $order_id));
        
        echo json_encode(array(
            "success" => true,
            "order_id" => $order_id,
            "status" => $order["status"],
            "eta" => $eta
        ));
        break;
    
    // ══════════════════════════════════════════════════════════════════════════
    // OBTER ETA ATUAL
    // ══════════════════════════════════════════════════════════════════════════
    case "get":
        $order_id = isset($_GET["order_id"]) ? intval($_GET["order_id"]) : 0;
        
        $stmt = $pdo->prepare("
            SELECT order_id, status, eta_minutes, eta_updated_at, estimated_delivery_at,
                   created_at, shopper_started_at, shopper_finished_at, 
                   delivery_started_at, delivery_picked_at, delivered_at
            FROM om_market_orders WHERE order_id = ?
        ");
        $stmt->execute(array($order_id));
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            echo json_encode(array("success" => false, "error" => "Pedido não encontrado"));
            exit;
        }
        
        // Calcular tempo restante
        $remaining = null;
        if ($order["estimated_delivery_at"]) {
            $est = strtotime($order["estimated_delivery_at"]);
            $remaining = max(0, ceil(($est - time()) / 60));
        }
        
        // Timeline
        $timeline = array();
        
        $timeline[] = array(
            "step" => "created",
            "label" => "Pedido criado",
            "time" => $order["created_at"],
            "completed" => true
        );
        
        $timeline[] = array(
            "step" => "shopper_accepted",
            "label" => "Shopper aceitou",
            "time" => $order["shopper_started_at"],
            "completed" => (bool)$order["shopper_started_at"]
        );
        
        $timeline[] = array(
            "step" => "shopping",
            "label" => "Comprando",
            "time" => $order["shopper_started_at"],
            "completed" => (bool)$order["shopper_finished_at"]
        );
        
        $timeline[] = array(
            "step" => "ready",
            "label" => "Pronto",
            "time" => $order["shopper_finished_at"],
            "completed" => in_array($order["status"], array("ready", "delivering", "delivered", "completed"))
        );
        
        $timeline[] = array(
            "step" => "delivery_picked",
            "label" => "Delivery pegou",
            "time" => $order["delivery_picked_at"],
            "completed" => (bool)$order["delivery_picked_at"]
        );
        
        $timeline[] = array(
            "step" => "delivering",
            "label" => "Entregando",
            "time" => $order["delivery_started_at"],
            "completed" => in_array($order["status"], array("delivered", "completed"))
        );
        
        $timeline[] = array(
            "step" => "delivered",
            "label" => "Entregue",
            "time" => $order["delivered_at"],
            "completed" => in_array($order["status"], array("delivered", "completed"))
        );
        
        echo json_encode(array(
            "success" => true,
            "status" => $order["status"],
            "eta_minutes" => $order["eta_minutes"],
            "remaining_minutes" => $remaining,
            "estimated_delivery_at" => $order["estimated_delivery_at"],
            "timeline" => $timeline
        ));
        break;
    
    default:
        echo json_encode(array("success" => false, "error" => "Invalid action"));
}

/**
 * Calcula ETA baseado no status e configurações
 */
function calculateETA($order, $config) {
    $status = $order["status"];
    $item_count = intval($order["item_count"]) ?: 5;
    
    // Multiplicador de horário
    $hour = intval(date("H"));
    $multiplier = 1.0;
    if ($hour >= 11 && $hour <= 13) $multiplier = floatval($config["rush_hour_multiplier"]); // Almoço
    if ($hour >= 18 && $hour <= 20) $multiplier = floatval($config["rush_hour_multiplier"]); // Jantar
    if ($hour >= 22 || $hour <= 6) $multiplier = floatval($config["night_multiplier"]); // Noite
    
    $eta = array(
        "acceptance" => 0,
        "shopping" => 0,
        "handoff" => 0,
        "delivery" => 0,
        "total_minutes" => 0,
        "multiplier" => $multiplier
    );
    
    // Calcular cada etapa baseado no status
    switch ($status) {
        case "pending":
            $eta["acceptance"] = intval($config["avg_acceptance_time"]);
            // Fall through
        case "confirmed":
            $eta["shopping"] = intval($config["avg_shopping_base_time"]) + ($item_count * intval($config["avg_shopping_time_per_item"]));
            // Fall through
        case "preparing":
            $eta["shopping"] = $eta["shopping"] ?: (intval($config["avg_shopping_base_time"]) + ($item_count * intval($config["avg_shopping_time_per_item"])));
            // Fall through
        case "ready":
            $eta["handoff"] = intval($config["avg_handoff_time"]);
            // Fall through
        case "delivering":
            // Calcular distância e tempo de entrega
            if ($order["delivery_lat"] && $order["customer_latitude"]) {
                $distance = haversine(
                    floatval($order["delivery_lat"]),
                    floatval($order["delivery_lng"]),
                    floatval($order["customer_latitude"]),
                    floatval($order["customer_longitude"])
                );
            } elseif ($order["partner_lat"] && $order["customer_latitude"]) {
                $distance = haversine(
                    floatval($order["partner_lat"]),
                    floatval($order["partner_lng"]),
                    floatval($order["customer_latitude"]),
                    floatval($order["customer_longitude"])
                );
            } else {
                $distance = 5; // Default 5km
            }
            
            $speed = intval($config["avg_delivery_speed_kmh"]) ?: 25;
            $eta["delivery"] = ceil(($distance / $speed) * 60);
            $eta["distance_km"] = round($distance, 2);
            break;
            
        case "delivered":
        case "completed":
            // Já entregue
            $eta["total_minutes"] = 0;
            return $eta;
    }
    
    // Aplicar multiplicador e somar
    $eta["acceptance"] = ceil($eta["acceptance"] * $multiplier);
    $eta["shopping"] = ceil($eta["shopping"] * $multiplier);
    $eta["delivery"] = ceil($eta["delivery"] * $multiplier);
    
    $eta["total_minutes"] = $eta["acceptance"] + $eta["shopping"] + $eta["handoff"] + $eta["delivery"];
    
    return $eta;
}

/**
 * Calcula distância em km usando fórmula de Haversine
 */
function haversine($lat1, $lng1, $lat2, $lng2) {
    $R = 6371; // Raio da Terra em km
    
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    
    $a = sin($dLat/2) * sin($dLat/2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLng/2) * sin($dLng/2);
    
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    
    return $R * $c;
}
