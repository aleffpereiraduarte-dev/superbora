<?php
/**
 * API: Carteira e Saques
 * GET /api/wallet.php - Saldo e transações
 * POST /api/wallet.php - Solicitar saque
 */
require_once 'db.php';

$workerId = requireAuth();
$db = getDB();

if (!$db) {
    jsonError('Erro de conexão', 500);
}

// GET - Saldo e histórico
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        // Saldo disponível
        $stmt = $db->prepare("
            SELECT 
                COALESCE(SUM(CASE WHEN type IN ('earning', 'bonus', 'tip') THEN amount ELSE 0 END), 0) as total_earned,
                COALESCE(SUM(CASE WHEN type = 'withdrawal' AND status = 'completed' THEN amount ELSE 0 END), 0) as total_withdrawn,
                COALESCE(SUM(CASE WHEN type = 'withdrawal' AND status = 'pending' THEN amount ELSE 0 END), 0) as pending_withdrawal
            FROM " . table('wallet_transactions') . "
            WHERE worker_id = ?
        ");
        $stmt->execute([$workerId]);
        $balance = $stmt->fetch();

        $available = $balance['total_earned'] - $balance['total_withdrawn'] - $balance['pending_withdrawal'];

        // Histórico de transações
        $stmt = $db->prepare("
            SELECT id, type, amount, description, status, created_at
            FROM " . table('wallet_transactions') . "
            WHERE worker_id = ?
            ORDER BY created_at DESC
            LIMIT 50
        ");
        $stmt->execute([$workerId]);
        $transactions = $stmt->fetchAll();

        // Dados bancários
        $stmt = $db->prepare("
            SELECT bank_name, account_type, agency, account_number, pix_key, pix_key_type
            FROM " . table('bank_accounts') . "
            WHERE worker_id = ?
        ");
        $stmt->execute([$workerId]);
        $bankAccount = $stmt->fetch();

        // Próximo saque disponível
        $stmt = $db->prepare("
            SELECT created_at FROM " . table('wallet_transactions') . "
            WHERE worker_id = ? AND type = 'withdrawal'
            ORDER BY created_at DESC LIMIT 1
        ");
        $stmt->execute([$workerId]);
        $lastWithdraw = $stmt->fetch();

        jsonSuccess([
            'balance' => [
                'available' => max(0, $available),
                'total_earned' => $balance['total_earned'],
                'total_withdrawn' => $balance['total_withdrawn'],
                'pending' => $balance['pending_withdrawal']
            ],
            'transactions' => $transactions,
            'bank_account' => $bankAccount,
            'last_withdrawal' => $lastWithdraw['created_at'] ?? null
        ]);

    } catch (Exception $e) {
        error_log("Wallet GET error: " . $e->getMessage());
        jsonError('Erro ao buscar carteira', 500);
    }
}

// POST - Solicitar saque
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = getJsonInput();
    $amount = floatval($input['amount'] ?? 0);
    $method = $input['method'] ?? 'pix'; // pix, ted

    if ($amount < 10) {
        jsonError('Valor mínimo para saque é R$ 10,00');
    }

    try {
        $db->beginTransaction();

        // Verificar saldo disponível
        $stmt = $db->prepare("
            SELECT 
                COALESCE(SUM(CASE WHEN type IN ('earning', 'bonus', 'tip') THEN amount ELSE 0 END), 0) -
                COALESCE(SUM(CASE WHEN type = 'withdrawal' THEN amount ELSE 0 END), 0) as available
            FROM " . table('wallet_transactions') . "
            WHERE worker_id = ?
        ");
        $stmt->execute([$workerId]);
        $balance = $stmt->fetch();

        if ($balance['available'] < $amount) {
            $db->rollBack();
            jsonError('Saldo insuficiente');
        }

        // Verificar dados bancários
        $stmt = $db->prepare("SELECT id FROM " . table('bank_accounts') . " WHERE worker_id = ?");
        $stmt->execute([$workerId]);
        if (!$stmt->fetch()) {
            $db->rollBack();
            jsonError('Cadastre seus dados bancários primeiro');
        }

        // Criar transação de saque
        $stmt = $db->prepare("
            INSERT INTO " . table('wallet_transactions') . " 
            (worker_id, type, amount, description, status, method, created_at)
            VALUES (?, 'withdrawal', ?, ?, 'pending', ?, NOW())
        ");
        $stmt->execute([$workerId, $amount, "Saque via $method", $method]);
        $transactionId = $db->lastInsertId();

        $db->commit();

        jsonSuccess([
            'transaction_id' => $transactionId,
            'amount' => $amount,
            'method' => $method,
            'estimated_time' => $method === 'pix' ? '1 hora' : '1-2 dias úteis'
        ], 'Saque solicitado com sucesso');

    } catch (Exception $e) {
        $db->rollBack();
        error_log("Wallet POST error: " . $e->getMessage());
        jsonError('Erro ao solicitar saque', 500);
    }
}

jsonError('Método não permitido', 405);
