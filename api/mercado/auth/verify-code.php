<?php
/**
 * POST /api/mercado/auth/verify-code.php
 * Verifica codigo OTP e faz login/registro
 * Body: { "phone": "11999999999", "code": "123456", "name": "Joao" (opcional, pro registro) }
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, "Metodo nao permitido", 405);
}

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    $input = getInput();

    $phone = preg_replace('/\D/', '', $input['phone'] ?? '');
    $code = trim($input['code'] ?? '');
    $name = trim($input['name'] ?? '');

    // Aceita telefones brasileiros (10-11) e internacionais (10-15 dígitos)
    if (strlen($phone) < 10 || strlen($phone) > 15) {
        response(false, null, "Telefone invalido", 400);
    }
    if (strlen($code) !== 6) {
        response(false, null, "Codigo deve ter 6 digitos", 400);
    }

    // Atomic OTP verification with FOR UPDATE to prevent race conditions
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("
            SELECT id, code, attempts, expires_at
            FROM om_market_otp_codes
            WHERE phone = ? AND expires_at > NOW() AND (used = 0 OR used IS NULL)
            ORDER BY created_at DESC LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([$phone]);
        $otp = $stmt->fetch();

        if (!$otp) {
            $db->commit();
            response(false, null, "Codigo expirado ou nao encontrado. Solicite um novo.", 400);
        }

        // Max 3 tentativas por codigo
        if ((int)$otp['attempts'] >= 3) {
            $db->prepare("UPDATE om_market_otp_codes SET used = 1 WHERE id = ?")->execute([$otp['id']]);
            $db->commit();
            response(false, null, "Muitas tentativas erradas. Solicite um novo codigo.", 400);
        }

        // Verificar codigo
        if (!password_verify($code, $otp['code'])) {
            $db->prepare("UPDATE om_market_otp_codes SET attempts = attempts + 1 WHERE id = ?")->execute([$otp['id']]);
            $db->commit();
            $remaining = 3 - (int)$otp['attempts'] - 1;
            response(false, null, "Codigo incorreto. $remaining tentativa(s) restante(s).", 400);
        }

        // Marcar como usado atomicamente
        $db->prepare("UPDATE om_market_otp_codes SET used = 1, verified_at = NOW() WHERE id = ?")->execute([$otp['id']]);
        $db->commit();
    } catch (Exception $txEx) {
        $db->rollBack();
        throw $txEx;
    }

    // Buscar ou criar cliente pelo telefone (normalize stored phone for comparison)
    $stmt = $db->prepare("SELECT customer_id, name, email, phone, cpf, foto, is_active FROM om_customers WHERE REGEXP_REPLACE(phone, '[^0-9]', '', 'g') = ?");
    $stmt->execute([$phone]);
    $customer = $stmt->fetch();

    if ($customer) {
        // Login
        if (!$customer['is_active']) {
            response(false, null, "Conta desativada", 403);
        }

        $db->prepare("UPDATE om_customers SET last_login = NOW(), phone_verified = 1 WHERE customer_id = ?")
            ->execute([$customer['customer_id']]);

        $token = om_auth()->generateToken('customer', (int)$customer['customer_id'], [
            'name' => $customer['name'],
            'email' => $customer['email']
        ]);

        response(true, [
            "token" => $token,
            "is_new" => false,
            "customer" => [
                "id" => (int)$customer['customer_id'],
                "nome" => $customer['name'],
                "email" => $customer['email'],
                "telefone" => $customer['phone'],
                "cpf" => $customer['cpf'],
                "foto" => $customer['foto']
            ]
        ], "Login realizado!");

    } else {
        // Registrar novo cliente
        // Note: phone_verified column should be added via migration, not at runtime
        $stmt = $db->prepare("
            INSERT INTO om_customers (name, phone, phone_verified, is_active, date_added, last_login)
            VALUES (?, ?, 1, 1, NOW(), NOW())
            RETURNING customer_id
        ");
        $stmt->execute([$name ?: 'Cliente', $phone]);
        $newId = (int)$stmt->fetch()['customer_id'];

        $token = om_auth()->generateToken('customer', $newId, [
            'name' => $name ?: 'Cliente',
            'email' => ''
        ]);

        response(true, [
            "token" => $token,
            "is_new" => true,
            "customer" => [
                "id" => $newId,
                "nome" => $name ?: 'Cliente',
                "email" => null,
                "telefone" => $phone,
                "cpf" => null,
                "foto" => null
            ]
        ], "Conta criada com sucesso!");
    }

} catch (Exception $e) {
    error_log("[verify-code] Erro: " . $e->getMessage());
    response(false, null, "Erro ao verificar codigo", 500);
}
