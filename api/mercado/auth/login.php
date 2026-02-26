<?php
require_once __DIR__ . "/_debug_log.php";
/**
 * POST /api/mercado/auth/login.php
 * Login do cliente - email + senha
 */
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../helpers/rate-limit.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, "Metodo nao permitido", 405);
}

try {
    $input = getInput();
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    // Rate limiting: 10 tentativas por 15 minutos por IP
    $ip = getRateLimitIP();
    if (!checkRateLimit("customer_login_{$ip}", 10, 15)) {
        response(false, null, "Muitas tentativas de login. Tente novamente em 15 minutos.", 429);
    }

    $email = filter_var(trim($input['email'] ?? ''), FILTER_SANITIZE_EMAIL);
    $password = $input['senha'] ?? $input['password'] ?? '';

    if (empty($email) || empty($password)) {
        response(false, null, "Email e senha obrigatorios", 400);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        response(false, null, "Email invalido", 400);
    }

    // Buscar cliente
    $stmt = $db->prepare("
        SELECT customer_id, name, email, phone, cpf, password_hash, foto, is_active
        FROM om_customers
        WHERE email = ?
    ");
    $stmt->execute([$email]);
    $customer = $stmt->fetch();

    if (!$customer) {
        response(false, null, "Email ou senha incorretos", 401);
    }

    if (!$customer['is_active']) {
        response(false, null, "Conta desativada. Entre em contato com o suporte.", 403);
    }

    // Verificar senha - suporta multiplos formatos
    $passwordValid = false;
    $storedHash = $customer['password_hash'] ?? '';

    if (empty($storedHash)) {
        response(false, null, "Esta conta usa login com Google. Use o botao 'Continuar com Google'.", 401);
    }

    // 1. password_verify (Argon2, bcrypt)
    if (password_verify($password, $storedHash)) {
        $passwordValid = true;
    }
    // 2. MD5 legacy - use hash_equals for timing-safe comparison
    elseif (hash_equals(md5($password), $storedHash)) {
        $passwordValid = true;
        error_log("[SECURITY WARNING] Customer {$customer['customer_id']} using MD5 password hash - upgrading");
    }
    // 3. SHA1 legacy - use hash_equals for timing-safe comparison
    elseif (hash_equals(sha1($password), $storedHash)) {
        $passwordValid = true;
        error_log("[SECURITY WARNING] Customer {$customer['customer_id']} using SHA1 password hash - upgrading");
    }

    if (!$passwordValid) {
        response(false, null, "Email ou senha incorretos", 401);
    }

    // Se senha em formato antigo, atualizar para Argon2
    if (!password_get_info($storedHash)['algo']) {
        $newHash = om_auth()->hashPassword($password);
        $stmtUpdate = $db->prepare("UPDATE om_customers SET password_hash = ? WHERE customer_id = ?");
        $stmtUpdate->execute([$newHash, $customer['customer_id']]);
    }

    // Atualizar last_login
    $stmtLogin = $db->prepare("UPDATE om_customers SET last_login = NOW() WHERE customer_id = ?");
    $stmtLogin->execute([$customer['customer_id']]);

    // Gerar token
    $token = om_auth()->generateToken('customer', (int)$customer['customer_id'], [
        'name' => $customer['name'],
        'email' => $customer['email']
    ]);

    response(true, [
        "token" => $token,
        "customer" => [
            "id" => (int)$customer['customer_id'],
            "nome" => $customer['name'],
            "email" => $customer['email'],
            "telefone" => $customer['phone'],
            "cpf" => $customer['cpf'],
            "foto" => $customer['foto'],
            "avatar" => $customer['foto']
        ]
    ], "Login realizado com sucesso!");

} catch (Exception $e) {
    error_log("[API Auth Login] Erro: " . $e->getMessage());
    response(false, null, "Erro ao fazer login", 500);
}
