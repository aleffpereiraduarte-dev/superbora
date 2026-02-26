<?php
header('Content-Type: application/json');

$code = $_GET['code'] ?? '';

if (empty($code)) {
    echo json_encode(['success' => false, 'error' => 'Código não fornecido']);
    exit;
}

// Em produção: verificar no banco
// SELECT collected_at FROM om_order_handoffs WHERE code = ?

// Simular: 20% de chance de já ter sido coletado
$collected = rand(1, 100) <= 20;

echo json_encode([
    'success' => true,
    'code' => $code,
    'collected' => $collected,
    'collected_at' => $collected ? date('Y-m-d H:i:s') : null
]);
