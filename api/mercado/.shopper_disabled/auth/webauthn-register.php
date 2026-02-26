<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../../../includes/classes/OmAuth.php';

header('Content-Type: application/json');
setCorsHeaders();
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { response(false, null, 'Method not allowed', 405); }

$auth = OmAuth::getInstance();
$auth->setDb(getDB());
$token = $auth->getTokenFromRequest();
if (!$token) response(false, null, 'Token obrigatorio', 401);
$decoded = $auth->validateToken($token);
if (!$decoded || $decoded['type'] !== 'shopper') response(false, null, 'Nao autorizado', 401);

$input = getInput();
$shopperId = $decoded['uid'];

// WebAuthn registration - store credential
if (!empty($input['credential_id']) && !empty($input['public_key'])) {
    $db = getDB();
    $db->exec("CREATE TABLE IF NOT EXISTS om_webauthn_credentials (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_type VARCHAR(20) NOT NULL,
        user_id INT NOT NULL,
        credential_id VARCHAR(500) NOT NULL,
        public_key TEXT NOT NULL,
        sign_count INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_cred (credential_id(255))
    )");

    $stmt = $db->prepare("INSERT INTO om_webauthn_credentials (user_type, user_id, credential_id, public_key) VALUES ('shopper', ?, ?, ?)");
    $stmt->execute([$shopperId, $input['credential_id'], $input['public_key']]);
    response(true, ['registered' => true], 'Biometria registrada');
}

// Generate challenge for registration
$challenge = base64_encode(random_bytes(32));
$db = getDB();
$stmt = $db->prepare("SELECT shopper_id, name, email FROM om_market_shoppers WHERE shopper_id = ?");
$stmt->execute([$shopperId]);
$shopper = $stmt->fetch(PDO::FETCH_ASSOC);

response(true, [
    'challenge' => $challenge,
    'rp' => ['name' => 'SuperBora Shopper', 'id' => $_SERVER['HTTP_HOST'] ?? 'localhost'],
    'user' => [
        'id' => base64_encode($shopperId),
        'name' => $shopper['email'] ?? '',
        'displayName' => $shopper['name'] ?? ''
    ],
    'pubKeyCredParams' => [
        ['type' => 'public-key', 'alg' => -7],
        ['type' => 'public-key', 'alg' => -257]
    ],
    'authenticatorSelection' => [
        'authenticatorAttachment' => 'platform',
        'userVerification' => 'preferred'
    ]
]);
