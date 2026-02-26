<?php
/**
 * 2FA Setup - OneMundo
 * Gera QR Code para configurar Google Authenticator
 *
 * POST /api/auth/2fa/setup.php
 * - customer_id: ID do cliente
 *
 * Retorna QR Code e secret para configurar app
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método não permitido']);
    exit;
}

try {
    require_once __DIR__ . '/../../../config.php';
    require_once __DIR__ . '/../../../database.php';

    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $customerId = (int)($input['customer_id'] ?? 0);

    if (!$customerId) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Não autenticado']);
        exit;
    }

    $pdo = getConnection();

    // Criar tabela se não existir
    $pdo->exec("CREATE TABLE IF NOT EXISTS om_2fa (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id INT NOT NULL UNIQUE,
        secret VARCHAR(32) NOT NULL,
        enabled TINYINT(1) DEFAULT 0,
        backup_codes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        verified_at TIMESTAMP NULL,
        INDEX idx_customer (customer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Buscar cliente
    $stmt = $pdo->prepare("SELECT email, firstname FROM " . DB_PREFIX . "customer WHERE customer_id = ?");
    $stmt->execute([$customerId]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$customer) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Cliente não encontrado']);
        exit;
    }

    // Gerar secret (Base32)
    $secret = generateBase32Secret(16);

    // Gerar códigos de backup
    $backupCodes = [];
    for ($i = 0; $i < 10; $i++) {
        $backupCodes[] = strtoupper(bin2hex(random_bytes(4)));
    }

    // Salvar no banco (sem ativar ainda)
    $stmt = $pdo->prepare("
        INSERT INTO om_2fa (customer_id, secret, backup_codes)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE secret = ?, backup_codes = ?, enabled = 0, verified_at = NULL
    ");
    $backupJson = json_encode($backupCodes);
    $stmt->execute([$customerId, $secret, $backupJson, $secret, $backupJson]);

    // Gerar URL do QR Code (otpauth)
    $issuer = 'OneMundo';
    $label = urlencode($issuer . ':' . $customer['email']);
    $otpauthUrl = "otpauth://totp/{$label}?secret={$secret}&issuer={$issuer}&algorithm=SHA1&digits=6&period=30";

    // Gerar QR Code como Data URL (usando API externa ou biblioteca)
    $qrCodeUrl = "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=" . urlencode($otpauthUrl);

    echo json_encode([
        'success' => true,
        'message' => 'Configure o Google Authenticator com o QR Code abaixo',
        'secret' => $secret,
        'qr_code_url' => $qrCodeUrl,
        'otpauth_url' => $otpauthUrl,
        'backup_codes' => $backupCodes,
        'instructions' => [
            '1. Baixe o Google Authenticator no seu celular',
            '2. Escaneie o QR Code ou digite o código manualmente',
            '3. Digite o código de 6 dígitos para verificar',
            '4. Guarde os códigos de backup em lugar seguro'
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("2FA setup error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro ao configurar 2FA']);
}

/**
 * Gera secret em Base32
 */
function generateBase32Secret($length = 16) {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    for ($i = 0; $i < $length; $i++) {
        $secret .= $chars[random_int(0, 31)];
    }
    return $secret;
}
