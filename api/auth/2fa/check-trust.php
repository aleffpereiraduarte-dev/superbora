<?php
/**
 * Check Trust - OneMundo
 * Verifica se dispositivo atual é confiável (pula 2FA)
 *
 * GET /api/auth/2fa/check-trust.php?customer_id=123
 *
 * Retorna se precisa de 2FA ou não
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

try {
    require_once __DIR__ . '/../../../config.php';
    require_once __DIR__ . '/../../../database.php';

    $customerId = (int)($_GET['customer_id'] ?? 0);

    if (!$customerId) {
        echo json_encode([
            'success' => true,
            'needs_2fa' => false,
            '2fa_enabled' => false
        ]);
        exit;
    }

    $pdo = getConnection();

    // Verificar se cliente tem 2FA ativado
    $stmt = $pdo->prepare("SELECT enabled FROM om_2fa WHERE customer_id = ? AND enabled = 1");
    $stmt->execute([$customerId]);

    if (!$stmt->fetch()) {
        // 2FA não está ativado
        echo json_encode([
            'success' => true,
            'needs_2fa' => false,
            '2fa_enabled' => false
        ]);
        exit;
    }

    // 2FA está ativado, verificar se dispositivo é confiável
    $deviceHash = generateDeviceHash();

    $stmt = $pdo->prepare("
        SELECT id, device_name, trusted_until
        FROM om_2fa_trusted_devices
        WHERE customer_id = ? AND device_hash = ? AND trusted_until > NOW()
    ");
    $stmt->execute([$customerId, $deviceHash]);
    $trustedDevice = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($trustedDevice) {
        // Dispositivo confiável - atualizar last_used
        $pdo->prepare("UPDATE om_2fa_trusted_devices SET last_used = NOW() WHERE id = ?")->execute([$trustedDevice['id']]);

        echo json_encode([
            'success' => true,
            'needs_2fa' => false,
            '2fa_enabled' => true,
            'trusted_device' => true,
            'device_name' => $trustedDevice['device_name'],
            'trusted_until' => $trustedDevice['trusted_until']
        ]);
    } else {
        // Precisa de 2FA
        echo json_encode([
            'success' => true,
            'needs_2fa' => true,
            '2fa_enabled' => true,
            'trusted_device' => false
        ]);
    }

} catch (Exception $e) {
    error_log("Check trust error: " . $e->getMessage());
    echo json_encode([
        'success' => true,
        'needs_2fa' => false,
        '2fa_enabled' => false
    ]);
}

function generateDeviceHash() {
    $components = [
        $_SERVER['HTTP_USER_AGENT'] ?? '',
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
        $_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''
    ];
    if (isset($_COOKIE['device_fp'])) {
        $components[] = $_COOKIE['device_fp'];
    }
    return hash('sha256', implode('|', $components));
}
