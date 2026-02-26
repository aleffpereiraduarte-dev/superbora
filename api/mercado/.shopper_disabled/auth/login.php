<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * POST /api/mercado/shopper/auth/login.php
 * Login do shopper (entregador)
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Body: {
 *   "email": "shopper@email.com",  // ou "telefone"
 *   "senha": "minhasenha"
 * }
 *
 * Retorna token JWT-like para autenticacao nas outras APIs
 * Shopper precisa ter cadastro APROVADO pelo RH para usar as APIs
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

    $email = trim($input["email"] ?? "");
    $telefone = trim($input["telefone"] ?? "");
    $senha = $input["senha"] ?? "";

    if ((!$email && !$telefone) || !$senha) {
        response(false, null, "Email/telefone e senha obrigatorios", 400);
    }

    // Rate limiting: 10 attempts per 15 minutes per IP, 15 per email/phone
    $clientIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'];
    if (strpos($clientIp, ',') !== false) {
        $clientIp = trim(explode(',', $clientIp)[0]);
    }

    try {
        $db->exec("CREATE TABLE IF NOT EXISTS om_login_attempts (
            id SERIAL PRIMARY KEY,
            ip_address VARCHAR(45) NOT NULL,
            email VARCHAR(255) DEFAULT NULL,
            user_type VARCHAR(30) DEFAULT 'shopper',
            attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_om_login_attempts_ip_time ON om_login_attempts (ip_address, attempted_at)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_om_login_attempts_email_time ON om_login_attempts (email, attempted_at)");

        // Per-IP rate limiting
        $stmtIp = $db->prepare("
            SELECT COUNT(*) FROM om_login_attempts
            WHERE ip_address = ? AND user_type = 'shopper'
            AND attempted_at > NOW() - INTERVAL '15 minutes'
        ");
        $stmtIp->execute([$clientIp]);
        if ((int)$stmtIp->fetchColumn() >= 10) {
            error_log("[shopper/auth/login] Rate limit IP excedido: $clientIp");
            response(false, null, "Muitas tentativas de login. Aguarde 15 minutos.", 429);
        }

        // Per-identifier rate limiting
        $loginIdentifier = $email ?: $telefone;
        $stmtEmail = $db->prepare("
            SELECT COUNT(*) FROM om_login_attempts
            WHERE email = ? AND user_type = 'shopper'
            AND attempted_at > NOW() - INTERVAL '15 minutes'
        ");
        $stmtEmail->execute([$loginIdentifier]);
        if ((int)$stmtEmail->fetchColumn() >= 15) {
            error_log("[shopper/auth/login] Rate limit per-identifier excedido: $loginIdentifier");
            response(false, null, "Muitas tentativas com este email/telefone. Aguarde 15 minutos.", 429);
        }

        // Log attempt
        $db->prepare("INSERT INTO om_login_attempts (ip_address, email, user_type, attempted_at) VALUES (?, ?, 'shopper', NOW())")
            ->execute([$clientIp, $loginIdentifier]);
    } catch (Exception $e) {
        error_log("[shopper/auth/login] Rate limit check error: " . $e->getMessage());
    }

    // Buscar shopper
    // Use whitelist for column name to prevent SQL injection via column interpolation
    $campo = $email ? "email" : "phone";
    $allowedColumns = ['email', 'phone'];
    if (!in_array($campo, $allowedColumns, true)) {
        response(false, null, "Parametro de login invalido", 400);
    }
    $valor = $email ?: $telefone;

    // Prepared statement com coluna whitelisted
    $stmt = $db->prepare("SELECT * FROM om_market_shoppers WHERE {$campo} = ?");
    $stmt->execute([$valor]);
    $shopper = $stmt->fetch();

    // SECURITY: Constant-time password verification to prevent user enumeration
    // Always run password_verify regardless of whether user exists
    $senhaHash = $shopper ? ($shopper["password"] ?? $shopper["password_hash"] ?? "") : '';
    $dummyHash = '$2y$10$dummyHashForTimingConsistency....................u';
    $passwordValid = password_verify($senha, $senhaHash ?: $dummyHash);

    if (!$shopper || !$passwordValid) {
        om_audit()->logLogin('shopper', (int)($shopper["shopper_id"] ?? 0), false);
        response(false, null, "Credenciais invalidas", 401);
    }

    // Verificar status do cadastro
    $status = (int)$shopper["status"];
    $statusInfo = om_auth()->getShopperStatus((int)$shopper["shopper_id"]);

    // Status: 0 = pendente RH, 1 = aprovado, 2 = rejeitado, 3 = suspenso
    if ($status === 0) {
        // Permite login mas nao pode aceitar pedidos
        // Gerar token com flag indicando pendencia
        $token = om_auth()->generateToken(
            OmAuth::USER_TYPE_SHOPPER,
            (int)$shopper["shopper_id"],
            ['approved' => false, 'status' => 'pending']
        );

        // Atualizar ultimo login
        $stmt = $db->prepare("UPDATE om_market_shoppers SET last_login = NOW() WHERE shopper_id = ?");
        $stmt->execute([$shopper["shopper_id"]]);

        om_audit()->logLogin('shopper', (int)$shopper["shopper_id"], true);

        response(true, [
            "shopper_id" => (int)$shopper["shopper_id"],
            "nome" => $shopper["name"],
            "email" => $shopper["email"],
            "telefone" => $shopper["phone"],
            "foto" => $shopper["photo"],
            "saldo" => 0,
            "token" => $token,
            "status" => "pending",
            "mensagem_status" => "Seu cadastro esta sendo analisado pelo RH. Voce recebera uma notificacao quando for aprovado."
        ], "Login realizado! Aguardando aprovacao do RH.");
    }

    if ($status === 2) {
        response(false, [
            "status" => "rejected",
            "motivo" => $shopper["motivo_rejeicao"] ?? "Nao informado"
        ], "Cadastro rejeitado pelo RH. Motivo: " . ($shopper["motivo_rejeicao"] ?? "Nao informado"), 403);
    }

    if ($status === 3) {
        response(false, [
            "status" => "suspended"
        ], "Sua conta esta temporariamente suspensa. Entre em contato com o suporte.", 403);
    }

    // Status 1 = Aprovado - Gerar token completo
    $token = om_auth()->generateToken(
        OmAuth::USER_TYPE_SHOPPER,
        (int)$shopper["shopper_id"],
        ['approved' => true, 'name' => $shopper["name"]]
    );

    // Atualizar ultimo login
    $stmt = $db->prepare("UPDATE om_market_shoppers SET last_login = NOW() WHERE shopper_id = ?");
    $stmt->execute([$shopper["shopper_id"]]);

    // Buscar saldo
    $stmt = $db->prepare("SELECT saldo_disponivel FROM om_shopper_saldo WHERE shopper_id = ?");
    $stmt->execute([$shopper["shopper_id"]]);
    $saldo = floatval($stmt->fetchColumn() ?: 0);

    // Clear rate limit entries on successful login
    try {
        $db->prepare("DELETE FROM om_login_attempts WHERE ip_address = ? AND user_type = 'shopper'")->execute([$clientIp]);
    } catch (Exception $e) {
        // Non-critical
    }

    // Log de login bem-sucedido
    om_audit()->logLogin('shopper', (int)$shopper["shopper_id"], true);

    response(true, [
        "shopper_id" => (int)$shopper["shopper_id"],
        "nome" => $shopper["name"],
        "email" => $shopper["email"],
        "telefone" => $shopper["phone"],
        "foto" => $shopper["photo"],
        "saldo" => $saldo,
        "token" => $token,
        "status" => "approved",
        "data_aprovacao" => $shopper["data_aprovacao"] ?? null
    ], "Login realizado com sucesso!");

} catch (Exception $e) {
    error_log("[shopper/auth/login] Erro: " . $e->getMessage());
    response(false, null, "Erro ao realizar login. Tente novamente.", 500);
}
