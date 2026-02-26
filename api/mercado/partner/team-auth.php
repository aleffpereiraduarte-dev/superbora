<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * POST /api/mercado/partner/team-auth.php
 * Autenticacao de membros da equipe do parceiro
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Actions:
 *   - login: Autenticar membro da equipe por email/senha
 *   - validate: Validar token de membro da equipe
 *
 * Body (login):
 * {
 *   "action": "login",
 *   "email": "membro@email.com",
 *   "password": "senha123"
 * }
 *
 * Body (validate):
 * {
 *   "action": "validate",
 *   "token": "token_jwt_aqui"
 * }
 *
 * Retorna token para autenticacao nas APIs do painel parceiro
 */

require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

// User type constante para membros de equipe
const USER_TYPE_TEAM = 'team';

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $input = getInput();
    $action = trim($input['action'] ?? '');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        response(false, null, "Metodo nao permitido", 405);
    }

    // ══════════════════════════════════════════════════════════════════════
    // ACTION: LOGIN
    // ══════════════════════════════════════════════════════════════════════
    if ($action === 'login') {
        $email = filter_var(trim($input['email'] ?? ''), FILTER_SANITIZE_EMAIL);
        $password = $input['password'] ?? '';

        if (empty($email) || empty($password)) {
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
            // Per-IP rate limiting
            $stmtIp = $db->prepare("
                SELECT COUNT(*) FROM om_login_attempts
                WHERE ip_address = ? AND user_type = 'team'
                AND attempted_at > NOW() - INTERVAL '15 minutes'
            ");
            $stmtIp->execute([$clientIp]);
            if ((int)$stmtIp->fetchColumn() >= 10) {
                error_log("[team-auth] Rate limit IP excedido: $clientIp");
                response(false, null, "Muitas tentativas de login. Aguarde 15 minutos.", 429);
            }

            // Per-email rate limiting
            $stmtEmail = $db->prepare("
                SELECT COUNT(*) FROM om_login_attempts
                WHERE email = ? AND user_type = 'team'
                AND attempted_at > NOW() - INTERVAL '15 minutes'
            ");
            $stmtEmail->execute([$email]);
            if ((int)$stmtEmail->fetchColumn() >= 15) {
                error_log("[team-auth] Rate limit email excedido: $email");
                response(false, null, "Muitas tentativas de login para este email. Aguarde 15 minutos.", 429);
            }

            // Record attempt
            $db->prepare("INSERT INTO om_login_attempts (ip_address, email, user_type) VALUES (?, ?, 'team')")
               ->execute([$clientIp, $email]);
        } catch (Exception $rlErr) {
            // If rate limit table doesn't exist, log but don't block login
            error_log("[team-auth] Rate limit check error: " . $rlErr->getMessage());
        }

        // Buscar membro da equipe pelo email
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
            WHERE t.email = ?
            LIMIT 1
        ");
        $stmt->execute([$email]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$member) {
            error_log("[team-auth] Login falhou: email nao encontrado - {$email}");
            response(false, null, "Credenciais invalidas", 401);
        }

        // Verificar se o membro esta ativo
        if ((int)$member['status'] !== 1) {
            error_log("[team-auth] Login falhou: membro inativo - {$email}");
            response(false, null, "Conta desativada. Entre em contato com o administrador.", 403);
        }

        // Verificar se o parceiro esta ativo
        if ((int)$member['partner_status'] !== 1) {
            error_log("[team-auth] Login falhou: parceiro inativo - partner_id {$member['partner_id']}");
            response(false, null, "Estabelecimento inativo. Entre em contato com o suporte.", 403);
        }

        // Verificar se tem senha definida
        if (empty($member['password_hash'])) {
            response(false, null, "Conta sem senha definida. Solicite ao administrador para definir sua senha.", 401);
        }

        // Verificar senha usando password_verify
        if (!password_verify($password, $member['password_hash'])) {
            error_log("[team-auth] Login falhou: senha incorreta - {$email}");
            response(false, null, "Credenciais invalidas", 401);
        }

        // Gerar token JWT para membro da equipe
        $token = om_auth()->generateToken(
            USER_TYPE_TEAM,
            (int)$member['id'],
            [
                'partner_id' => (int)$member['partner_id'],
                'role' => $member['role'],
                'name' => $member['name'],
                'email' => $member['email']
            ]
        );

        // Atualizar ultimo login do membro (graceful - ignora se coluna nao existir)
        try {
            $stmt = $db->prepare("UPDATE om_partner_team SET last_login = NOW() WHERE id = ?");
            $stmt->execute([$member['id']]);
        } catch (Exception $e) {
            // Coluna last_login pode nao existir - ignorar
        }

        error_log("[team-auth] Login bem-sucedido: {$email} (partner_id: {$member['partner_id']}, role: {$member['role']})");

        response(true, [
            'token' => $token,
            'user' => [
                'id' => (int)$member['id'],
                'name' => $member['name'],
                'email' => $member['email'],
                'role' => $member['role']
            ],
            'partner_id' => (int)$member['partner_id'],
            'partner_name' => $member['partner_name'],
            'partner_logo' => $member['partner_logo']
        ], "Login realizado com sucesso!");
    }

    // ══════════════════════════════════════════════════════════════════════
    // ACTION: VALIDATE
    // ══════════════════════════════════════════════════════════════════════
    if ($action === 'validate') {
        $token = trim($input['token'] ?? '');

        if (empty($token)) {
            // Tentar pegar do header Authorization
            $token = om_auth()->getTokenFromRequest();
        }

        if (empty($token)) {
            response(false, null, "Token ausente", 400);
        }

        // Validar o token
        $payload = om_auth()->validateToken($token);

        if (!$payload) {
            response(false, null, "Token invalido ou expirado", 401);
        }

        // Verificar se e um token de membro de equipe
        if ($payload['type'] !== USER_TYPE_TEAM) {
            response(false, null, "Token nao pertence a um membro de equipe", 401);
        }

        $memberId = $payload['uid'];
        $extraData = $payload['data'] ?? [];

        // Buscar dados atualizados do membro
        $stmt = $db->prepare("
            SELECT
                t.id,
                t.partner_id,
                t.name,
                t.email,
                t.role,
                t.status,
                p.name as partner_name,
                p.status as partner_status,
                p.logo as partner_logo
            FROM om_partner_team t
            INNER JOIN om_market_partners p ON p.partner_id = t.partner_id
            WHERE t.id = ?
            LIMIT 1
        ");
        $stmt->execute([$memberId]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$member) {
            response(false, null, "Membro nao encontrado", 401);
        }

        // Verificar se ainda esta ativo
        if ((int)$member['status'] !== 1) {
            response(false, null, "Conta desativada", 403);
        }

        // Verificar se o parceiro ainda esta ativo
        if ((int)$member['partner_status'] !== 1) {
            response(false, null, "Estabelecimento inativo", 403);
        }

        response(true, [
            'valid' => true,
            'user' => [
                'id' => (int)$member['id'],
                'name' => $member['name'],
                'email' => $member['email'],
                'role' => $member['role']
            ],
            'partner_id' => (int)$member['partner_id'],
            'partner_name' => $member['partner_name'],
            'partner_logo' => $member['partner_logo'],
            'expires_at' => date('c', $payload['exp'])
        ], "Token valido!");
    }

    // Acao nao reconhecida
    response(false, null, "Acao invalida. Use 'login' ou 'validate'", 400);

} catch (Exception $e) {
    error_log("[team-auth] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
