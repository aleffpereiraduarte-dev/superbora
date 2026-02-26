<?php
/**
 * DELETE /api/mercado/customer/account.php
 * Exclui (soft-delete) a conta do cliente - LGPD compliance
 * Requer confirmacao de senha
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
setCorsHeaders();

header("Access-Control-Allow-Methods: DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "DELETE") {
    response(false, null, "Metodo nao permitido", 405);
}

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    // Autenticar cliente
    $token = om_auth()->getTokenFromRequest();
    if (!$token) {
        response(false, null, "Token nao fornecido", 401);
    }

    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== 'customer') {
        response(false, null, "Nao autorizado", 401);
    }

    $customerId = $payload['uid'];
    $input = getInput();
    $senha = $input['senha'] ?? $input['password'] ?? '';

    if (empty($senha)) {
        response(false, null, "Senha e obrigatoria para confirmar a exclusao", 400);
    }

    // Buscar dados do cliente
    $stmt = $db->prepare("
        SELECT customer_id, name, email, phone, password_hash, is_active
        FROM om_customers
        WHERE customer_id = ? AND is_active = '1'
    ");
    $stmt->execute([$customerId]);
    $customer = $stmt->fetch();

    if (!$customer) {
        response(false, null, "Cliente nao encontrado", 404);
    }

    // Verificar senha
    $storedHash = $customer['password_hash'] ?? '';
    $passwordValid = false;

    if (!empty($storedHash)) {
        if (password_verify($senha, $storedHash)) {
            $passwordValid = true;
        } elseif (hash_equals(md5($senha), $storedHash)) {
            $passwordValid = true;
        } elseif (hash_equals(sha1($senha), $storedHash)) {
            $passwordValid = true;
        }
    }

    if (!$passwordValid) {
        response(false, null, "Senha incorreta", 401);
    }

    // Upgrade legacy MD5/SHA1 hash to bcrypt (defense-in-depth, login.php also does this)
    if ($passwordValid && !empty($storedHash) && !password_verify($senha, $storedHash)) {
        try {
            $newHash = password_hash($senha, PASSWORD_BCRYPT);
            $db->prepare("UPDATE om_customers SET password_hash = ? WHERE customer_id = ?")->execute([$newHash, $customerId]);
            error_log("[customer/account] Upgraded legacy password hash to bcrypt for customer #{$customerId}");
        } catch (Exception $hashErr) {
            error_log("[customer/account] Failed to upgrade hash: " . $hashErr->getMessage());
        }
    }

    // Soft-delete: desativar conta e anonimizar dados (LGPD)
    $anonymizedEmail = 'deleted_' . $customerId . '_' . time() . '@removed.local';
    $anonymizedName = 'Usuario Removido';
    $anonymizedPhone = '';

    $db->beginTransaction();

    try {
        // Anonimizar e desativar o cliente
        $stmtDelete = $db->prepare("
            UPDATE om_customers
            SET is_active = 0,
                name = ?,
                email = ?,
                phone = ?,
                cpf = NULL,
                password_hash = '',
                foto = NULL,
                deleted_at = NOW(),
                updated_at = NOW()
            WHERE customer_id = ?
        ");
        $stmtDelete->execute([$anonymizedName, $anonymizedEmail, $anonymizedPhone, $customerId]);

        // Desativar enderecos
        $stmtAddr = $db->prepare("
            UPDATE om_customer_addresses
            SET is_active = 0
            WHERE customer_id = ?
        ");
        $stmtAddr->execute([$customerId]);

        // Revogar todos os tokens (invalidar pela tabela se existir)
        try {
            $stmtTokens = $db->prepare("
                DELETE FROM om_auth_tokens WHERE user_id = ? AND user_type = 'customer'
            ");
            $stmtTokens->execute([$customerId]);
        } catch (Exception $e) {
            // Tabela de tokens pode nao existir - nao e critico
            error_log("[customer/account] Aviso ao revogar tokens: " . $e->getMessage());
        }

        $db->commit();

        response(true, null, "Conta excluida com sucesso. Seus dados foram removidos conforme a LGPD.");

    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    error_log("[customer/account] Erro: " . $e->getMessage());
    response(false, null, "Erro ao excluir conta", 500);
}
