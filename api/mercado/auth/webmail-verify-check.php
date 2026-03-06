<?php
/**
 * POST /api/mercado/auth/webmail-verify-check.php
 * Valida OTP e retorna JWT assinado para o mail server
 * Body: { "phone": "+5511999999999", "code": "123456" }
 *
 * Retorna: { success: true, token: "jwt.signed.token" }
 * O token e validado pelo mail server durante o signup usando HMAC-SHA256
 */
require_once __DIR__ . "/../config/database.php";

// CORS
header('Access-Control-Allow-Origin: https://mail.onemundo.com.br');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Metodo nao permitido']);
    http_response_code(405);
    exit;
}

try {
    $db = getDB();
    $input = getInput();

    $phone = preg_replace('/\D/', '', $input['phone'] ?? '');
    $code = trim($input['code'] ?? '');

    if (strlen($phone) < 10 || strlen($phone) > 13) {
        echo json_encode(['success' => false, 'message' => 'Telefone invalido']);
        http_response_code(400);
        exit;
    }
    if (strlen($code) !== 6) {
        echo json_encode(['success' => false, 'message' => 'Codigo deve ter 6 digitos']);
        http_response_code(400);
        exit;
    }

    // Rate limit por IP
    $clientIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!checkRateLimit("webmail_otp_check:$clientIp", 30, 3600)) {
        echo json_encode(['success' => false, 'message' => 'Muitas tentativas. Tente novamente mais tarde.']);
        http_response_code(429);
        exit;
    }

    // Buscar OTP mais recente nao expirado
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("
            SELECT id, code, attempts, expires_at
            FROM om_market_otp_codes
            WHERE phone = ? AND expires_at > NOW() AND (used = 0 OR used IS NULL)
            ORDER BY created_at DESC LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([$phone]);
        $otp = $stmt->fetch();

        if (!$otp) {
            $db->commit();
            echo json_encode(['success' => false, 'message' => 'Codigo expirado ou nao encontrado. Solicite um novo.']);
            http_response_code(400);
            exit;
        }

        // Max 3 tentativas por codigo
        if ((int)$otp['attempts'] >= 3) {
            $db->prepare("UPDATE om_market_otp_codes SET used = 1 WHERE id = ?")->execute([$otp['id']]);
            $db->commit();
            echo json_encode(['success' => false, 'message' => 'Muitas tentativas erradas. Solicite um novo codigo.']);
            http_response_code(400);
            exit;
        }

        // Verificar codigo
        if (!password_verify($code, $otp['code'])) {
            $db->prepare("UPDATE om_market_otp_codes SET attempts = attempts + 1 WHERE id = ?")->execute([$otp['id']]);
            $db->commit();
            $remaining = 3 - (int)$otp['attempts'] - 1;
            echo json_encode(['success' => false, 'message' => "Codigo incorreto. $remaining tentativa(s) restante(s)."]);
            http_response_code(400);
            exit;
        }

        // Marcar como usado
        $db->prepare("UPDATE om_market_otp_codes SET used = 1, verified_at = NOW() WHERE id = ?")->execute([$otp['id']]);
        $db->commit();

    } catch (Exception $txEx) {
        $db->rollBack();
        throw $txEx;
    }

    // Gerar JWT assinado com HMAC-SHA256
    $secret = getenv('WEBMAIL_VERIFY_SECRET') ?: 'OneMundoMail2026SecretKey';

    $header = base64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload = base64url_encode(json_encode([
        'phone' => $phone,
        'verified' => true,
        'iat' => time(),
        'exp' => time() + 600, // 10 minutos
    ]));
    $signature = base64url_encode(hash_hmac('sha256', "$header.$payload", $secret, true));
    $token = "$header.$payload.$signature";

    error_log("[webmail-verify-check] Telefone ***" . substr($phone, -4) . " verificado com sucesso");

    echo json_encode([
        'success' => true,
        'token' => $token,
        'message' => 'Telefone verificado com sucesso!',
    ]);

} catch (Exception $e) {
    error_log("[webmail-verify-check] Erro: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro interno']);
    http_response_code(500);
}

function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
