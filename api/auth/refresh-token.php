<?php
/**
 * POST /api/auth/refresh-token.php
 * Renova token de autenticação do cliente
 * Valida o token atual via OmAuth e gera um novo
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    require_once __DIR__ . '/../../config.php';
    require_once __DIR__ . '/../../database.php';

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

    $pdo = getConnection();
    $auth = OmAuth::getInstance();
    $auth->setDb($pdo);

    // Obter token do header ou body
    $token = $auth->getTokenFromRequest();
    if (!$token) {
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $token = $input['token'] ?? '';
    }

    if (empty($token)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Token não fornecido']);
        exit;
    }

    // Validar token atual
    $payload = $auth->validateToken($token);
    if (!$payload) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Token inválido ou expirado']);
        exit;
    }

    // Gerar novo token mantendo os mesmos dados do usuário
    $newToken = $auth->generateToken(
        $payload['type'],
        $payload['uid'],
        $payload['data'] ?? []
    );

    // Revogar token antigo
    if (!empty($payload['jti'])) {
        $auth->revokeToken($payload['jti']);
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'token' => $newToken,
        'expires_in' => 86400 * 7,
    ]);

} catch (Exception $e) {
    error_log("Erro refresh-token: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro ao renovar token']);
}
