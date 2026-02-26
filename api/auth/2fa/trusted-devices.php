<?php
/**
 * Trusted Devices - OneMundo
 * Gerencia dispositivos confiáveis para pular 2FA
 *
 * GET  - Lista dispositivos confiáveis do cliente
 * POST - Adiciona dispositivo como confiável
 * DELETE - Remove dispositivo confiável
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

// Tempo de confiança: 30 dias
define('TRUST_DURATION', 30 * 24 * 60 * 60);

try {
    require_once __DIR__ . '/../../../config.php';
    require_once __DIR__ . '/../../../database.php';

    $pdo = getConnection();

    // Criar tabela se não existir
    $pdo->exec("CREATE TABLE IF NOT EXISTS om_2fa_trusted_devices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id INT NOT NULL,
        device_hash VARCHAR(64) NOT NULL,
        device_name VARCHAR(100),
        ip_address VARCHAR(45),
        user_agent VARCHAR(255),
        trusted_until TIMESTAMP NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        last_used TIMESTAMP NULL,
        UNIQUE KEY unique_device (customer_id, device_hash),
        INDEX idx_customer (customer_id),
        INDEX idx_expires (trusted_until)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $customerId = (int)($input['customer_id'] ?? $_GET['customer_id'] ?? 0);

    if (!$customerId) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Não autenticado']);
        exit;
    }

    // ===== GET - Listar dispositivos =====
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $pdo->prepare("
            SELECT id, device_name, ip_address,
                   DATE_FORMAT(created_at, '%d/%m/%Y %H:%i') as added_at,
                   DATE_FORMAT(last_used, '%d/%m/%Y %H:%i') as last_used,
                   DATE_FORMAT(trusted_until, '%d/%m/%Y') as expires_at,
                   CASE WHEN device_hash = ? THEN 1 ELSE 0 END as is_current
            FROM om_2fa_trusted_devices
            WHERE customer_id = ? AND trusted_until > NOW()
            ORDER BY last_used DESC
        ");

        $currentDeviceHash = generateDeviceHash();
        $stmt->execute([$currentDeviceHash, $customerId]);
        $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'count' => count($devices),
            'devices' => $devices
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ===== POST - Adicionar dispositivo confiável =====
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $deviceName = trim($input['device_name'] ?? getDeviceName());
        $deviceHash = generateDeviceHash();
        $ip = getClientIP();
        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
        $trustedUntil = date('Y-m-d H:i:s', time() + TRUST_DURATION);

        // Limitar a 5 dispositivos por cliente
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM om_2fa_trusted_devices WHERE customer_id = ? AND trusted_until > NOW()");
        $stmt->execute([$customerId]);

        if ($stmt->fetchColumn() >= 5) {
            // Remover o mais antigo
            $pdo->prepare("
                DELETE FROM om_2fa_trusted_devices
                WHERE customer_id = ?
                ORDER BY last_used ASC
                LIMIT 1
            ")->execute([$customerId]);
        }

        // Inserir ou atualizar
        $stmt = $pdo->prepare("
            INSERT INTO om_2fa_trusted_devices
            (customer_id, device_hash, device_name, ip_address, user_agent, trusted_until)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                device_name = ?,
                ip_address = ?,
                trusted_until = ?,
                last_used = NOW()
        ");

        $stmt->execute([
            $customerId, $deviceHash, $deviceName, $ip, $userAgent, $trustedUntil,
            $deviceName, $ip, $trustedUntil
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'Dispositivo adicionado como confiável',
            'trusted_until' => $trustedUntil,
            'days' => 30
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ===== DELETE - Remover dispositivo =====
    if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        $deviceId = (int)($input['device_id'] ?? $_GET['device_id'] ?? 0);
        $removeAll = (bool)($input['all'] ?? false);

        if ($removeAll) {
            $stmt = $pdo->prepare("DELETE FROM om_2fa_trusted_devices WHERE customer_id = ?");
            $stmt->execute([$customerId]);
            $count = $stmt->rowCount();

            echo json_encode([
                'success' => true,
                'message' => "Todos os $count dispositivos foram removidos"
            ]);
        } elseif ($deviceId) {
            $stmt = $pdo->prepare("DELETE FROM om_2fa_trusted_devices WHERE id = ? AND customer_id = ?");
            $stmt->execute([$deviceId, $customerId]);

            echo json_encode([
                'success' => true,
                'removed' => $stmt->rowCount() > 0
            ]);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'device_id ou all=true é obrigatório']);
        }
        exit;
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método não permitido']);

} catch (Exception $e) {
    error_log("Trusted devices error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno']);
}

/**
 * Gera hash único do dispositivo
 */
function generateDeviceHash() {
    $components = [
        $_SERVER['HTTP_USER_AGENT'] ?? '',
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
        $_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''
    ];

    // Adicionar cookie de fingerprint se existir
    if (isset($_COOKIE['device_fp'])) {
        $components[] = $_COOKIE['device_fp'];
    }

    return hash('sha256', implode('|', $components));
}

/**
 * Detecta nome do dispositivo baseado no User Agent
 */
function getDeviceName() {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

    // Detectar sistema operacional
    $os = 'Desconhecido';
    if (preg_match('/Windows NT 10/i', $ua)) $os = 'Windows 10';
    elseif (preg_match('/Windows NT 6.3/i', $ua)) $os = 'Windows 8.1';
    elseif (preg_match('/Windows/i', $ua)) $os = 'Windows';
    elseif (preg_match('/Macintosh|Mac OS X/i', $ua)) $os = 'macOS';
    elseif (preg_match('/Linux/i', $ua)) $os = 'Linux';
    elseif (preg_match('/iPhone/i', $ua)) $os = 'iPhone';
    elseif (preg_match('/iPad/i', $ua)) $os = 'iPad';
    elseif (preg_match('/Android/i', $ua)) $os = 'Android';

    // Detectar navegador
    $browser = 'Navegador';
    if (preg_match('/Chrome\/[\d.]+/i', $ua)) $browser = 'Chrome';
    elseif (preg_match('/Firefox\/[\d.]+/i', $ua)) $browser = 'Firefox';
    elseif (preg_match('/Safari\/[\d.]+/i', $ua) && !preg_match('/Chrome/i', $ua)) $browser = 'Safari';
    elseif (preg_match('/Edge\/[\d.]+/i', $ua)) $browser = 'Edge';

    return "$browser em $os";
}

/**
 * Obtém IP real do cliente
 */
function getClientIP() {
    $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ip = explode(',', $_SERVER[$header])[0];
            if (filter_var(trim($ip), FILTER_VALIDATE_IP)) {
                return trim($ip);
            }
        }
    }
    return '127.0.0.1';
}
