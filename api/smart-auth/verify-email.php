<?php
/**
 * Smart Auth - Verificar Email
 *
 * POST /api/smart-auth/verify-email.php
 * {
 *   "customer_id": 123,
 *   "code": "123456"
 * }
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método não permitido']);
    exit;
}

try {
    require_once __DIR__ . '/../../database.php';

    $input = json_decode(file_get_contents('php://input'), true);

    $customerId = (int)($input['customer_id'] ?? 0);
    $code = trim($input['code'] ?? '');

    if (!$customerId || !$code) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Dados incompletos']);
        exit;
    }

    $pdo = getConnection();

    // Verificar código
    $stmt = $pdo->prepare("
        SELECT id, code, expires_at, verified_at
        FROM om_verification_codes
        WHERE customer_id = ? AND type = 'email' AND code = ?
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$customerId, $code]);
    $verification = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$verification) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Código inválido']);
        exit;
    }

    if ($verification['verified_at']) {
        echo json_encode([
            'success' => true,
            'message' => 'Email já verificado',
            'already_verified' => true
        ]);
        exit;
    }

    if (strtotime($verification['expires_at']) < time()) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Código expirado. Solicite um novo.']);
        exit;
    }

    // Marcar como verificado
    $stmt = $pdo->prepare("UPDATE om_verification_codes SET verified_at = NOW() WHERE id = ?");
    $stmt->execute([$verification['id']]);

    // Verificar se telefone também está verificado
    $stmt = $pdo->prepare("
        SELECT verified_at FROM om_verification_codes
        WHERE customer_id = ? AND type = 'phone'
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$customerId]);
    $phoneVerification = $stmt->fetch(PDO::FETCH_ASSOC);

    $phoneVerified = !empty($phoneVerification['verified_at']);

    // Se ambos verificados, ativar conta
    if ($phoneVerified) {
        $stmt = $pdo->prepare("UPDATE oc_customer SET status = 1 WHERE customer_id = ?");
        $stmt->execute([$customerId]);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Email verificado com sucesso!',
        'email_verified' => true,
        'phone_verified' => $phoneVerified,
        'account_active' => $phoneVerified,
        'next_step' => $phoneVerified ? 'login' : 'verify_phone'
    ]);

} catch (Exception $e) {
    error_log("Verify Email Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno']);
}
