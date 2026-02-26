<?php
/**
 * API: Dados Bancários
 * GET /api/bank.php - Obter dados
 * POST /api/bank.php - Salvar/atualizar dados
 */
require_once 'db.php';

$workerId = requireAuth();
$db = getDB();

if (!$db) {
    jsonError('Erro de conexão', 500);
}

// GET - Obter dados bancários
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $stmt = $db->prepare("
            SELECT bank_code, bank_name, account_type, agency, account_number, 
                   pix_key, pix_key_type, holder_name, holder_cpf, created_at, updated_at
            FROM " . table('bank_accounts') . "
            WHERE worker_id = ?
        ");
        $stmt->execute([$workerId]);
        $account = $stmt->fetch();

        jsonSuccess(['bank_account' => $account]);

    } catch (Exception $e) {
        error_log("Bank GET error: " . $e->getMessage());
        jsonError('Erro ao buscar dados bancários', 500);
    }
}

// POST - Salvar dados bancários
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = getJsonInput();

    // Validações
    $required = ['bank_code', 'bank_name', 'account_type', 'agency', 'account_number'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            jsonError("Campo $field é obrigatório");
        }
    }

    $bankCode = $input['bank_code'];
    $bankName = $input['bank_name'];
    $accountType = $input['account_type']; // corrente, poupanca
    $agency = preg_replace('/\D/', '', $input['agency']);
    $accountNumber = preg_replace('/\D/', '', $input['account_number']);
    $pixKey = $input['pix_key'] ?? null;
    $pixKeyType = $input['pix_key_type'] ?? null; // cpf, email, phone, random
    $holderName = $input['holder_name'] ?? null;
    $holderCpf = preg_replace('/\D/', '', $input['holder_cpf'] ?? '');

    try {
        // Verificar se já existe
        $stmt = $db->prepare("SELECT id FROM " . table('bank_accounts') . " WHERE worker_id = ?");
        $stmt->execute([$workerId]);
        $exists = $stmt->fetch();

        if ($exists) {
            // Atualizar
            $stmt = $db->prepare("
                UPDATE " . table('bank_accounts') . "
                SET bank_code = ?, bank_name = ?, account_type = ?, agency = ?, 
                    account_number = ?, pix_key = ?, pix_key_type = ?,
                    holder_name = ?, holder_cpf = ?, updated_at = NOW()
                WHERE worker_id = ?
            ");
            $stmt->execute([
                $bankCode, $bankName, $accountType, $agency, $accountNumber,
                $pixKey, $pixKeyType, $holderName, $holderCpf, $workerId
            ]);
        } else {
            // Inserir
            $stmt = $db->prepare("
                INSERT INTO " . table('bank_accounts') . "
                (worker_id, bank_code, bank_name, account_type, agency, account_number,
                 pix_key, pix_key_type, holder_name, holder_cpf, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $workerId, $bankCode, $bankName, $accountType, $agency, $accountNumber,
                $pixKey, $pixKeyType, $holderName, $holderCpf
            ]);
        }

        jsonSuccess([], 'Dados bancários salvos com sucesso');

    } catch (Exception $e) {
        error_log("Bank POST error: " . $e->getMessage());
        jsonError('Erro ao salvar dados bancários', 500);
    }
}

jsonError('Método não permitido', 405);
