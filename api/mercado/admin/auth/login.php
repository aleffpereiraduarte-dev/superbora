<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * POST /api/mercado/admin/auth/login.php
 * Login do administrador
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Body: {
 *   "email": "admin@onemundo.com",
 *   "senha": "minhasenha"
 * }
 *
 * Retorna token para autenticacao no painel administrativo
 * Inclui rate limiting: 5 tentativas por 15 minutos
 */

require_once __DIR__ . "/../../config/database.php";
require_once dirname(__DIR__, 4) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 4) . "/includes/classes/OmAudit.php";

setCorsHeaders();

try {
    $input = getInput();
    // Log only non-sensitive data (email/method) - NEVER log passwords
    $safeEmail = $input['email'] ?? 'unknown';
    error_log("[admin/auth/login] EMAIL: " . $safeEmail . " | METHOD: " . $_SERVER['REQUEST_METHOD']);
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

    // Rate limiting: 5 tentativas por 15 minutos por IP
    $clientIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'];

    // Limpar IPs multiplos (pegar primeiro)
    if (strpos($clientIp, ',') !== false) {
        $clientIp = trim(explode(',', $clientIp)[0]);
    }

    try {
        // Garantir que tabela existe
        $db->exec("
            CREATE TABLE IF NOT EXISTS om_login_attempts (
                id SERIAL PRIMARY KEY,
                ip_address VARCHAR(45) NOT NULL,
                email VARCHAR(255) DEFAULT NULL,
                user_type VARCHAR(30) DEFAULT 'admin',
                attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_om_login_attempts_ip_time ON om_login_attempts (ip_address, attempted_at)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_om_login_attempts_email_time ON om_login_attempts (email, attempted_at)");

        // Contar tentativas recentes deste IP
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM om_login_attempts
            WHERE ip_address = ? AND user_type = 'admin'
            AND attempted_at > NOW() - INTERVAL '15 minutes'
        ");
        $stmt->execute([$clientIp]);
        $attempts = (int)$stmt->fetchColumn();

        if ($attempts >= 5) {
            error_log("[admin/auth/login] Rate limit excedido para IP: $clientIp");
            response(false, null, "Muitas tentativas de login. Aguarde 15 minutos.", 429);
        }

        // Per-email rate limiting (prevents distributed brute force)
        $stmtEmail = $db->prepare("
            SELECT COUNT(*) FROM om_login_attempts
            WHERE email = ? AND user_type = 'admin'
            AND attempted_at > NOW() - INTERVAL '15 minutes'
        ");
        $stmtEmail->execute([$email]);
        $emailAttempts = (int)$stmtEmail->fetchColumn();

        if ($emailAttempts >= 10) {
            error_log("[admin/auth/login] Rate limit per-email excedido: $email");
            response(false, null, "Muitas tentativas com este email. Aguarde 15 minutos.", 429);
        }

        // Registrar tentativa
        $stmt = $db->prepare("
            INSERT INTO om_login_attempts (ip_address, email, user_type, attempted_at)
            VALUES (?, ?, 'admin', NOW())
        ");
        $stmt->execute([$clientIp, $email]);

    } catch (Exception $e) {
        // Se rate limiting falhar, apenas logar e continuar
        error_log("[admin/auth/login] Rate limit check falhou: " . $e->getMessage());
    }

    // Buscar admin
    $stmt = $db->prepare("
        SELECT admin_id, name, email, password AS password_hash, role, status, is_rh
        FROM om_admins
        WHERE email = ?
        LIMIT 1
    ");
    $stmt->execute([$email]);
    $admin = $stmt->fetch();

    if (!$admin) {
        om_audit()->logLogin('admin', 0, false);
        response(false, null, "Credenciais invalidas", 401);
    }

    // Verificar senha
    $senhaHash = $admin["password_hash"] ?? "";

    if (empty($senhaHash)) {
        error_log("[admin/auth/login] Conta sem senha definida para email: $email");
        response(false, null, "Credenciais invalidas", 401);
    }

    // SECURITY: Only use password_verify (bcrypt, Argon2) - no MD5/SHA1 fallback
    // Legacy insecure hashes must be reset via password recovery flow
    $passwordValid = password_verify($senha, $senhaHash);

    if (!$passwordValid) {
        om_audit()->logLogin('admin', (int)$admin["admin_id"], false);
        response(false, null, "Credenciais invalidas", 401);
    }

    // Check if password needs rehashing (algorithm upgrade)
    if (password_needs_rehash($senhaHash, PASSWORD_ARGON2ID)) {
        $newHash = om_auth()->hashPassword($senha);
        $stmtUpdate = $db->prepare("UPDATE om_admins SET password = ? WHERE admin_id = ?");
        $stmtUpdate->execute([$newHash, $admin["admin_id"]]);
        error_log("[admin/auth/login] Password rehashed for admin_id: " . $admin["admin_id"]);
    }

    // Verificar status da conta
    $status = (int)($admin["status"] ?? 1);

    if ($status === 0) {
        error_log("[admin/auth/login] Tentativa de login em conta inativa: admin_id=" . $admin["admin_id"]);
        response(false, null, "Credenciais invalidas", 401);
    }

    if ($status === 2) {
        error_log("[admin/auth/login] Tentativa de login em conta suspensa: admin_id=" . $admin["admin_id"]);
        response(false, null, "Credenciais invalidas", 401);
    }

    // Determinar tipo do token (admin ou rh)
    $isRh = (int)($admin["is_rh"] ?? 0);
    $tokenType = $isRh ? OmAuth::USER_TYPE_RH : OmAuth::USER_TYPE_ADMIN;

    // Gerar token com role nos dados extras
    $token = om_auth()->generateToken(
        $tokenType,
        (int)$admin["admin_id"],
        [
            'name' => $admin["name"],
            'role' => $admin["role"] ?? 'admin',
            'is_rh' => $isRh
        ]
    );

    // Atualizar ultimo login
    $stmt = $db->prepare("UPDATE om_admins SET last_login = NOW() WHERE admin_id = ?");
    $stmt->execute([$admin["admin_id"]]);

    // Limpar tentativas de login bem-sucedidas para este IP
    try {
        $stmt = $db->prepare("
            DELETE FROM om_login_attempts
            WHERE ip_address = ? AND user_type = 'admin'
        ");
        $stmt->execute([$clientIp]);
    } catch (Exception $e) {
        // Nao e critico
    }

    // Log de login bem-sucedido
    om_audit()->logLogin('admin', (int)$admin["admin_id"], true);

    response(true, [
        "admin_id" => (int)$admin["admin_id"],
        "nome" => $admin["name"],
        "email" => $admin["email"],
        "role" => $admin["role"] ?? "admin",
        "is_rh" => (bool)$isRh,
        "token" => $token,
        "status" => "active"
    ], "Login realizado com sucesso!");

} catch (Exception $e) {
    error_log("[admin/auth/login] Erro: " . $e->getMessage());
    response(false, null, "Erro ao realizar login. Tente novamente.", 500);
}
