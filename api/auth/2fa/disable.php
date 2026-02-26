<?php
/**
 * 2FA Disable - OneMundo
 * Desativa autenticação de dois fatores
 *
 * POST /api/auth/2fa/disable.php
 * - customer_id: ID do cliente
 * - code: Código de 6 dígitos para confirmar
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
    require_once __DIR__ . '/verify.php';

    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $customerId = (int)($input['customer_id'] ?? 0);
    $code = preg_replace('/\D/', '', $input['code'] ?? '');

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

    // Buscar secret
    $stmt = $pdo->prepare("SELECT secret FROM om_2fa WHERE customer_id = ? AND enabled = 1");
    $stmt->execute([$customerId]);
    $twoFa = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$twoFa) {
        echo json_encode(['success' => true, 'message' => '2FA já está desativado']);
        exit;
    }

    // Verificar código
    if (!verifyTOTP($twoFa['secret'], $code)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Código inválido']);
        exit;
    }

    // Desativar
    $stmt = $pdo->prepare("DELETE FROM om_2fa WHERE customer_id = ?");
    $stmt->execute([$customerId]);

    echo json_encode([
        'success' => true,
        'message' => '2FA desativado com sucesso',
        '2fa_enabled' => false
    ]);

} catch (Exception $e) {
    error_log("2FA disable error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro ao desativar 2FA']);
}
