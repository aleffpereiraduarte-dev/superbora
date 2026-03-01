<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * POST /api/mercado/partner/auth/login.php
 * Login do parceiro (mercado/restaurante/farmacia/loja)
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Body: {
 *   "email": "parceiro@email.com",
 *   "senha": "minhasenha"
 * }
 *
 * Retorna token para autenticacao nas APIs do painel parceiro
 */

require_once __DIR__ . "/../../config/database.php";
require_once dirname(__DIR__, 4) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 4) . "/includes/classes/OmAudit.php";

setCorsHeaders();

try {
    $input = getInput();
    $db = getDB();

    // Configurar classes
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $email = filter_var(trim($input["email"] ?? ""), FILTER_SANITIZE_EMAIL);
    $senha = $input["senha"] ?? "";

    if (empty($email) || empty($senha)) {
        response(false, null, "Email e senha obrigatorios", 400);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        response(false, null, "Email invalido", 400);
    }

    // Rate limiting: 10 attempts per 15 minutes per IP, 15 per email
    $clientIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'];
    if (strpos($clientIp, ',') !== false) {
        $clientIp = trim(explode(',', $clientIp)[0]);
    }

    try {
        // Table om_login_attempts must exist via migration

        // Per-IP rate limiting
        $stmtIp = $db->prepare("
            SELECT COUNT(*) FROM om_login_attempts
            WHERE ip_address = ? AND user_type = 'partner'
            AND attempted_at > NOW() - INTERVAL '15 minutes'
        ");
        $stmtIp->execute([$clientIp]);
        if ((int)$stmtIp->fetchColumn() >= 10) {
            error_log("[partner/auth/login] Rate limit IP excedido: $clientIp");
            response(false, null, "Muitas tentativas de login. Aguarde 15 minutos.", 429);
        }

        // Per-email rate limiting (prevents distributed brute force)
        $stmtEmail = $db->prepare("
            SELECT COUNT(*) FROM om_login_attempts
            WHERE email = ? AND user_type = 'partner'
            AND attempted_at > NOW() - INTERVAL '15 minutes'
        ");
        $stmtEmail->execute([$email]);
        if ((int)$stmtEmail->fetchColumn() >= 15) {
            error_log("[partner/auth/login] Rate limit per-email excedido: $email");
            response(false, null, "Muitas tentativas com este email. Aguarde 15 minutos.", 429);
        }

        // Log attempt
        $db->prepare("INSERT INTO om_login_attempts (ip_address, email, user_type, attempted_at) VALUES (?, ?, 'partner', NOW())")
            ->execute([$clientIp, $email]);
    } catch (Exception $e) {
        error_log("[partner/auth/login] Rate limit check error: " . $e->getMessage());
    }

    // Buscar parceiro por login_email ou email
    $stmt = $db->prepare("
        SELECT partner_id, name, cnpj, email, login_email, login_password, status, logo, phone, categoria,
               COALESCE(totp_enabled::int, 0) as totp_enabled, totp_secret
        FROM om_market_partners
        WHERE login_email = ? OR email = ?
        LIMIT 1
    ");
    $stmt->execute([$email, $email]);
    $partner = $stmt->fetch();

    if (!$partner) {
        om_audit()->logLogin('partner', 0, false);
        response(false, null, "Credenciais invalidas", 401);
    }

    // Verificar senha
    $senhaHash = $partner["login_password"] ?? "";

    if (empty($senhaHash)) {
        response(false, null, "Conta sem senha definida. Entre em contato com o suporte.", 401);
    }

    $passwordValid = false;

    // 1. password_verify (bcrypt, Argon2)
    if (password_verify($senha, $senhaHash)) {
        $passwordValid = true;
    }
    // 2. MD5 legacy - timing-safe comparison
    elseif (hash_equals(md5($senha), $senhaHash)) {
        $passwordValid = true;
        error_log("[SECURITY WARNING] Partner {$partner['partner_id']} using MD5 password hash - upgrading");
    }
    // 3. SHA1 legacy - timing-safe comparison
    elseif (hash_equals(sha1($senha), $senhaHash)) {
        $passwordValid = true;
        error_log("[SECURITY WARNING] Partner {$partner['partner_id']} using SHA1 password hash - upgrading");
    }

    if (!$passwordValid) {
        om_audit()->logLogin('partner', (int)$partner["partner_id"], false);
        response(false, null, "Credenciais invalidas", 401);
    }

    // Se senha em formato antigo, atualizar para Argon2
    if (!password_get_info($senhaHash)['algo']) {
        $newHash = om_auth()->hashPassword($senha);
        $stmtUpdate = $db->prepare("UPDATE om_market_partners SET login_password = ? WHERE partner_id = ?");
        $stmtUpdate->execute([$newHash, $partner["partner_id"]]);
    }

    // Verificar status do parceiro (handles both numeric '0','1','2','3' and string 'pending','active','rejected','suspended')
    $status = $partner["status"];

    if ($status === '0' || $status === 'pending') {
        // Pendente de aprovacao - permite login limitado
        $token = om_auth()->generateToken(
            OmAuth::USER_TYPE_PARTNER,
            (int)$partner["partner_id"],
            ['approved' => false, 'status' => 'pending', 'name' => $partner["name"]]
        );

        $stmt = $db->prepare("UPDATE om_market_partners SET last_login = NOW() WHERE partner_id = ?");
        $stmt->execute([$partner["partner_id"]]);

        om_audit()->logLogin('partner', (int)$partner["partner_id"], true);

        response(true, [
            "partner_id" => (int)$partner["partner_id"],
            "nome" => $partner["name"],
            "email" => $partner["email"],
            "cnpj" => $partner["cnpj"],
            "logo" => $partner["logo"],
            "token" => $token,
            "status" => "pending",
            "mensagem_status" => "Seu cadastro esta sendo analisado pela equipe. Voce recebera uma notificacao quando for aprovado."
        ], "Login realizado! Aguardando aprovacao.");
    }

    if ($status === '2' || $status === 'rejected') {
        response(false, [
            "status" => "rejected"
        ], "Cadastro rejeitado. Entre em contato com o suporte para mais informacoes.", 403);
    }

    if ($status === '3' || $status === 'suspended') {
        response(false, [
            "status" => "suspended"
        ], "Sua conta esta temporariamente suspensa. Entre em contato com o suporte.", 403);
    }

    // Check 2FA before issuing full token
    if ($partner['totp_enabled'] && !empty($partner['totp_secret'])) {
        // Generate temp token for 2FA step — SHORT TTL (10 min) to limit exposure
        $tempToken = om_auth()->generateToken(
            OmAuth::USER_TYPE_PARTNER,
            (int)$partner["partner_id"],
            ['2fa_pending' => true, 'name' => $partner["name"], 'temp_exp' => time() + 600]
        );

        om_audit()->logLogin('partner', (int)$partner["partner_id"], true);

        response(true, [
            "requires_2fa" => true,
            "partner_id" => (int)$partner["partner_id"],
            "nome" => $partner["name"],
            "temp_token" => $tempToken,
        ], "Verificacao 2FA necessaria.");
    }

    // Status 1 = Aprovado - Gerar token completo
    $token = om_auth()->generateToken(
        OmAuth::USER_TYPE_PARTNER,
        (int)$partner["partner_id"],
        ['approved' => true, 'name' => $partner["name"]]
    );

    // Atualizar ultimo login
    $stmt = $db->prepare("UPDATE om_market_partners SET last_login = NOW() WHERE partner_id = ?");
    $stmt->execute([$partner["partner_id"]]);

    // Clear rate limit entries on successful login
    try {
        $db->prepare("DELETE FROM om_login_attempts WHERE ip_address = ? AND user_type = 'partner'")->execute([$clientIp]);
    } catch (Exception $e) {
        // Non-critical
    }

    // Log de login bem-sucedido
    om_audit()->logLogin('partner', (int)$partner["partner_id"], true);

    response(true, [
        "partner_id" => (int)$partner["partner_id"],
        "nome" => $partner["name"],
        "email" => $partner["email"],
        "cnpj" => $partner["cnpj"],
        "logo" => $partner["logo"],
        "categoria" => $partner["categoria"] ?? null,
        "token" => $token,
        "status" => "approved"
    ], "Login realizado com sucesso!");

} catch (Exception $e) {
    error_log("[partner/auth/login] Erro: " . $e->getMessage());
    response(false, null, "Erro ao realizar login. Tente novamente.", 500);
}
