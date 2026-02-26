<?php
require_once __DIR__ . "/_debug_log.php";
/**
 * POST /api/auth/login-otp.php
 * Login via OTP - verifica código e loga o cliente pelo telefone
 *
 * Request: { "telefone": "33999652818", "codigo": "123456" }
 * Response: { success: true, data: { token, customer, verificado } }
 */
require_once dirname(__DIR__) . '/includes/cors.php';
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database.php';
require_once dirname(__DIR__, 2) . '/includes/env_loader.php';

function jsonResponse($success, $data = null, $message = "", $code = 200) {
    http_response_code($code);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode(["success" => $success, "message" => $message, "data" => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $input = json_decode(file_get_contents("php://input"), true) ?: $_POST;

    $telefone = preg_replace('/\D/', '', $input["telefone"] ?? "");
    $codigo = preg_replace('/\D/', '', $input["codigo"] ?? "");

    if (empty($telefone) || strlen($telefone) < 10 || strlen($telefone) > 11) {
        jsonResponse(false, null, "Telefone inválido", 400);
    }

    if (strlen($codigo) !== 6) {
        jsonResponse(false, null, "Código deve ter 6 dígitos", 400);
    }

    $db = getConnection();

    // 1. Verificar o código OTP
    $stmt = $db->prepare("
        SELECT id, codigo, tentativas, expires_at
        FROM om_phone_verification
        WHERE telefone = ? AND verificado = 0
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$telefone]);
    $verification = $stmt->fetch();

    if (!$verification) {
        jsonResponse(false, null, "Nenhum código pendente. Solicite um novo código.", 400);
    }

    // Verificar expiração
    if (strtotime($verification['expires_at']) < time()) {
        $stmt = $db->prepare("UPDATE om_phone_verification SET verificado = -1 WHERE id = ?");
        $stmt->execute([$verification['id']]);
        jsonResponse(false, null, "Código expirado. Solicite um novo código.", 400);
    }

    // Verificar tentativas (max 5)
    if ($verification['tentativas'] >= 5) {
        $stmt = $db->prepare("UPDATE om_phone_verification SET verificado = -1 WHERE id = ?");
        $stmt->execute([$verification['id']]);
        jsonResponse(false, null, "Muitas tentativas. Solicite um novo código.", 429);
    }

    // Incrementar tentativas
    $stmt = $db->prepare("UPDATE om_phone_verification SET tentativas = tentativas + 1 WHERE id = ?");
    $stmt->execute([$verification['id']]);

    // Verificar código
    if ($verification['codigo'] !== $codigo) {
        $remaining = 5 - ($verification['tentativas'] + 1);
        jsonResponse(false, [
            "verificado" => false,
            "tentativas_restantes" => max(0, $remaining)
        ], "Código incorreto. {$remaining} tentativa(s) restante(s).", 400);
    }

    // Código correto - marcar como verificado
    $stmt = $db->prepare("UPDATE om_phone_verification SET verificado = 1 WHERE id = ?");
    $stmt->execute([$verification['id']]);

    // 2. Buscar cliente por telefone
    $stmt = $db->prepare("
        SELECT customer_id, firstname, lastname, email, telephone, status
        FROM oc_customer
        WHERE telephone = ? OR telephone = ? OR telephone = ?
        LIMIT 1
    ");
    $tel55 = '55' . $telefone;
    $telPlus55 = '+55' . $telefone;
    $stmt->execute([$telefone, $tel55, $telPlus55]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$customer) {
        jsonResponse(false, [
            "verificado" => true,
            "needs_register" => true
        ], "Telefone verificado! Mas não encontramos uma conta com esse número. Crie sua conta.", 404);
    }

    if ($customer['status'] != 1) {
        jsonResponse(false, null, "Conta desativada. Entre em contato com o suporte.", 403);
    }

    // 3. Sincronizar/criar entrada em om_customers
    $omCustomerId = null;
    try {
        $stmtOm = $db->prepare("SELECT customer_id FROM om_customers WHERE email = ? LIMIT 1");
        $stmtOm->execute([strtolower($customer['email'])]);
        $omRow = $stmtOm->fetch(PDO::FETCH_ASSOC);

        if ($omRow) {
            $omCustomerId = (int)$omRow['customer_id'];
            $db->prepare("UPDATE om_customers SET last_login = NOW() WHERE customer_id = ?")
                ->execute([$omCustomerId]);
        } else {
            $fullName = trim($customer['firstname'] . ' ' . ($customer['lastname'] ?? ''));
            $phoneClean = preg_replace('/\D/', '', $customer['telephone'] ?? '');
            $stmtInsert = $db->prepare("
                INSERT INTO om_customers (name, email, password_hash, phone, is_active, is_verified, phone_verified, created_at, last_login)
                VALUES (?, ?, '', ?, 1, 1, 1, NOW(), NOW())
                RETURNING customer_id
            ");
            $stmtInsert->execute([$fullName, strtolower($customer['email']), $phoneClean]);
            $omCustomerId = (int)$stmtInsert->fetchColumn();
            error_log("[login-otp] Cliente oc#{$customer['customer_id']} sincronizado para om_customers #{$omCustomerId}");
        }
    } catch (Exception $e) {
        error_log("[login-otp] Aviso: falha ao sincronizar om_customers: " . $e->getMessage());
    }

    // 4. Gerar token via OmAuth (compatível com todo o sistema)
    $token = null;
    if ($omCustomerId) {
        try {
            // Carregar JWT_SECRET do .env mercado (OmAuth precisa antes de instanciar)
            if (empty($_ENV['JWT_SECRET'])) {
                $envPath = dirname(__DIR__, 2) . '/.env';
                if (file_exists($envPath)) {
                    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                        if (str_starts_with($line, '#') || strpos($line, '=') === false) continue;
                        [$k, $v] = explode('=', $line, 2);
                        if (trim($k) === 'JWT_SECRET') { $_ENV['JWT_SECRET'] = trim($v); break; }
                    }
                }
            }
            require_once dirname(__DIR__, 2) . '/includes/classes/OmAuth.php';
            $auth = OmAuth::getInstance();
            $auth->setDb($db);
            $token = $auth->generateToken('customer', $omCustomerId, [
                'email' => $customer['email'],
                'oc_id' => (int)$customer['customer_id'],
            ]);
        } catch (Exception $e) {
            error_log("[login-otp] Aviso: falha ao gerar token OmAuth: " . $e->getMessage());
        }
    }

    // Fallback: token compativel com getCustomerIdFromToken()
    if (!$token) {
        $jwtSecret = $_ENV['JWT_SECRET'] ?? '';
        if (empty($jwtSecret)) {
            $jwtSecret = hash('sha256', (defined('DB_PASS') ? DB_PASS : (defined('DB_PASSWORD') ? DB_PASSWORD : '')) . 'love1' . 'onemundo_fallback_2024');
        }
        $fallbackId = $omCustomerId ?: (int)$customer['customer_id'];
        $tp = base64_encode(json_encode([
            'user_id' => $fallbackId,
            'email' => $customer['email'],
            'exp' => time() + (7 * 24 * 60 * 60)
        ]));
        $token = $tp . '.' . hash_hmac('sha256', $tp, $jwtSecret);
    }

    // Atualizar último login (non-blocking)
    try {
        $stmt = $db->prepare("UPDATE oc_customer SET date_last_login = NOW() WHERE customer_id = ?");
        $stmt->execute([$customer['customer_id']]);
    } catch (Exception $e) {
        // Coluna pode não existir
    }

    $customerId = $omCustomerId ?: $customer['customer_id'];
    jsonResponse(true, [
        "token" => $token,
        "customer" => [
            "id" => $customerId,
            "firstname" => $customer['firstname'],
            "lastname" => $customer['lastname'],
            "email" => $customer['email'],
            "telephone" => $customer['telephone']
        ],
        "verificado" => true
    ], "Login realizado com sucesso!");

} catch (Exception $e) {
    error_log("[login-otp] " . $e->getMessage() . " | " . $e->getFile() . ":" . $e->getLine());
    jsonResponse(false, null, "Erro interno. Tente novamente.", 500);
}
