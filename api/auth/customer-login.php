<?php
require_once __DIR__ . "/_debug_log.php";
/**
 * POST /api/auth/customer-login.php
 * Login de clientes do e-commerce OneMundo
 * Retorna JSON com status adequado para credenciais inválidas
 * Inclui rate limiting para prevenir ataques de força bruta
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Rate limiting - 5 tentativas por minuto por IP
require_once __DIR__ . '/../rate-limit/RateLimiter.php';
RateLimiter::check(5, 60);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método não permitido. Use POST.']);
    exit;
}

try {
    require_once __DIR__ . '/../../config.php';
    require_once __DIR__ . '/../../database.php';

    // Obter dados do request
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }

    $email = trim($input['email'] ?? '');
    $password = $input['password'] ?? $input['senha'] ?? '';

    // Validação básica
    if (empty($email)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Email é obrigatório']);
        exit;
    }

    if (empty($password)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Senha é obrigatória']);
        exit;
    }

    // Validar formato do email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) && !preg_match('/^\d{10,11}$/', preg_replace('/\D/', '', $email))) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Formato de email inválido']);
        exit;
    }

    $pdo = getConnection();

    // Buscar cliente
    $stmt = $pdo->prepare("
        SELECT
            c.customer_id,
            c.firstname,
            c.lastname,
            c.email,
            c.telephone,
            c.password,
            c.salt,
            c.status
        FROM oc_customer c
        WHERE (LOWER(c.email) = LOWER(?) OR c.telephone = ?)
    ");

    $phone = preg_replace('/\D/', '', $email);
    // Only search by phone if it looks like a real phone number (10+ digits)
    if (strlen($phone) >= 10) {
        $stmt->execute([$email, $phone]);
    } else {
        $stmtEmailOnly = $pdo->prepare("
            SELECT c.customer_id, c.firstname, c.lastname, c.email, c.telephone, c.password, c.salt, c.status
            FROM oc_customer c
            WHERE LOWER(c.email) = LOWER(?)
        ");
        $stmtEmailOnly->execute([$email]);
    }
    $customer = (strlen($phone) >= 10) ? $stmt->fetch(PDO::FETCH_ASSOC) : $stmtEmailOnly->fetch(PDO::FETCH_ASSOC);

    // Cliente não encontrado
    if (!$customer) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Email ou senha incorretos',
            'logged' => false
        ]);
        exit;
    }

    // Conta inativa
    if ($customer['status'] != 1) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Conta desativada. Entre em contato com o suporte.',
            'logged' => false
        ]);
        exit;
    }

    // Verificar senha
    $validPassword = false;
    $needsPasswordUpgrade = false;

    // Método 1: password_hash (moderno - BCRYPT)
    if (password_verify($password, $customer['password'])) {
        $validPassword = true;
    }
    // Método 2: SHA1 com salt (OpenCart antigo) - MIGRAR para BCRYPT
    elseif (!empty($customer['salt']) && sha1($customer['salt'] . sha1($customer['salt'] . sha1($password))) === $customer['password']) {
        $validPassword = true;
        $needsPasswordUpgrade = true; // Marcar para atualizar senha
    }
    // Método 3: SHA1 sem salt - MIGRAR para BCRYPT
    elseif (sha1($password) === $customer['password']) {
        $validPassword = true;
        $needsPasswordUpgrade = true; // Marcar para atualizar senha
    }

    // Senha incorreta
    if (!$validPassword) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Email ou senha incorretos',
            'logged' => false
        ]);
        exit;
    }

    // Login bem sucedido

    // MIGRAR SENHA SHA1 PARA BCRYPT (segurança moderna)
    if ($needsPasswordUpgrade) {
        $newPasswordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $stmt = $pdo->prepare("UPDATE oc_customer SET password = ?, salt = '' WHERE customer_id = ?");
        $stmt->execute([$newPasswordHash, $customer['customer_id']]);
        error_log("Senha do cliente #{$customer['customer_id']} migrada para BCRYPT");
    }

    // Sincronizar/criar entrada em om_customers (sistema novo)
    $omCustomerId = null;
    try {
        $stmtOm = $pdo->prepare("SELECT customer_id FROM om_customers WHERE email = ? LIMIT 1");
        $stmtOm->execute([strtolower($customer['email'])]);
        $omRow = $stmtOm->fetch(PDO::FETCH_ASSOC);

        if ($omRow) {
            $omCustomerId = (int)$omRow['customer_id'];
            // Atualizar last_login
            $pdo->prepare("UPDATE om_customers SET last_login = NOW() WHERE customer_id = ?")
                ->execute([$omCustomerId]);
        } else {
            // Criar entrada em om_customers a partir de oc_customer
            $fullName = trim($customer['firstname'] . ' ' . ($customer['lastname'] ?? ''));
            $bcryptHash = $needsPasswordUpgrade
                ? ($newPasswordHash ?? password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]))
                : $customer['password'];
            $phoneClean = preg_replace('/\D/', '', $customer['telephone'] ?? '');

            $stmtInsert = $pdo->prepare("
                INSERT INTO om_customers (name, email, password_hash, phone, is_active, is_verified, created_at, last_login)
                VALUES (?, ?, ?, ?, 1, 1, NOW(), NOW())
                RETURNING customer_id
            ");
            $stmtInsert->execute([$fullName, strtolower($customer['email']), $bcryptHash, $phoneClean]);
            $omCustomerId = (int)$stmtInsert->fetchColumn();
            error_log("Cliente #{$customer['customer_id']} (oc) sincronizado para om_customers #{$omCustomerId}");
        }
    } catch (Exception $e) {
        error_log("Aviso: falha ao sincronizar om_customers: " . $e->getMessage());
    }

    // Gerar token via OmAuth (compatível com todo o sistema)
    $token = null;
    if ($omCustomerId) {
        try {
            // Carregar JWT_SECRET do .env mercado (OmAuth precisa antes de instanciar)
            if (empty($_ENV['JWT_SECRET'])) {
                $envPath = __DIR__ . '/../../.env';
                if (file_exists($envPath)) {
                    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                        if (str_starts_with($line, '#') || strpos($line, '=') === false) continue;
                        [$k, $v] = explode('=', $line, 2);
                        if (trim($k) === 'JWT_SECRET') { $_ENV['JWT_SECRET'] = trim($v); break; }
                    }
                }
            }
            require_once __DIR__ . '/../../includes/classes/OmAuth.php';
            $auth = OmAuth::getInstance();
            $auth->setDb($pdo);
            $token = $auth->generateToken('customer', $omCustomerId, [
                'email' => $customer['email'],
                'oc_id' => (int)$customer['customer_id'],
            ]);
        } catch (Exception $e) {
            error_log("Aviso: falha ao gerar token OmAuth: " . $e->getMessage());
        }
    }

    // Fallback: token compativel com getCustomerIdFromToken()
    if (!$token) {
        $jwtSecret = $_ENV['JWT_SECRET'] ?? '';
        if (empty($jwtSecret)) {
            $jwtSecret = hash('sha256', DB_PASS . DB_NAME . 'onemundo_fallback_2024');
        }
        $fallbackId = $omCustomerId ?: (int)$customer['customer_id'];
        $tokenPayload = [
            'user_id' => $fallbackId,
            'email' => $customer['email'],
            'exp' => time() + (7 * 24 * 60 * 60)
        ];
        $payloadB64 = base64_encode(json_encode($tokenPayload));
        $token = $payloadB64 . '.' . hash_hmac('sha256', $payloadB64, $jwtSecret);
    }

    // Atualizar último login (non-blocking)
    try {
        $stmt = $pdo->prepare("UPDATE oc_customer SET date_last_login = NOW() WHERE customer_id = ?");
        $stmt->execute([$customer['customer_id']]);
    } catch (Exception $e) {
        // Coluna pode não existir - ignorar
    }

    // Resposta de sucesso
    $customerId = $omCustomerId ?: $customer['customer_id'];
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'logged' => true,
        'message' => 'Login realizado com sucesso',
        'customer' => [
            'id' => $customerId,
            'firstname' => $customer['firstname'],
            'lastname' => $customer['lastname'],
            'email' => $customer['email'],
            'telephone' => $customer['telephone']
        ],
        'token' => $token
    ]);

} catch (PDOException $e) {
    error_log("Erro login customer: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erro interno do servidor',
        'logged' => false
    ]);
} catch (Exception $e) {
    error_log("Erro login customer: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erro ao processar login',
        'logged' => false
    ]);
}
