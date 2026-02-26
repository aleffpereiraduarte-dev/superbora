<?php
session_start();
require_once __DIR__ . "/../includes/functions.php";
header("Content-Type: application/json");

if (!isLoggedIn()) {
    jsonResponse(false, "Não autenticado");
}

$data = json_decode(file_get_contents("php://input"), true);
$orderId = intval($data["order_id"] ?? 0);
$code = trim($data["code"] ?? "");

if (!$orderId || !$code) {
    jsonResponse(false, "Dados inválidos");
}

$pdo = getDB();
$workerId = getWorkerId();

// Verificar pedido
$stmt = $pdo->prepare("SELECT * FROM om_market_orders WHERE order_id = ? AND (shopper_id = ? OR delivery_id = ?)");
$stmt->execute([$orderId, $workerId, $workerId]);
$order = $stmt->fetch();

if (!$order) {
    jsonResponse(false, "Pedido não encontrado");
}

// Verificar código
if ($order["delivery_code"] !== $code) {
    jsonResponse(false, "Código incorreto");
}

// Confirmar entrega
$pdo->prepare("UPDATE om_market_orders SET status = \"completed\", completed_at = NOW(), delivered_at = NOW() WHERE order_id = ?")
    ->execute([$orderId]);

// Creditar ganhos
$earnings = floatval($order["shopper_earnings"] ?? 0);
if ($earnings > 0) {
    $pdo->prepare("UPDATE om_market_workers SET balance = balance + ?, total_earned = total_earned + ?, total_orders = total_orders + 1 WHERE worker_id = ?")
        ->execute([$earnings, $earnings, $workerId]);
}

jsonResponse(true, "Entrega confirmada!", ["earnings" => $earnings]);