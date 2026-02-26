<?php
/**
 * POST /api/mercado/partner/change-password.php
 * Alterar senha do parceiro
 *
 * Body: {
 *   "current_password": "senhaAtual",
 *   "new_password": "novaSenha",
 *   "confirm_password": "novaSenha"
 * }
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        response(false, null, "Metodo nao permitido", 405);
    }

    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Token ausente", 401);

    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== OmAuth::USER_TYPE_PARTNER) {
        response(false, null, "Nao autorizado", 401);
    }

    $partnerId = $payload['uid'];
    $input = getInput();

    $currentPassword = $input['current_password'] ?? '';
    $newPassword = $input['new_password'] ?? '';
    $confirmPassword = $input['confirm_password'] ?? '';

    if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
        response(false, null, "Todos os campos sao obrigatorios", 400);
    }

    if ($newPassword !== $confirmPassword) {
        response(false, null, "Nova senha e confirmacao nao conferem", 400);
    }

    if (strlen($newPassword) < 8) {
        response(false, null, "Nova senha deve ter no minimo 8 caracteres", 400);
    }

    // SECURITY: Require at least one letter and one number (matching customer policy)
    if (!preg_match('/[a-zA-Z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
        response(false, null, "Senha deve conter pelo menos uma letra e um numero", 400);
    }

    if (strlen($newPassword) > 50) {
        response(false, null, "Nova senha deve ter no maximo 50 caracteres", 400);
    }

    // Buscar senha atual do parceiro
    $stmt = $db->prepare("SELECT login_password FROM om_market_partners WHERE partner_id = ? LIMIT 1");
    $stmt->execute([$partnerId]);
    $partner = $stmt->fetch();

    if (!$partner) {
        response(false, null, "Parceiro nao encontrado", 404);
    }

    $senhaHash = $partner['login_password'] ?? '';

    if (empty($senhaHash)) {
        response(false, null, "Conta sem senha definida. Entre em contato com o suporte.", 400);
    }

    // Verificar senha atual (mesma cascata do login)
    $passwordValid = false;

    if (password_verify($currentPassword, $senhaHash)) {
        $passwordValid = true;
    } elseif (hash_equals(md5($currentPassword), $senhaHash)) {
        $passwordValid = true;
    } elseif (hash_equals(sha1($currentPassword), $senhaHash)) {
        $passwordValid = true;
    }

    if (!$passwordValid) {
        response(false, null, "Senha atual incorreta", 401);
    }

    // Hash nova senha com Argon2ID
    $newHash = om_auth()->hashPassword($newPassword);
    $stmt = $db->prepare("UPDATE om_market_partners SET login_password = ? WHERE partner_id = ?");
    $stmt->execute([$newHash, $partnerId]);

    // SECURITY: Revoke all existing tokens on password change
    om_auth()->revokeAllTokens('partner', $partnerId);

    // Generate a fresh token for the current session
    $newToken = om_auth()->generateToken('partner', (int)$partnerId, [
        'name' => $payload['data']['name'] ?? '',
        'email' => $payload['data']['email'] ?? ''
    ]);

    response(true, ["token" => $newToken], "Senha alterada com sucesso! Faca login novamente em outros dispositivos.");

} catch (Exception $e) {
    error_log("[partner/change-password] Erro: " . $e->getMessage());
    response(false, null, "Erro ao alterar senha", 500);
}
