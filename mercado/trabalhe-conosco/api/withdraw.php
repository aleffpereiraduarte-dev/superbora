<?php
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$amount = floatval($data['amount'] ?? 0);
$pixKey = $data['pix_key'] ?? '';
$pixType = $data['pix_type'] ?? '';

if ($amount < 10) {
    echo json_encode(['success' => false, 'error' => 'Valor mínimo: R$ 10,00']);
    exit;
}

if (empty($pixKey)) {
    echo json_encode(['success' => false, 'error' => 'Chave PIX obrigatória']);
    exit;
}

$result = requestWithdrawal(getWorkerId(), $amount, $pixKey, $pixType);
echo json_encode($result);