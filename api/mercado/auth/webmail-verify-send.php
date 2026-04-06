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

    $channel = strtolower($input['channel'] ?? 'whatsapp');
    if (!in_array($channel, ['sms', 'whatsapp'])) {
        $channel = 'whatsapp';
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

    $twilioSid = $_ENV['TWILIO_SID'] ?? getenv('TWILIO_SID') ?: '';
    $twilioToken = $_ENV['TWILIO_TOKEN'] ?? $_ENV['TWILIO_AUTH_TOKEN'] ?? getenv('TWILIO_TOKEN') ?: '';
    $verifySid = $_ENV['TWILIO_VERIFY_SID'] ?? 'VA34083528deea28a6963d3bee14a72ceb';

    $actualChannel = $channel;
    $result = ['success' => false];

    if ($channel === 'whatsapp') {
        // WhatsApp via Z-API com codigo customizado
        $result = whatsappOTP($phone, $code, 'OneMundo Mail');
        if (!$result['success']) {
            // Fallback: Twilio Verify SMS (short code brasileiro, nao numero americano)
            $verifyResult = twilioVerifySend($twilioSid, $twilioToken, $verifySid, $phone);
            if ($verifyResult['success']) {
                // Marcar que usou Twilio Verify (codigo diferente do nosso)
                $db->prepare("UPDATE om_market_otp_codes SET code = 'TWILIO_VERIFY' WHERE id = (SELECT id FROM om_market_otp_codes WHERE phone = ? ORDER BY created_at DESC LIMIT 1)")
                   ->execute([$phone]);
                $result = ['success' => true];
                $actualChannel = 'sms';
            } else {
                $actualChannel = 'none';
            }
        }
    } else {
        // SMS via Twilio Verify (short code brasileiro)
        $verifyResult = twilioVerifySend($twilioSid, $twilioToken, $verifySid, $phone);
        if ($verifyResult['success']) {
            $db->prepare("UPDATE om_market_otp_codes SET code = 'TWILIO_VERIFY' WHERE id = (SELECT id FROM om_market_otp_codes WHERE phone = ? ORDER BY created_at DESC LIMIT 1)")
               ->execute([$phone]);
            $result = ['success' => true];
        } else {
            // Fallback: WhatsApp
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

/**
 * Send verification code via Twilio Verify API (uses Brazilian short code, not US number)
 */
function twilioVerifySend(string $sid, string $token, string $verifySid, string $phone): array {
    if (empty($sid) || empty($token)) {
        return ['success' => false, 'message' => 'Twilio not configured'];
    }

    $cleanPhone = preg_replace('/\D/', '', $phone);
    if (strlen($cleanPhone) >= 10 && strlen($cleanPhone) <= 11 && !preg_match('/^(55|1|44|34|49|33|61|81)/', $cleanPhone)) {
        $cleanPhone = '55' . $cleanPhone;
    }
    $formattedPhone = '+' . $cleanPhone;

    $url = "https://verify.twilio.com/v2/Services/{$verifySid}/Verifications";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['To' => $formattedPhone, 'Channel' => 'sms']),
        CURLOPT_USERPWD => "{$sid}:{$token}",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($result, true) ?? [];
    if ($httpCode >= 200 && $httpCode < 300) {
        error_log("[webmail-verify-send] Twilio Verify SMS sent to {$formattedPhone}");
        return ['success' => true, 'data' => $data];
    }
    error_log("[webmail-verify-send] Twilio Verify failed: " . ($data['message'] ?? "HTTP {$httpCode}"));
    return ['success' => false, 'message' => $data['message'] ?? "HTTP {$httpCode}"];
}
