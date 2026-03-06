<?php
/**
 * POST /api/mercado/auth/webmail-verify-send.php
 * Envia OTP para verificacao de telefone no signup do OneMundo Mail
 * Body: { "phone": "+5511999999999", "channel": "sms" | "whatsapp" }
 *
 * Endpoint publico (sem autenticacao) - protegido por rate limit + CORS
 */
require_once __DIR__ . "/../config/database.php";

// CORS - permitir requests do webmail
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

    $channel = strtolower($input['channel'] ?? 'sms');
    if (!in_array($channel, ['sms', 'whatsapp'])) {
        $channel = 'sms';
    }

    $phone = preg_replace('/\D/', '', $input['phone'] ?? '');
    if (strlen($phone) < 10 || strlen($phone) > 13) {
        echo json_encode(['success' => false, 'message' => 'Telefone invalido']);
        http_response_code(400);
        exit;
    }

    // Rate limit: 5 envios por telefone por hora
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM om_market_otp_codes
        WHERE phone = ? AND created_at > NOW() - INTERVAL '1 hours'
    ");
    $stmt->execute([$phone]);
    if ((int)$stmt->fetchColumn() >= 5) {
        echo json_encode(['success' => false, 'message' => 'Muitas tentativas. Aguarde 1 hora.']);
        http_response_code(429);
        exit;
    }

    // Rate limit: 20 envios por IP por hora
    $clientIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!checkRateLimit("webmail_otp_send:$clientIp", 20, 3600)) {
        echo json_encode(['success' => false, 'message' => 'Muitas requisicoes. Tente novamente mais tarde.']);
        http_response_code(429);
        exit;
    }

    // Gerar codigo de 6 digitos
    $code = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);

    // Salvar hash no banco
    $stmt = $db->prepare("
        INSERT INTO om_market_otp_codes (phone, code, expires_at, attempts, used, created_at)
        VALUES (?, ?, NOW() + INTERVAL '5 minutes', 0, 0, NOW())
    ");
    $stmt->execute([$phone, password_hash($code, PASSWORD_DEFAULT)]);

    require_once __DIR__ . '/../helpers/zapi-whatsapp.php';
    require_once __DIR__ . '/../helpers/twilio-sms.php';

    $actualChannel = $channel;
    $result = ['success' => false];

    if ($channel === 'whatsapp') {
        $result = whatsappOTP($phone, $code, 'OneMundo Mail');
        if (!$result['success']) {
            // Fallback para SMS
            $result = sendSMS($phone, "OneMundo Mail: Seu codigo de verificacao e $code. Valido por 5 minutos.");
            $actualChannel = 'sms';
        }
    } else {
        $result = sendSMS($phone, "OneMundo Mail: Seu codigo de verificacao e $code. Valido por 5 minutos.");
        if (!$result['success']) {
            // Fallback para WhatsApp
            $result = whatsappOTP($phone, $code, 'OneMundo Mail');
            $actualChannel = 'whatsapp';
        }
    }

    // Mascarar telefone para resposta
    $masked = '(' . substr($phone, -11, 2) . ') ***-**' . substr($phone, -2);

    error_log("[webmail-verify-send] " . ($result['success'] ? "Enviado" : "Falha") . " para ***" . substr($phone, -4) . " via $actualChannel");

    echo json_encode([
        'success' => $result['success'],
        'masked_phone' => $masked,
        'channel' => $actualChannel,
        'message' => $result['success']
            ? "Codigo enviado via " . ($actualChannel === 'whatsapp' ? 'WhatsApp' : 'SMS')
            : 'Falha ao enviar codigo. Tente novamente.',
    ]);

} catch (Exception $e) {
    error_log("[webmail-verify-send] Erro: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro interno']);
    http_response_code(500);
}
