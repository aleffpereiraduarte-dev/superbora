<?php
/**
 * POST /api/mercado/auth/change-password.php
 * Alterar senha do cliente autenticado
 * Campos: current_password, new_password, confirm_password
 */
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../helpers/rate-limit.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, "Metodo nao permitido", 405);
}

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    // Requer autenticacao do cliente
    $token = om_auth()->getTokenFromRequest();
    if (!$token) {
        response(false, null, "Autenticacao necessaria", 401);
    }
    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== 'customer') {
        response(false, null, "Token invalido", 401);
    }
    $customerId = (int)$payload['uid'];

    // SECURITY: Rate limit password changes (5 per hour per user)
    if (!checkRateLimit("change_pwd_{$customerId}", 5, 60)) {
        response(false, null, "Muitas tentativas. Aguarde antes de tentar novamente.", 429);
    }

    $input = getInput();

    $currentPassword = $input['current_password'] ?? $input['senha_atual'] ?? '';
    $newPassword = $input['new_password'] ?? $input['nova_senha'] ?? '';
    $confirmPassword = $input['confirm_password'] ?? $input['confirmar_senha'] ?? '';

    // Validacoes
    if (empty($currentPassword)) {
        response(false, null, "Senha atual obrigatoria", 400);
    }
    if (empty($newPassword)) {
        response(false, null, "Nova senha obrigatoria", 400);
    }
    if (strlen($newPassword) < 8) {
        response(false, null, "Nova senha deve ter no minimo 8 caracteres", 400);
    }
    // SECURITY: Require at least one letter and one number
    if (!preg_match('/[a-zA-Z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
        response(false, null, "Senha deve conter pelo menos uma letra e um numero", 400);
    }
    if ($newPassword !== $confirmPassword) {
        response(false, null, "Nova senha e confirmacao nao conferem", 400);
    }

    // Buscar hash da senha atual
    $stmt = $db->prepare("SELECT password_hash FROM om_customers WHERE customer_id = ? AND is_active = '1'");
    $stmt->execute([$customerId]);
    $customer = $stmt->fetch();

    if (!$customer) {
        response(false, null, "Cliente nao encontrado", 404);
    }

    $storedHash = $customer['password_hash'] ?? '';
    if (empty($storedHash)) {
        response(false, null, "Conta sem senha definida", 400);
    }

    // Verificar senha atual - suporta multiplos formatos (mesmo padrao do login)
    $passwordValid = false;
    if (password_verify($currentPassword, $storedHash)) {
        $passwordValid = true;
    } elseif (hash_equals(md5($currentPassword), $storedHash)) {
        $passwordValid = true;
    } elseif (hash_equals(sha1($currentPassword), $storedHash)) {
        $passwordValid = true;
    }

    if (!$passwordValid) {
        response(false, null, "Senha atual incorreta", 401);
    }

    // Hash da nova senha com Argon2
    $newHash = om_auth()->hashPassword($newPassword);

    // Atualizar no banco
    $stmt = $db->prepare("UPDATE om_customers SET password_hash = ?, updated_at = NOW() WHERE customer_id = ?");
    $stmt->execute([$newHash, $customerId]);

    // SECURITY: Revoke all existing tokens on password change to prevent session hijacking
    om_auth()->revokeAllTokens('customer', $customerId);

    // Generate a fresh token for the current session
    $newToken = om_auth()->generateToken('customer', $customerId, [
        'name' => $payload['data']['name'] ?? '',
        'email' => $payload['data']['email'] ?? ''
    ]);

    response(true, ["token" => $newToken], "Senha alterada com sucesso! Faca login novamente em outros dispositivos.");

} catch (Exception $e) {
    error_log("[API Auth ChangePassword] Erro: " . $e->getMessage());
    response(false, null, "Erro ao alterar senha", 500);
}
