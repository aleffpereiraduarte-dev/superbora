<?php
require_once dirname(__DIR__) . '/config/database.php';
/**
 * API para registrar quando driver chegou no mercado
 * Inicia contagem de tempo de espera
 */

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

try {
    $pdo = getPDO();
} catch (PDOException $e) {
    die(json_encode(["error" => "DB error"]));
}

$input = json_decode(file_get_contents("php://input"), true);
$order_id = intval($input["order_id"] ?? $_POST["order_id"] ?? 0);
$driver_id = intval($input["driver_id"] ?? $_POST["driver_id"] ?? 0);

if (!$order_id || !$driver_id) {
    die(json_encode(["error" => "order_id and driver_id required"]));
}

// Verificar se pedido pertence ao driver
$stmt = $pdo->prepare("SELECT delivery_driver_id, driver_arrived_at FROM om_market_orders WHERE order_id = ?");
$stmt->execute([$order_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order || intval($order["delivery_driver_id"]) !== $driver_id) {
    die(json_encode(["error" => "Pedido não encontrado ou não pertence a este driver"]));
}

if ($order["driver_arrived_at"]) {
    die(json_encode(["success" => true, "message" => "Chegada já registrada", "arrived_at" => $order["driver_arrived_at"]]));
}

// Registrar chegada
$pdo->prepare("UPDATE om_market_orders SET driver_arrived_at = NOW() WHERE order_id = ?")->execute([$order_id]);

// Log
$pdo->prepare("
    INSERT INTO om_dispatch_log (order_id, action, details)
    VALUES (?, 'driver_arrived', ?)
")->execute([$order_id, json_encode(["driver_id" => $driver_id])]);

echo json_encode([
    "success" => true,
    "message" => "Chegada registrada",
    "arrived_at" => date("Y-m-d H:i:s"),
    "wait_free_minutes" => 5,
    "wait_fee_per_minute" => 0.50
]);
