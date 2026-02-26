<?php
session_start();
require_once __DIR__ . "/../includes/functions.php";
header("Content-Type: application/json");

if (!isLoggedIn()) {
    jsonResponse(false, "Não autenticado");
}

$data = json_decode(file_get_contents("php://input"), true);
$pixKey = trim($data["pix_key"] ?? "");
$pixType = trim($data["pix_type"] ?? "");
$holderName = trim($data["holder_name"] ?? "");

if (!$pixKey || !$pixType) {
    jsonResponse(false, "Dados inválidos");
}

$pdo = getDB();
$stmt = $pdo->prepare("UPDATE om_market_workers SET bank_pix_key = ?, bank_pix_type = ?, bank_holder_name = ? WHERE worker_id = ?");
$stmt->execute([$pixKey, $pixType, $holderName, getWorkerId()]);

jsonResponse(true, "Dados PIX salvos com sucesso!");