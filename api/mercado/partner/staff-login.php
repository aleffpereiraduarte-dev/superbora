<?php
/**
 * POST /api/mercado/partner/staff-login.php
 *
 * Staff login endpoint (separate from partner owner login).
 * Authenticates against om_partner_team table.
 *
 * Body: {
 *   "email": "staff@email.com",
 *   "password": "senha123"
 * }
 *
 * Returns JWT token with type='staff', plus member info and role-based permissions.
 */

require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, "Metodo nao permitido", 405);
}

// Permissions map by role
const ROLE_PERMISSIONS = [
    'atendente' => ['orders', 'tables', 'kds'],
    'gerente'   => ['orders', 'tables', 'kds', 'products', 'promos', 'reports'],
    'admin'     => ['orders', 'tables', 'kds', 'products', 'promos', 'reports', 'financeiro', 'equipe', 'configuracoes'],
];

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $input = getInput();

    $email = filter_var(trim($input['email'] ?? ''), FILTER_SANITIZE_EMAIL);
    $password = $input['password'] ?? '';

    if (empty($email) || empty($password)) {
        response(false, null, "Email e senha obrigatorios", 400);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        response(false, null, "Email invalido", 400);
    }

    // Rate limiting
    $clientIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'];
    if (strpos($clientIp, ',') !== false) {
        $clientIp = trim(explode(',', $clientIp)[0]);
    }

    try {
        $db->exec("CREATE TABLE IF NOT EXISTS om_login_attempts (
            id SERIAL PRIMARY KEY,
            ip_address VARCHAR(45) NOT NULL,
            email VARCHAR(255) DEFAULT NULL,
            user_type VARCHAR(30) DEFAULT 'unknown',
            attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        $db->exec("DO $$ BEGIN
            ALTER TABLE om_login_attempts ADD COLUMN IF NOT EXISTS email VARCHAR(255) DEFAULT NULL;
            ALTER TABLE om_login_attempts ADD COLUMN IF NOT EXISTS user_type VARCHAR(30) DEFAULT 'unknown';
            ALTER TABLE om_login_attempts ADD COLUMN IF NOT EXISTS attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP;
        EXCEPTION WHEN OTHERS THEN NULL;
        END $$");

        // Per-IP rate limiting: 10 failed attempts per 15 minutes
        $stmtIp = $db->prepare("
            SELECT COUNT(*) FROM om_login_attempts
            WHERE ip_address = ? AND user_type = 'staff'
            AND attempted_at > NOW() - INTERVAL '15 minutes'
        ");
        $stmtIp->execute([$clientIp]);
        if ((int)$stmtIp->fetchColumn() >= 10) {
            response(false, null, "Muitas tentativas de login. Aguarde 15 minutos.", 429);
        }

        // Per-email rate limiting
        $stmtEmail = $db->prepare("
            SELECT COUNT(*) FROM om_login_attempts
            WHERE email = ? AND user_type = 'staff'
            AND attempted_at > NOW() - INTERVAL '15 minutes'
        ");
        $stmtEmail->execute([$email]);
        if ((int)$stmtEmail->fetchColumn() >= 15) {
            response(false, null, "Muitas tentativas com este email. Aguarde 15 minutos.", 429);
        }

        // Record attempt
        $db->prepare("INSERT INTO om_login_attempts (ip_address, email, user_type, attempted_at) VALUES (?, ?, 'staff', NOW())")
           ->execute([$clientIp, $email]);
    } catch (Exception $e) {
        error_log("[staff-login] Rate limit check error: " . $e->getMessage());
    }

    // Find staff member by email
    $stmt = $db->prepare("
        SELECT
            t.id,
            t.partner_id,
            t.name,
            t.email,
            t.password_hash,
            t.role,
            t.status,
            p.name as partner_name,
            p.status as partner_status,
            p.logo as partner_logo
        FROM om_partner_team t
        INNER JOIN om_market_partners p ON p.partner_id = t.partner_id
        WHERE LOWER(t.email) = LOWER(?)
        LIMIT 1
    ");
    $stmt->execute([$email]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$member) {
        error_log("[staff-login] Login failed: email not found - {$email}");
        response(false, null, "Credenciais invalidas", 401);
    }

    // Check member is active
    if ((int)$member['status'] !== 1) {
        error_log("[staff-login] Login failed: inactive member - {$email}");
        response(false, null, "Conta desativada. Entre em contato com o administrador.", 403);
    }

    // Check partner is active
    if ((int)$member['partner_status'] !== 1) {
        error_log("[staff-login] Login failed: inactive partner - partner_id {$member['partner_id']}");
        response(false, null, "Estabelecimento inativo. Entre em contato com o suporte.", 403);
    }

    // Check password is set
    if (empty($member['password_hash'])) {
        response(false, null, "Conta sem senha definida. Solicite ao administrador para definir sua senha.", 401);
    }

    // Verify password
    if (!password_verify($password, $member['password_hash'])) {
        error_log("[staff-login] Login failed: invalid credentials");
        response(false, null, "Credenciais invalidas", 401);
    }

    // Determine permissions
    $role = $member['role'] ?? 'atendente';
    $permissions = ROLE_PERMISSIONS[$role] ?? ROLE_PERMISSIONS['atendente'];

    // Generate JWT token with type='staff'
    $token = om_auth()->generateToken(
        'staff',
        (int)$member['id'],
        [
            'partner_id' => (int)$member['partner_id'],
            'role' => $role,
            'name' => $member['name'],
            'email' => $member['email'],
        ]
    );

    // Update last_login
    try {
        $stmt = $db->prepare("UPDATE om_partner_team SET last_login = NOW() WHERE id = ?");
        $stmt->execute([$member['id']]);
    } catch (Exception $e) {
        // Non-critical
    }

    // Clear rate limit entries on success
    try {
        $db->prepare("DELETE FROM om_login_attempts WHERE ip_address = ? AND user_type = 'staff'")->execute([$clientIp]);
    } catch (Exception $e) {
        // Non-critical
    }

    error_log("[staff-login] Login successful: {$email} (partner_id: {$member['partner_id']}, role: {$role})");

    response(true, [
        'token' => $token,
        'member' => [
            'id' => (int)$member['id'],
            'name' => $member['name'],
            'email' => $member['email'],
            'role' => $role,
        ],
        'partner_id' => (int)$member['partner_id'],
        'partner_name' => $member['partner_name'],
        'partner_logo' => $member['partner_logo'],
        'permissions' => $permissions,
    ], "Login realizado com sucesso!");

} catch (Exception $e) {
    error_log("[staff-login] Error: " . $e->getMessage());
    response(false, null, "Erro ao realizar login. Tente novamente.", 500);
}
