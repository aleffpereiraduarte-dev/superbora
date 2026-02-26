<?php
/**
 * POST /api/mercado/partner/auth/totp-verify.php
 * Verifica código TOTP durante login (segundo fator)
 *
 * Body: {
 *   "temp_token": "jwt_temporario_do_login",
 *   "code": "123456"
 * }
 *
 * Retorna JWT completo se o código estiver correto.
 */

require_once __DIR__ . "/../../config/database.php";
require_once dirname(__DIR__, 4) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 4) . "/includes/classes/SimpleTOTP.php";

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, "Metodo nao permitido", 405);
}

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $input = getInput();
    $tempToken = $input['temp_token'] ?? '';
    $code = trim($input['code'] ?? '');

    if (empty($tempToken)) {
        response(false, null, "temp_token obrigatorio", 400);
    }

    if (strlen($code) !== 6 || !ctype_digit($code)) {
        response(false, null, "Codigo deve ter 6 digitos", 400);
    }

    // Validate temp token (it's a short-lived JWT with 2fa_pending claim)
    $payload = om_auth()->validateToken($tempToken);

    if (!$payload || $payload['type'] !== OmAuth::USER_TYPE_PARTNER) {
        response(false, null, "Token temporario invalido ou expirado", 401);
    }

    if (empty($payload['2fa_pending'])) {
        response(false, null, "Token nao e de verificacao 2FA", 400);
    }

    $partnerId = $payload['uid'];

    // Rate limiting: 5 TOTP attempts per 5 minutes
    $clientIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'];
    if (strpos($clientIp, ',') !== false) {
        $clientIp = trim(explode(',', $clientIp)[0]);
    }

    try {
        $stmtRate = $db->prepare("
            SELECT COUNT(*) FROM om_login_attempts
            WHERE ip_address = ? AND user_type = 'partner_totp'
            AND attempted_at > NOW() - INTERVAL '5 minutes'
        ");
        $stmtRate->execute([$clientIp]);
        if ((int)$stmtRate->fetchColumn() >= 5) {
            response(false, null, "Muitas tentativas. Aguarde 5 minutos.", 429);
        }

        $db->prepare("INSERT INTO om_login_attempts (ip_address, email, user_type, attempted_at) VALUES (?, ?, 'partner_totp', NOW())")
            ->execute([$clientIp, "partner:$partnerId"]);
    } catch (Exception $e) {
        // Non-critical
    }

    // Get partner TOTP secret
    $stmt = $db->prepare("
        SELECT partner_id, name, email, cnpj, logo, categoria, totp_secret, totp_enabled, status
        FROM om_market_partners
        WHERE partner_id = ?
    ");
    $stmt->execute([$partnerId]);
    $partner = $stmt->fetch();

    if (!$partner || !$partner['totp_enabled'] || empty($partner['totp_secret'])) {
        response(false, null, "2FA nao configurado para este parceiro", 400);
    }

    // Verify TOTP code
    if (!SimpleTOTP::verify($partner['totp_secret'], $code)) {
        response(false, null, "Codigo TOTP invalido", 401);
    }

    // TOTP verified — generate full JWT token
    $token = om_auth()->generateToken(
        OmAuth::USER_TYPE_PARTNER,
        (int)$partner['partner_id'],
        ['approved' => ($partner['status'] == '1'), 'name' => $partner['name']]
    );

    // Clear rate limit on success
    try {
        $db->prepare("DELETE FROM om_login_attempts WHERE ip_address = ? AND user_type = 'partner_totp'")->execute([$clientIp]);
    } catch (Exception $e) {
        // Non-critical
    }

    response(true, [
        "partner_id" => (int)$partner['partner_id'],
        "nome" => $partner['name'],
        "email" => $partner['email'],
        "cnpj" => $partner['cnpj'],
        "logo" => $partner['logo'],
        "categoria" => $partner['categoria'],
        "token" => $token,
        "status" => $partner['status'] == '1' ? 'approved' : 'pending',
    ], "2FA verificado com sucesso!");

} catch (Exception $e) {
    error_log("[partner/auth/totp-verify] Erro: " . $e->getMessage());
    response(false, null, "Erro ao verificar 2FA", 500);
}
