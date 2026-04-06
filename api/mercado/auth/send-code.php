<?php
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
        // Aceita telefones brasileiros (10-11) ou internacionais (10-15 digitos)
        if (strlen($phone) < 10 || strlen($phone) > 15) {
            response(false, null, "Telefone invalido", 400);
        }
        $identifier = $phone;
    }

    // Check if phone/email already exists (for smart login flow)
    // Only check on login context (check_exists param from mobile app)
    $checkExists = filter_var($input['check_exists'] ?? false, FILTER_VALIDATE_BOOLEAN);

    if ($checkExists && $preferredChannel !== 'email' && !empty($phone)) {
        $stmt = $db->prepare("
            SELECT customer_id FROM om_customers
            WHERE REGEXP_REPLACE(phone, '[^0-9]', '', 'g') = ?
        ");
        $stmt->execute([$phone]);
        $exists = (bool)$stmt->fetch();

        if (!$exists) {
            // Phone not registered — tell the frontend so it can offer registration
            response(true, [
                "exists" => false,
                "phone" => substr($phone, 0, 2) . "****" . substr($phone, -4)
            ], "Numero nao cadastrado");
        }
        // If exists, continue with OTP send below
    }

    // Demo account bypass for Apple App Store review (fixed phone + code 123456)
    // Accept the demo number with any country code prefix: 15550000001, 115550000001, 5515550000001, etc.
    $demoSuffix = '5550000001';
    if (str_ends_with($phone, $demoSuffix) && strlen($phone) >= strlen($demoSuffix) && strlen($phone) <= strlen($demoSuffix) + 3) {
        response(true, [
            "channel" => "sms",
            "sent" => true,
            "expires_in" => 300,
            "phone" => "(555) ***-0001",
            "exists" => true
        ], "Codigo enviado via SMS!");
    }

    // Rate limit: max 3 codigos por identificador por hora (prevent OTP bombing)
    // Per-phone rate limit relaxed — Twilio Verify handles anti-fraud
    // Clean up old codes for this identifier
    $db->prepare("DELETE FROM om_market_otp_codes WHERE phone = ? AND used = 0 AND expires_at < NOW()")->execute([$identifier]);

    // IP-based rate limit disabled — Twilio Verify has built-in anti-fraud

    // ─── Twilio Verify (primary for SMS/WhatsApp OTP) ───
    $twilioSid = $_ENV['TWILIO_SID'] ?? getenv('TWILIO_SID') ?: '';
    $twilioToken = $_ENV['TWILIO_TOKEN'] ?? getenv('TWILIO_TOKEN') ?: '';
    $verifySid = $_ENV['TWILIO_VERIFY_SID'] ?? getenv('TWILIO_VERIFY_SID') ?: 'VA34083528deea28a6963d3bee14a72ceb';
    $useVerify = !empty($twilioSid) && !empty($twilioToken) && !empty($verifySid) && $preferredChannel !== 'email';

    $channel = $preferredChannel;
    $sent = false;
    $code = '';
    $usedVerify = false;

    if ($useVerify) {
        // Use Twilio Verify — handles 10DLC, retry, anti-fraud automatically
        $verifyChannel = ($preferredChannel === 'whatsapp') ? 'whatsapp' : 'sms';
        $toNumber = '+' . ltrim($phone, '+');

        $ch = curl_init("https://verify.twilio.com/v2/Services/$verifySid/Verifications");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['To' => $toNumber, 'Channel' => $verifyChannel]),
            CURLOPT_USERPWD => "$twilioSid:$twilioToken",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($resp, true);
        if ($httpCode >= 200 && $httpCode < 300 && ($data['status'] ?? '') === 'pending') {
            $sent = true;
            $usedVerify = true;
            $channel = $data['channel'] ?? $verifyChannel;
            error_log("[send-code] Twilio Verify sent to $toNumber via $channel (SID: " . ($data['sid'] ?? '?') . ")");
        } else {
            $errorMsg = $data['message'] ?? ($data['error_message'] ?? 'unknown');
            error_log("[send-code] Twilio Verify failed: $httpCode - $errorMsg — falling back to manual");

            // Fallback: try the other channel via Verify
            if ($verifyChannel === 'sms') {
                $ch2 = curl_init("https://verify.twilio.com/v2/Services/$verifySid/Verifications");
                curl_setopt_array($ch2, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => http_build_query(['To' => $toNumber, 'Channel' => 'whatsapp']),
                    CURLOPT_USERPWD => "$twilioSid:$twilioToken",
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 15,
                ]);
                $resp2 = curl_exec($ch2);
                $httpCode2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
                curl_close($ch2);
                $data2 = json_decode($resp2, true);
                if ($httpCode2 >= 200 && $httpCode2 < 300 && ($data2['status'] ?? '') === 'pending') {
                    $sent = true;
                    $usedVerify = true;
                    $channel = 'whatsapp';
                }
            }
        }

        // Save a placeholder OTP record (Verify manages the real code)
        if ($sent) {
            $code = '000000'; // Placeholder — verification happens via Twilio Verify API
            $stmt = $db->prepare("
                INSERT INTO om_market_otp_codes (phone, code, expires_at, attempts, used, created_at, verify_sid)
                VALUES (?, 'twilio_verify', NOW() + INTERVAL '10 minutes', 0, 0, NOW(), ?)
            ");
            $stmt->execute([$identifier, $data['sid'] ?? ($data2['sid'] ?? '')]);
        }
    }

    // Fallback: manual OTP (for email or if Verify unavailable)
    if (!$sent) {
        $code = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);

        $stmt = $db->prepare("
            INSERT INTO om_market_otp_codes (phone, code, expires_at, attempts, used, created_at)
            VALUES (?, ?, NOW() + INTERVAL '5 minutes', 0, 0, NOW())
        ");
        $stmt->execute([$identifier, password_hash($code, PASSWORD_DEFAULT)]);

        require_once __DIR__ . '/../helpers/zapi-whatsapp.php';
        require_once __DIR__ . '/../helpers/twilio-sms.php';
        require_once __DIR__ . '/../helpers/email-smtp.php';

        $result = ['success' => false];
        if ($preferredChannel === 'email') {
            $result = sendEmailOTP($email, $code);
        } elseif ($preferredChannel === 'whatsapp') {
            $result = whatsappOTP($phone, $code);
            if (!$result['success']) {
                $result = sendSMS($phone, "SuperBora: Seu codigo de verificacao e $code. Valido por 5 minutos.");
                $channel = 'sms';
            }
        } else {
            $result = sendSMS($phone, "SuperBora: Seu codigo de verificacao e $code. Valido por 5 minutos.");
            if (!$result['success']) {
                $result = whatsappOTP($phone, $code);
                $channel = 'whatsapp';
            }
        }
        $sent = $result['success'];
    }

    if (!$sent) {
        error_log("[send-code] Falha ao enviar para $identifier via $channel");
    }

    $maskedIdentifier = $preferredChannel === 'email'
        ? substr($email, 0, 3) . '***@' . explode('@', $email)[1]
        : substr($phone, 0, 2) . "****" . substr($phone, -2);
    error_log("[send-code] " . ($sent ? "Enviado" : "FALHA") . " para $maskedIdentifier via $channel" . ($usedVerify ? " (Twilio Verify)" : ""));

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
        "expires_in" => 300,
        "exists" => true
    ];

    if ($preferredChannel === 'email') {
        $responseData["email"] = substr($email, 0, 3) . '***@' . explode('@', $email)[1];
    } else {
        $responseData["phone"] = substr($phone, 0, 2) . "****" . substr($phone, -4);
    }

    if (!$sent) {
        response(false, $responseData, "Nao foi possivel enviar o codigo. Tente outro canal.", 500);
    }
    response(true, $responseData, "Codigo enviado via $channelName!");

} catch (Exception $e) {
    error_log("[send-code] Erro: " . $e->getMessage());
    response(false, null, "Erro ao enviar codigo", 500);
}
