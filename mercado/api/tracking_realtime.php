<?php
/**
 * API Tracking Realtime - GPS do Delivery
 * Actions: update_location, get_location, get_route
 */
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

require_once dirname(__DIR__) . '/config/database.php';
$pdo = getPDO();

$action = $_REQUEST["action"] ?? "";

switch ($action) {
    case "update_location":
        $delivery_id = (int)($_POST["delivery_id"] ?? 0);
        $order_id = (int)($_POST["order_id"] ?? 0);
        $lat = (float)($_POST["lat"] ?? 0);
        $lng = (float)($_POST["lng"] ?? 0);
        $speed = (float)($_POST["speed"] ?? 0);
        $heading = (float)($_POST["heading"] ?? 0);

        if (!$delivery_id || !$lat || !$lng) {
            echo json_encode(["success" => false, "error" => "Dados incompletos"]);
            exit;
        }

        // Atualizar posicao atual do delivery
        $stmt = $pdo->prepare("UPDATE om_market_deliveries SET current_lat = ?, current_lng = ?, last_location_update = NOW() WHERE delivery_id = ?");
        $stmt->execute([$lat, $lng, $delivery_id]);

        // Salvar historico de tracking
        if ($order_id) {
            $stmt = $pdo->prepare("INSERT INTO om_delivery_tracking (order_id, delivery_id, latitude, longitude) VALUES (?, ?, ?, ?)");
            $stmt->execute([$order_id, $delivery_id, $lat, $lng]);
        }

        echo json_encode(["success" => true]);
        break;

    case "get_location":
        $order_id = (int)($_GET["order_id"] ?? 0);

        $stmt = $pdo->prepare("SELECT d.delivery_id, d.name, d.current_lat, d.current_lng, d.vehicle_type,
            o.customer_lat, o.customer_lng, o.status
            FROM om_market_orders o
            LEFT JOIN om_market_deliveries d ON o.delivery_id = d.delivery_id
            WHERE o.order_id = ?");
        $stmt->execute([$order_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            // Calcular distancia
            if ($row["current_lat"] && $row["customer_lat"]) {
                $dist = 6371 * acos(cos(deg2rad($row["customer_lat"])) * cos(deg2rad($row["current_lat"]))
                    * cos(deg2rad($row["current_lng"]) - deg2rad($row["customer_lng"]))
                    + sin(deg2rad($row["customer_lat"])) * sin(deg2rad($row["current_lat"])));
                $row["distance_km"] = round($dist, 2);
                $row["eta_minutes"] = round($dist / 0.5); // 30km/h media
            }
            echo json_encode(["success" => true, "data" => $row]);
        } else {
            echo json_encode(["success" => false, "error" => "Pedido nao encontrado"]);
        }
        break;

    case "get_route":
        $order_id = (int)($_GET["order_id"] ?? 0);

        $stmt = $pdo->prepare("SELECT latitude, longitude, recorded_at FROM om_delivery_tracking
            WHERE order_id = ? ORDER BY recorded_at ASC");
        $stmt->execute([$order_id]);
        $points = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(["success" => true, "route" => $points]);
        break;

    default:
        echo json_encode(["success" => false, "actions" => ["update_location","get_location","get_route"]]);
}
