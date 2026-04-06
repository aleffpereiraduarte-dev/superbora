<?php
/**
 * POST /api/mercado/partner/auth/forgot-password.php
 * Password reset for partner accounts
 *
 * POST action=request: Send reset code to email
 * POST action=verify: Verify code
 * POST action=reset: Set new password
 */
require_once __DIR__ . '/../../config/database.php';
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, 'Método não permitido', 405);
}

try {
    $db = getDB();
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $input['action'] ?? 'request';

    switch ($action) {
        case 'request': {
            $email = strtolower(trim($input['email'] ?? ''));
            if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                response(false, null, 'Email inválido', 400);
            }

            // Check partner exists
            $stmt = $db->prepare("SELECT partner_id, name, trade_name FROM om_market_partners WHERE login_email = ? OR email = ? LIMIT 1");
            $stmt->execute([$email, $email]);
            $partner = $stmt->fetch();

            // Always return success (don't reveal if email exists)
            if (!$partner) {
                response(true, ['message' => 'Se o email estiver cadastrado, você receberá um código de recuperação.']);
            }

            // Rate limit: max 3 requests per hour per email
            $stmt = $db->prepare("SELECT COUNT(*) FROM sb_password_resets WHERE email = ? AND created_at > NOW() - INTERVAL '1 hour'");
            $stmt->execute([$email]);
            if ((int)$stmt->fetchColumn() >= 3) {
                response(false, null, 'Muitas tentativas. Aguarde 1 hora.', 429);
            }

            // Generate 6-digit code
            $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $expiresAt = date('Y-m-d H:i:s', time() + 900); // 15 min

            // Store reset code
            $db->prepare("INSERT INTO sb_password_resets (email, code, user_type, expires_at) VALUES (?, ?, 'partner', ?)")
               ->execute([$email, password_hash($code, PASSWORD_DEFAULT), $expiresAt]);

            // Send email with code
            $name = $partner['trade_name'] ?: $partner['name'];
            try {
                require_once __DIR__ . '/../../helpers/email.php';
                if (function_exists('sendEmail')) {
                    sendEmail($email, "Código de recuperação - SuperBora",
                        "Olá $name,\n\nSeu código de recuperação é: $code\n\nEste código expira em 15 minutos.\n\nSe você não solicitou, ignore este email.\n\nSuperBora");
                }
            } catch (\Throwable $e) {
                error_log("[partner-forgot] Email send failed: " . $e->getMessage());
            }

            // Also log code for debugging (remove in production)
            error_log("[partner-forgot] Code for $email: $code");

            response(true, ['message' => 'Se o email estiver cadastrado, você receberá um código de recuperação.']);
            break;
        }

        case 'verify': {
            $email = strtolower(trim($input['email'] ?? ''));
            $code = trim($input['code'] ?? '');

            if (!$email || !$code || strlen($code) !== 6) {
                response(false, null, 'Email e código são obrigatórios', 400);
            }

            // Find latest valid reset
            $stmt = $db->prepare("SELECT id, code FROM sb_password_resets WHERE email = ? AND user_type = 'partner' AND used = false AND expires_at > NOW() ORDER BY created_at DESC LIMIT 1");
            $stmt->execute([$email]);
            $reset = $stmt->fetch();

            if (!$reset || !password_verify($code, $reset['code'])) {
                response(false, null, 'Código inválido ou expirado', 400);
            }

            // Generate temp token for password reset
            $token = bin2hex(random_bytes(32));
            $db->prepare("UPDATE sb_password_resets SET verify_token = ?, verified_at = NOW() WHERE id = ?")
               ->execute([$token, $reset['id']]);

            response(true, ['token' => $token, 'message' => 'Código verificado. Use o token para definir nova senha.']);
            break;
        }

        case 'reset': {
            $token = trim($input['token'] ?? '');
            $password = $input['password'] ?? '';

            if (!$token || strlen($password) < 6) {
                response(false, null, 'Token e senha (mín 6 chars) obrigatórios', 400);
            }

            // Find verified reset
            $stmt = $db->prepare("SELECT id, email FROM sb_password_resets WHERE verify_token = ? AND user_type = 'partner' AND used = false AND verified_at IS NOT NULL AND expires_at > NOW() LIMIT 1");
            $stmt->execute([$token]);
            $reset = $stmt->fetch();

            if (!$reset) {
                response(false, null, 'Token inválido ou expirado', 400);
            }

            // Update password
            $hash = password_hash($password, PASSWORD_ARGON2ID);
            $db->prepare("UPDATE om_market_partners SET login_password = ? WHERE login_email = ? OR email = ?")
               ->execute([$hash, $reset['email'], $reset['email']]);

            // Mark reset as used
            $db->prepare("UPDATE sb_password_resets SET used = true WHERE id = ?")->execute([$reset['id']]);

            // Invalidate all other resets for this email
            $db->prepare("UPDATE sb_password_resets SET used = true WHERE email = ? AND id != ?")->execute([$reset['email'], $reset['id']]);

            response(true, ['message' => 'Senha alterada com sucesso!']);
            break;
        }

        default:
            response(false, null, 'Ação inválida', 400);
    }

} catch (\Throwable $e) {
    error_log("[partner-forgot] Error: " . $e->getMessage());
    response(false, null, 'Erro interno', 500);
}
