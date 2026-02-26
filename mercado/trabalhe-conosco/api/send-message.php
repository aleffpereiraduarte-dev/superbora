<?php
session_start();
require_once __DIR__ . "/../includes/functions.php";
header("Content-Type: application/json");

if (!isLoggedIn()) {
    jsonResponse(false, "Não autenticado");
}

$data = json_decode(file_get_contents("php://input"), true);
$orderId = intval($data["order_id"] ?? 0);
$message = trim($data["message"] ?? "");

if (!$orderId || !$message) {
    jsonResponse(false, "Dados inválidos");
}

$pdo = getDB();
$worker = getWorker();

$stmt = $pdo->prepare("INSERT INTO om_order_chat (order_id, sender_type, sender_id, sender_name, message, created_at) VALUES (?, \"shopper\", ?, ?, ?, NOW())");
$stmt->execute([$orderId, $worker["worker_id"], $worker["name"], $message]);

jsonResponse(true, "Mensagem enviada", ["message_id" => $pdo->lastInsertId()]);