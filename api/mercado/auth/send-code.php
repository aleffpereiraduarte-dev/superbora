<?php
require_once __DIR__ . "/_debug_log.php";
/**
 * POST /api/mercado/auth/send-code.php
 * Envia codigo de verificacao via WhatsApp (Z-API), SMS (Twilio) ou Email
 * Body: { "phone": "11999999999", "channel": "whatsapp" | "sms" }
 *    ou { "email": "user@example.com", "channel": "email" }
 *
 * Se channel nao especificado, tenta WhatsApp primeiro, fallback SMS
 */
require_once __DIR__ . "/../config/database.php";

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, "Metodo nao permitido", 405);
}

try {
    $db = getDB();
    $input = getInput();

    // Canal preferido (whatsapp, sms ou email)
    $preferredChannel = strtolower($input['channel'] ?? 'whatsapp');
    if (!in_array($preferredChannel, ['whatsapp', 'sms', 'email'])) {
        $preferredChannel = 'whatsapp';
    }

    // Validar entrada baseado no canal
    $phone = '';
    $email = '';
    $identifier = '';

    if ($preferredChannel === 'email') {
        $email = trim($input['email'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            response(false, null, "Email invalido", 400);
        }
        $identifier = $email;
    } else {
        $phone = preg_replace('/\D/', '', $input['phone'] ?? '');
        // Aceita telefones brasileiros (10-11) ou internacionais (8-15 digitos)
        if (strlen($phone) < 8 || strlen($phone) > 15) {
            response(false, null, "Telefone invalido", 400);
        }
        $identifier = $phone;
    }

    // Rate limit: max 3 codigos por identificador por hora (prevent OTP bombing)
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM om_market_otp_codes
        WHERE phone = ? AND created_at > NOW() - INTERVAL '1 hours'
    ");
    $stmt->execute([$identifier]);
    $recentCount = (int)$stmt->fetchColumn();
    if ($recentCount >= 3) {
        response(false, null, "Muitas tentativas. Aguarde 1 hora.", 429);
    }

    // Additional IP-based rate limit: max 10 OTP sends per IP per hour
    $clientIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!checkRateLimit("otp_send:$clientIp", 10, 3600)) {
        response(false, null, "Muitas requisicoes. Tente novamente mais tarde.", 429);
    }

    // Gerar codigo de 6 digitos
    $code = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);

    // Salvar no banco - usar NOW() do PostgreSQL para evitar problemas de timezone
    $stmt = $db->prepare("
        INSERT INTO om_market_otp_codes (phone, code, expires_at, attempts, used, created_at)
        VALUES (?, ?, NOW() + INTERVAL '5 minutes', 0, 0, NOW())
    ");
    $stmt->execute([$identifier, password_hash($code, PASSWORD_DEFAULT)]);

    require_once __DIR__ . '/../helpers/zapi-whatsapp.php';
    require_once __DIR__ . '/../helpers/twilio-sms.php';
    require_once __DIR__ . '/../helpers/email-smtp.php';

    $channel = $preferredChannel;
    $result = ['success' => false];

    // Enviar pelo canal escolhido
    if ($preferredChannel === 'email') {
        $result = sendEmailOTP($email, $code);
        // Fallback: nenhum (email não tem fallback)
    } elseif ($preferredChannel === 'whatsapp') {
        $result = whatsappOTP($phone, $code);
        if (!$result['success']) {
            // Fallback para SMS
            $result = sendSMS($phone, "SuperBora: Seu codigo de verificacao e $code. Valido por 5 minutos.");
            $channel = 'sms';
        }
    } else {
        // Usuario escolheu SMS
        $result = sendSMS($phone, "SuperBora: Seu codigo de verificacao e $code. Valido por 5 minutos.");
        if (!$result['success']) {
            // Fallback para WhatsApp
            $result = whatsappOTP($phone, $code);
            $channel = 'whatsapp';
        }
    }

    $sent = $result['success'];
    if (!$sent) {
        error_log("[send-code] Falha ao enviar para $identifier via $channel: " . ($result['message'] ?? 'erro desconhecido'));
    }

    // SECURITY: Never log OTP codes in plain text
    $maskedIdentifier = $preferredChannel === 'email'
        ? substr($email, 0, 3) . '***@' . explode('@', $email)[1]
        : substr($phone, 0, 2) . "****" . substr($phone, -2);
    error_log("[send-code] Codigo " . ($sent ? "enviado" : "NAO enviado") . " para $maskedIdentifier via $channel");

    // Preparar resposta
    $channelName = match($channel) {
        'whatsapp' => 'WhatsApp',
        'sms' => 'SMS',
        'email' => 'Email',
        default => $channel
    };

    $responseData = [
        "channel" => $channel,
        "sent" => $sent,
        "expires_in" => 300
    ];

    if ($preferredChannel === 'email') {
        $responseData["email"] = substr($email, 0, 3) . '***@' . explode('@', $email)[1];
    } else {
        $responseData["phone"] = substr($phone, 0, 2) . "****" . substr($phone, -4);
    }

    response(true, $responseData, $sent ? "Codigo enviado via $channelName!" : "Codigo gerado. Verifique seu $channelName.");

} catch (Exception $e) {
    error_log("[send-code] Erro: " . $e->getMessage());
    response(false, null, "Erro ao enviar codigo", 500);
}
