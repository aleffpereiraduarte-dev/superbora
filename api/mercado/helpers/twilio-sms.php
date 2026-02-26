<?php
/**
 * Twilio SMS Helper - SuperBora
 * Envia SMS via Twilio (fallback quando WhatsApp nao disponivel)
 */

// Load from environment at runtime - avoid global constants to prevent leakage via get_defined_constants()
function _getTwilioSid(): string { return $_ENV['TWILIO_SID'] ?? getenv('TWILIO_SID') ?: ''; }
function _getTwilioToken(): string { return $_ENV['TWILIO_TOKEN'] ?? getenv('TWILIO_TOKEN') ?: ''; }
function _getTwilioPhone(): string { return $_ENV['TWILIO_PHONE'] ?? getenv('TWILIO_PHONE') ?: ''; }
// Backward compat: define constants if not already defined
if (!defined('TWILIO_SID')) define('TWILIO_SID', _getTwilioSid());
if (!defined('TWILIO_TOKEN')) define('TWILIO_TOKEN', _getTwilioToken());
if (!defined('TWILIO_PHONE')) define('TWILIO_PHONE', _getTwilioPhone());

/**
 * Envia SMS via Twilio
 * @param string $to Telefone com codigo do pais (ex: +5511999999999)
 * @param string $body Corpo da mensagem
 * @return array ['success' => bool, 'sid' => string|null, 'message' => string]
 */
function sendSMS(string $to, string $body): array {
    // Validate credentials are configured
    if (empty(TWILIO_SID) || empty(TWILIO_TOKEN) || empty(TWILIO_PHONE)) {
        error_log("[twilio] Credentials not configured");
        return ['success' => false, 'sid' => null, 'message' => 'SMS service not configured'];
    }

    $to = formatPhoneForTwilio($to);
    if (!$to) {
        return ['success' => false, 'sid' => null, 'message' => 'Telefone invalido'];
    }

    $url = "https://api.twilio.com/2010-04-01/Accounts/" . TWILIO_SID . "/Messages.json";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'From' => TWILIO_PHONE,
            'To' => $to,
            'Body' => $body
        ]),
        CURLOPT_USERPWD => TWILIO_SID . ':' . TWILIO_TOKEN,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15
    ]);

    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        error_log("[twilio] cURL error: $error");
        return ['success' => false, 'sid' => null, 'message' => "Erro conexao: $error"];
    }

    $data = json_decode($result, true);

    if ($httpCode >= 200 && $httpCode < 300) {
        error_log("[twilio] SMS enviado para " . substr($to, 0, 4) . "****" . substr($to, -2) . " | SID: " . ($data['sid'] ?? ''));
        return ['success' => true, 'sid' => $data['sid'] ?? null, 'message' => 'SMS enviado'];
    }

    $errMsg = $data['message'] ?? "HTTP $httpCode";
    error_log("[twilio] Falha enviar SMS para " . substr($to, 0, 4) . "****" . substr($to, -2) . ": $errMsg");
    return ['success' => false, 'sid' => null, 'message' => $errMsg];
}

/**
 * Formata telefone para formato Twilio (+15551234567)
 * Aceita numeros brasileiros e internacionais
 * O frontend já envia com código do país
 */
function formatPhoneForTwilio(string $phone): string {
    $phone = preg_replace('/\D/', '', $phone);

    // Aceita numeros de 10 a 15 digitos (com codigo do pais)
    // Frontend já envia: código país + número local
    if (strlen($phone) < 10 || strlen($phone) > 15) {
        return '';
    }
    return '+' . $phone;
}
