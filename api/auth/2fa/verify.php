<?php
/**
 * 2FA Verify - OneMundo
 * Verifica código TOTP e ativa 2FA
 *
 * POST /api/auth/2fa/verify.php
 * - customer_id: ID do cliente
 * - code: Código de 6 dígitos do app
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
    $code = preg_replace('/\D/', '', $input['code'] ?? '');
    $trustDevice = (bool)($input['trust_device'] ?? $input['remember'] ?? false);
    $deviceName = trim($input['device_name'] ?? '');

    if (!$customerId) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Não autenticado']);
        exit;
    }

    if (strlen($code) !== 6) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Código deve ter 6 dígitos']);
        exit;
    }

    $pdo = getConnection();

    // Buscar secret do cliente
    $stmt = $pdo->prepare("SELECT secret, enabled, backup_codes FROM om_2fa WHERE customer_id = ?");
    $stmt->execute([$customerId]);
    $twoFa = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$twoFa) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => '2FA não configurado. Configure primeiro.']);
        exit;
    }

    // Verificar código TOTP
    $validCode = verifyTOTP($twoFa['secret'], $code);

    // Se não for TOTP válido, verificar backup codes
    if (!$validCode) {
        $backupCodes = json_decode($twoFa['backup_codes'], true) ?? [];
        $codeIndex = array_search($code, $backupCodes);

        if ($codeIndex !== false) {
            // Remover código usado
            unset($backupCodes[$codeIndex]);
            $stmt = $pdo->prepare("UPDATE om_2fa SET backup_codes = ? WHERE customer_id = ?");
            $stmt->execute([json_encode(array_values($backupCodes)), $customerId]);
            $validCode = true;
        }
    }

    if (!$validCode) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Código inválido ou expirado',
            'valid' => false
        ]);
        exit;
    }

    // Se ainda não está ativado, ativar agora
    if (!$twoFa['enabled']) {
        $stmt = $pdo->prepare("UPDATE om_2fa SET enabled = 1, verified_at = NOW() WHERE customer_id = ?");
        $stmt->execute([$customerId]);
    }

    $trustedUntil = null;

    // Se pediu para confiar no dispositivo
    if ($trustDevice) {
        require_once __DIR__ . '/trusted-devices.php';

        // Adicionar dispositivo confiável
        $deviceHash = generateDeviceHash();
        $ip = getClientIP();
        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
        $trustedUntil = date('Y-m-d H:i:s', time() + (30 * 24 * 60 * 60));
        $finalDeviceName = $deviceName ?: getDeviceName();

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
            $customerId, $deviceHash, $finalDeviceName, $ip, $userAgent, $trustedUntil,
            $finalDeviceName, $ip, $trustedUntil
        ]);
    }

    echo json_encode([
        'success' => true,
        'valid' => true,
        'message' => $twoFa['enabled'] ? 'Código verificado com sucesso' : '2FA ativado com sucesso!',
        '2fa_enabled' => true,
        'device_trusted' => $trustDevice,
        'trusted_until' => $trustedUntil
    ]);

} catch (Exception $e) {
    error_log("2FA verify error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro ao verificar código']);
}

/**
 * Verifica código TOTP
 * Aceita código atual e 1 anterior/posterior (30s de tolerância)
 */
function verifyTOTP($secret, $code, $window = 1) {
    $timestamp = floor(time() / 30);

    for ($i = -$window; $i <= $window; $i++) {
        $calculatedCode = calculateTOTP($secret, $timestamp + $i);
        if (hash_equals($calculatedCode, $code)) {
            return true;
        }
    }

    return false;
}

/**
 * Calcula código TOTP
 */
function calculateTOTP($secret, $counter) {
    // Decodificar Base32
    $secretBytes = base32Decode($secret);

    // Counter em 8 bytes big-endian
    $counterBytes = pack('N*', 0, $counter);

    // HMAC-SHA1
    $hash = hash_hmac('sha1', $counterBytes, $secretBytes, true);

    // Dynamic truncation
    $offset = ord($hash[19]) & 0x0F;
    $code = (
        ((ord($hash[$offset]) & 0x7F) << 24) |
        ((ord($hash[$offset + 1]) & 0xFF) << 16) |
        ((ord($hash[$offset + 2]) & 0xFF) << 8) |
        (ord($hash[$offset + 3]) & 0xFF)
    ) % 1000000;

    return str_pad($code, 6, '0', STR_PAD_LEFT);
}

/**
 * Decodifica Base32
 */
function base32Decode($input) {
    $map = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $input = strtoupper($input);
    $output = '';
    $buffer = 0;
    $bitsLeft = 0;

    for ($i = 0; $i < strlen($input); $i++) {
        $val = strpos($map, $input[$i]);
        if ($val === false) continue;

        $buffer = ($buffer << 5) | $val;
        $bitsLeft += 5;

        if ($bitsLeft >= 8) {
            $bitsLeft -= 8;
            $output .= chr(($buffer >> $bitsLeft) & 0xFF);
        }
    }

    return $output;
}
