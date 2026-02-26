<?php
header('Content-Type: application/json');
session_start();

$input = json_decode(file_get_contents('php://input'), true);
$code = $input['code'] ?? '';

if (empty($code)) {
    echo json_encode(['success' => false, 'error' => 'Código não fornecido']);
    exit;
}

// Em produção: atualizar banco de dados
// UPDATE om_order_handoffs SET collected_at = NOW(), delivery_worker_id = ? WHERE code = ?

echo json_encode([
    'success' => true,
    'message' => 'Handoff confirmado',
    'code' => $code,
    'collected_at' => date('Y-m-d H:i:s')
]);
