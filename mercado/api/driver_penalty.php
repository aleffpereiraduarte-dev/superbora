<?php
require_once dirname(__DIR__) . '/config/database.php';
/**
 * API para aplicar penalidade quando driver desiste
 * Reduz score_interno e registra desistência
 */

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

try {
    $pdo = getPDO();
} catch (PDOException $e) {
    die(json_encode(["error" => "DB error"]));
}

$input = json_decode(file_get_contents("php://input"), true);
$driver_id = intval($input["driver_id"] ?? $_POST["driver_id"] ?? 0);
$order_id = intval($input["order_id"] ?? $_POST["order_id"] ?? 0);
$reason = $input["reason"] ?? "desistencia";

if (!$driver_id) {
    die(json_encode(["error" => "driver_id required"]));
}

// Buscar config de penalidade
$penalty_points = 10; // default
try {
    $config = $pdo->query("SELECT config_value FROM om_dispatch_config WHERE config_key = 'penalty_desistencia'")->fetchColumn();
    if ($config) $penalty_points = intval($config);
} catch (Exception $e) {}

// Aplicar penalidade
$pdo->prepare("
    UPDATE om_market_deliveries 
    SET score_interno = GREATEST(0, COALESCE(score_interno, 100) - ?),
        total_desistencias = COALESCE(total_desistencias, 0) + 1
    WHERE delivery_id = ?
")->execute([$penalty_points, $driver_id]);

// Registrar penalidade
$pdo->prepare("
    INSERT INTO om_driver_penalties (driver_id, order_id, reason, points_lost, description)
    VALUES (?, ?, ?, ?, ?)
")->execute([$driver_id, $order_id ?: null, $reason, $penalty_points, "Desistência de pedido"]);

// Buscar novo score
$stmt = $pdo->prepare("SELECT score_interno FROM om_market_deliveries WHERE delivery_id = ?");
$stmt->execute([$driver_id]);
$new_score = $stmt->fetchColumn();

// Se tinha pedido, liberar para outro driver
if ($order_id) {
    $pdo->prepare("
        UPDATE om_market_orders 
        SET delivery_driver_id = NULL, 
            driver_dispatch_at = NULL,
            driver_arrived_at = NULL,
            wait_fee = 0
        WHERE order_id = ?
    ")->execute([$order_id]);
    
    // Cancelar oferta do driver
    $pdo->prepare("
        UPDATE om_delivery_offers 
        SET status = 'cancelled', cancelled_reason = 'driver_desistiu'
        WHERE order_id = ? AND accepted_by = ?
    ")->execute([$order_id, $driver_id]);
}

echo json_encode([
    "success" => true,
    "message" => "Penalidade aplicada",
    "points_lost" => $penalty_points,
    "new_score" => intval($new_score),
    "order_released" => $order_id ? true : false
]);
