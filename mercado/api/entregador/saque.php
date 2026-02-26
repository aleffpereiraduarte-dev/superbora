<?php
/**
 * API: Solicitar saque
 * POST /mercado/api/entregador/saque.php
 */
require_once __DIR__ . '/config.php';

$input = getInput();
$driver_id = (int)($input['driver_id'] ?? 0);
$valor = (float)($input['valor'] ?? 0);
$tipo_conta = $input['tipo_conta'] ?? 'pix';
$chave_pix = $input['chave_pix'] ?? '';

if (!$driver_id || !$valor) {
    jsonResponse(['success' => false, 'error' => 'driver_id e valor obrigatorios'], 400);
}

if ($valor < 10) {
    jsonResponse(['success' => false, 'error' => 'Valor minimo para saque: R$ 10,00'], 400);
}

$pdo = getDB();

// Verificar driver
$driver = validateDriver($driver_id);
if (!$driver) {
    jsonResponse(['success' => false, 'error' => 'Motorista nao encontrado'], 404);
}

// Verificar saldo
if ($valor > (float)$driver['balance']) {
    jsonResponse(['success' => false, 'error' => 'Saldo insuficiente', 'saldo' => (float)$driver['balance']], 400);
}

// Usar chave PIX do cadastro se nao fornecida
if (!$chave_pix && $driver['pix_key']) {
    $chave_pix = $driver['pix_key'];
}

if (!$chave_pix) {
    jsonResponse(['success' => false, 'error' => 'Chave PIX nao configurada. Cadastre sua chave PIX primeiro.'], 400);
}

$pdo->beginTransaction();
try {
    // Debitar saldo
    $pdo->prepare("UPDATE om_boraum_drivers SET balance = balance - ? WHERE driver_id = ?")
        ->execute([$valor, $driver_id]);

    // Registrar transacao de saque
    $pdo->prepare("INSERT INTO om_boraum_transactions (user_type, user_id, type, amount, description, payout_status, payout_method, payout_key, created_at) VALUES ('driver', ?, 'withdrawal', ?, 'Saque PIX', 'pending', 'pix', ?, NOW())")
        ->execute([$driver_id, -$valor, $chave_pix]);

    $pdo->commit();

    jsonResponse([
        'success' => true,
        'message' => 'Saque solicitado com sucesso! O valor sera depositado em ate 1 hora.',
        'driver' => [
            'id' => $driver_id,
            'name' => $driver['name'],
            'novo_saldo' => (float)$driver['balance'] - $valor
        ],
        'saque' => [
            'valor' => $valor,
            'chave_pix' => $chave_pix,
            'status' => 'pending',
            'previsao' => date('Y-m-d H:i:s', strtotime('+1 hour'))
        ]
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    jsonResponse(['success' => false, 'error' => 'Erro ao processar saque: ' . $e->getMessage()], 500);
}
