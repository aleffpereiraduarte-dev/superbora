<?php
/**
 * POST /api/mercado/auth/google.php
 * Login/Registro via Google Sign-In
 *
 * Recebe o credential (ID token) do Google Identity Services,
 * verifica com Google, e faz login ou cria conta automaticamente.
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, "Metodo nao permitido", 405);
}

try {
    $input = getInput();
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $credential = trim($input['credential'] ?? '');
    if (empty($credential)) {
        response(false, null, "Token Google obrigatorio", 400);
    }

    // Verificar token com Google
    $googleUser = verifyGoogleToken($credential);
    if (!$googleUser) {
        response(false, null, "Token Google invalido ou expirado", 401);
    }

    $googleId = $googleUser['sub'];
    $email = $googleUser['email'];
    $name = $googleUser['name'] ?? '';
    $picture = $googleUser['picture'] ?? '';
    $emailVerified = ($googleUser['email_verified'] ?? 'false') === 'true' || $googleUser['email_verified'] === true;

    if (!$emailVerified) {
        response(false, null, "Email Google nao verificado", 400);
    }

    // Note: google_id column should be added via migration, not at runtime

    // Buscar por google_id
    $stmt = $db->prepare("
        SELECT customer_id, name, email, phone, cpf, foto, is_active, google_id
        FROM om_customers
        WHERE google_id = ?
    ");
    $stmt->execute([$googleId]);
    $customer = $stmt->fetch();

    // Se nao encontrou por google_id, tentar por email
    if (!$customer) {
        $stmt = $db->prepare("
            SELECT customer_id, name, email, phone, cpf, foto, is_active, google_id
            FROM om_customers
            WHERE email = ?
        ");
        $stmt->execute([$email]);
        $customer = $stmt->fetch();

        // Se achou por email, vincular google_id
        if ($customer) {
            $stmtLink = $db->prepare("UPDATE om_customers SET google_id = ? WHERE customer_id = ?");
            $stmtLink->execute([$googleId, $customer['customer_id']]);
        }
    }

    if ($customer) {
        // Login - conta existente
        if (!$customer['is_active']) {
            response(false, null, "Conta desativada. Entre em contato com o suporte.", 403);
        }

        // Atualizar foto do Google se nao tem foto
        if (empty($customer['foto']) && !empty($picture)) {
            $stmtFoto = $db->prepare("UPDATE om_customers SET foto = ? WHERE customer_id = ?");
            $stmtFoto->execute([$picture, $customer['customer_id']]);
            $customer['foto'] = $picture;
        }

        // Atualizar last_login
        $stmtLogin = $db->prepare("UPDATE om_customers SET last_login = NOW() WHERE customer_id = ?");
        $stmtLogin->execute([$customer['customer_id']]);

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
                "foto" => $customer['foto']
            ],
            "is_new" => false
        ], "Login realizado com sucesso!");

    } else {
        // Registro - conta nova via Google (sem senha, sem telefone/CPF obrigatorios)
        $stmt = $db->prepare("
            INSERT INTO om_customers (name, email, password_hash, google_id, foto, phone_verified, is_active, is_verified, created_at, updated_at)
            VALUES (?, ?, '', ?, ?, 0, 1, 1, NOW(), NOW())
            RETURNING customer_id
        ");
        $stmt->execute([$name, $email, $googleId, $picture ?: null]);
        $customerId = (int)$stmt->fetch()['customer_id'];

        $token = om_auth()->generateToken('customer', $customerId, [
            'name' => $name,
            'email' => $email
        ]);

        response(true, [
            "token" => $token,
            "customer" => [
                "id" => $customerId,
                "nome" => $name,
                "email" => $email,
                "telefone" => null,
                "cpf" => null,
                "foto" => $picture ?: null
            ],
            "is_new" => true
        ], "Conta criada com sucesso!", 201);
    }

} catch (Exception $e) {
    error_log("[API Auth Google] Erro: " . $e->getMessage());
    response(false, null, "Erro ao autenticar com Google", 500);
}

/**
 * Verifica ID token com Google OAuth2 API
 */
function verifyGoogleToken(string $idToken): ?array {
    $clientId = getenv('GOOGLE_CLIENT_ID');
    if (!$clientId || $clientId === 'CHANGE_ME') {
        error_log("[Auth Google] GOOGLE_CLIENT_ID nao configurado no .env");
        return null;
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || empty($response)) {
        error_log("[Auth Google] Token invalido (HTTP $httpCode)");
        return null;
    }

    $data = json_decode($response, true);
    if (!$data || empty($data['sub']) || empty($data['email'])) {
        error_log("[Auth Google] Resposta Google invalida");
        return null;
    }

    // Verificar issuer
    $allowedIssuers = ['accounts.google.com', 'https://accounts.google.com'];
    if (!in_array($data['iss'] ?? '', $allowedIssuers, true)) {
        error_log("[Auth Google] Issuer invalido: " . ($data['iss'] ?? ''));
        return null;
    }

    // Verificar audience (client_id)
    if (($data['aud'] ?? '') !== $clientId) {
        error_log("[Auth Google] Client ID nao confere: " . ($data['aud'] ?? ''));
        return null;
    }

    return $data;
}
