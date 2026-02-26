<?php
header('Content-Type: application/json');

$code = strtoupper($_GET['code'] ?? '');

if (empty($code) || strlen($code) !== 8) {
    echo json_encode(['success' => false, 'valid' => false, 'error' => 'Código inválido']);
    exit;
}

// Em produção: verificar no banco
/*
$db = new PDO(...);
$stmt = $db->prepare("SELECT * FROM om_order_handoffs WHERE code = ? AND status = 'pending'");
$stmt->execute([$code]);
$handoff = $stmt->fetch();
*/

// Simular validação (em produção seria do banco)
// Aceita qualquer código de 8 caracteres alfanuméricos
$valid = preg_match('/^[A-Z0-9]{8}$/', $code);

echo json_encode([
    'success' => true,
    'valid' => $valid,
    'code' => $code
]);
