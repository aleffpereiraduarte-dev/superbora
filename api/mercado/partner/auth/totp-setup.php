<?php
/**
 * GET/POST/DELETE /api/mercado/partner/auth/totp-setup.php
 * Gerenciamento de 2FA/TOTP do parceiro
 *
 * GET    - Gera novo secret + QR code URL (para configurar no app)
 * POST   - Valida código TOTP e habilita 2FA { code: "123456" }
 * DELETE - Desabilita 2FA { senha: "current_password" }
 */

require_once __DIR__ . "/../../config/database.php";
require_once dirname(__DIR__, 4) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 4) . "/includes/classes/SimpleTOTP.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Token ausente", 401);

    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== OmAuth::USER_TYPE_PARTNER) {
        response(false, null, "Nao autorizado", 401);
    }

    $partnerId = $payload['uid'];
    $method = $_SERVER['REQUEST_METHOD'];

    // Columns totp_secret and totp_enabled must exist via migration

    // GET — Generate new secret + QR code
    if ($method === 'GET') {
        $stmt = $db->prepare("SELECT name, email, login_email, totp_enabled FROM om_market_partners WHERE partner_id = ?");
        $stmt->execute([$partnerId]);
        $partner = $stmt->fetch();

        if (!$partner) response(false, null, "Parceiro nao encontrado", 404);

        $secret = SimpleTOTP::generateSecret();
        $label = $partner['login_email'] ?: $partner['email'];
        $qrUrl = SimpleTOTP::getQRUrl($label, $secret);

        // Store pending secret (column totp_pending_secret must exist via migration)
        $db->prepare("UPDATE om_market_partners SET totp_pending_secret = ? WHERE partner_id = ?")
            ->execute([$secret, $partnerId]);

        response(true, [
            'secret' => $secret,
            'qr_url' => $qrUrl,
            'totp_enabled' => (bool)$partner['totp_enabled'],
            'label' => $label,
        ], "Secret gerado. Escaneie o QR code e envie o codigo para confirmar.");
    }

    // POST — Verify code and enable 2FA
    if ($method === 'POST') {
        $input = getInput();
        $code = trim($input['code'] ?? '');

        if (strlen($code) !== 6 || !ctype_digit($code)) {
            response(false, null, "Codigo deve ter 6 digitos", 400);
        }

        // Rate limiting: 5 attempts per 5 minutes for TOTP setup verification
        $clientIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'];
        try {
            $stmtRl = $db->prepare("
                SELECT COUNT(*) FROM om_login_attempts
                WHERE ip_address = ? AND user_type = 'totp_setup' AND email = ?
                AND attempted_at > NOW() - INTERVAL '5 minutes'
            ");
            $stmtRl->execute([$clientIp, (string)$partnerId]);
            if ((int)$stmtRl->fetchColumn() >= 5) {
                response(false, null, "Muitas tentativas. Aguarde 5 minutos.", 429);
            }
            $db->prepare("INSERT INTO om_login_attempts (ip_address, email, user_type) VALUES (?, ?, 'totp_setup')")
               ->execute([$clientIp, (string)$partnerId]);
        } catch (Exception $rlErr) {
            error_log("[totp-setup] Rate limit check error: " . $rlErr->getMessage());
        }

        $stmt = $db->prepare("SELECT totp_pending_secret, totp_secret, totp_enabled FROM om_market_partners WHERE partner_id = ?");
        $stmt->execute([$partnerId]);
        $partner = $stmt->fetch();

        // Use pending secret if available, fall back to totp_secret for backward compat
        $secretToVerify = $partner['totp_pending_secret'] ?? $partner['totp_secret'] ?? '';
        if (!$partner || empty($secretToVerify)) {
            response(false, null, "Nenhum secret configurado. Faca GET primeiro.", 400);
        }

        if (!SimpleTOTP::verify($secretToVerify, $code)) {
            response(false, null, "Codigo TOTP invalido. Tente novamente.", 400);
        }

        // Enable 2FA — move pending secret to active secret
        $db->prepare("UPDATE om_market_partners SET totp_enabled = 1, totp_secret = ?, totp_pending_secret = NULL WHERE partner_id = ?")
            ->execute([$secretToVerify, $partnerId]);

        response(true, [
            'totp_enabled' => true,
        ], "2FA ativado com sucesso!");
    }

    // DELETE — Disable 2FA (requires password)
    if ($method === 'DELETE') {
        $input = getInput();
        $senha = $input['senha'] ?? '';

        if (empty($senha)) {
            response(false, null, "Senha obrigatoria para desativar 2FA", 400);
        }

        $stmt = $db->prepare("SELECT login_password FROM om_market_partners WHERE partner_id = ?");
        $stmt->execute([$partnerId]);
        $partner = $stmt->fetch();

        if (!$partner) response(false, null, "Parceiro nao encontrado", 404);

        $passwordValid = password_verify($senha, $partner['login_password'])
            || hash_equals(md5($senha), $partner['login_password'])
            || hash_equals(sha1($senha), $partner['login_password']);

        if (!$passwordValid) {
            response(false, null, "Senha incorreta", 401);
        }

        $db->prepare("UPDATE om_market_partners SET totp_enabled = 0, totp_secret = NULL WHERE partner_id = ?")
            ->execute([$partnerId]);

        response(true, [
            'totp_enabled' => false,
        ], "2FA desativado com sucesso.");
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[partner/auth/totp-setup] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar 2FA", 500);
}
