<?php
/**
 * POST /api/mercado/corporate/register.php
 * Cadastro de conta corporativa
 */
require_once __DIR__ . "/../config/database.php";

setCorsHeaders();

try {
    $db = getDB();
    $input = getInput();

    $companyName = trim($input['company_name'] ?? '');
    $cnpj = preg_replace('/[^0-9]/', '', $input['cnpj'] ?? '');
    $contactName = trim($input['contact_name'] ?? '');
    $contactEmail = trim($input['contact_email'] ?? '');
    $contactPhone = preg_replace('/[^0-9]/', '', $input['contact_phone'] ?? '');
    $billingEmail = trim($input['billing_email'] ?? '') ?: $contactEmail;
    $address = trim($input['address'] ?? '');
    $city = trim($input['city'] ?? '');
    $state = strtoupper(trim($input['state'] ?? ''));
    $cep = preg_replace('/[^0-9]/', '', $input['cep'] ?? '');
    $monthlyLimit = (float)($input['monthly_limit'] ?? 10000);
    $perEmployeeLimit = (float)($input['per_employee_limit'] ?? 50);

    // Validacoes
    if (empty($companyName)) {
        response(false, null, "Nome da empresa obrigatorio", 400);
    }

    if (strlen($cnpj) !== 14) {
        response(false, null, "CNPJ invalido", 400);
    }

    if (empty($contactName) || empty($contactEmail)) {
        response(false, null, "Nome e email do contato obrigatorios", 400);
    }

    if (!filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
        response(false, null, "Email invalido", 400);
    }

    // Transação para evitar race condition no check-then-insert do CNPJ
    $db->beginTransaction();
    try {
        // Verificar se CNPJ ja existe (com lock)
        $stmt = $db->prepare("SELECT id FROM om_corporate_accounts WHERE cnpj = ? FOR UPDATE");
        $stmt->execute([$cnpj]);
        if ($stmt->fetch()) {
            $db->rollBack();
            response(false, null, "CNPJ ja cadastrado", 400);
        }

        // Criar conta
        $stmt = $db->prepare("
            INSERT INTO om_corporate_accounts
            (company_name, cnpj, contact_name, contact_email, contact_phone,
             billing_email, address, city, state, cep,
             monthly_limit, per_employee_limit, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
        ");
        $stmt->execute([
            $companyName, $cnpj, $contactName, $contactEmail, $contactPhone,
            $billingEmail, $address, $city, $state, $cep,
            $monthlyLimit, $perEmployeeLimit
        ]);

        $accountId = (int)$db->lastInsertId();
        $db->commit();
    } catch (Exception $txEx) {
        $db->rollBack();
        throw $txEx;
    }

    response(true, [
        'account_id' => $accountId,
        'message' => 'Cadastro recebido! Nossa equipe entrara em contato em ate 24 horas para ativar sua conta.'
    ]);

} catch (Exception $e) {
    error_log("[corporate/register] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar cadastro", 500);
}
