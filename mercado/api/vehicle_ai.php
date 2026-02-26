<?php
/**
 * 🧠 API de Cálculo de Veículo
 */
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

require_once dirname(__DIR__) . '/config/database.php';
require_once __DIR__ . "/../includes/VehicleAI.php";

try {
    $pdo = getPDO();
} catch (PDOException $e) {
    die(json_encode(["success" => false, "error" => "DB Error"]));
}

$input = json_decode(file_get_contents("php://input"), true) ?? $_POST;
$action = $input["action"] ?? $_GET["action"] ?? "calculate";

$ai = new VehicleAI($pdo);

// Calcular para um pedido
if ($action === "calculate") {
    $order_id = intval($input["order_id"] ?? $_GET["order_id"] ?? 0);
    
    if (!$order_id) {
        echo json_encode(["success" => false, "error" => "Order ID obrigatório"]);
        exit;
    }
    
    $result = $ai->calcularVeiculo($order_id);
    echo json_encode(["success" => true] + $result);
    exit;
}

// Calcular e salvar
if ($action === "calculate_save") {
    $order_id = intval($input["order_id"] ?? 0);
    
    if (!$order_id) {
        echo json_encode(["success" => false, "error" => "Order ID obrigatório"]);
        exit;
    }
    
    $result = $ai->calcularESalvar($order_id);
    echo json_encode(["success" => true] + $result);
    exit;
}

// Recalcular todos pendentes
if ($action === "recalculate_all") {
    $count = $ai->recalcularPendentes();
    echo json_encode(["success" => true, "orders_processed" => $count]);
    exit;
}

echo json_encode(["success" => false, "error" => "Ação inválida"]);
