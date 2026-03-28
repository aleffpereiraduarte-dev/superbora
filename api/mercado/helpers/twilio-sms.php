<?php
/**
 * SMS Helper - SuperBora
 * Envia SMS via Telnyx (primary) ou Twilio (fallback)
 * Provider controlado pela env var SMS_PROVIDER (telnyx|twilio)
 */

// Load from environment at runtime - avoid global constants to prevent leakage via get_defined_constants()
function _getTwilioSid(): string { return $_ENV['TWILIO_SID'] ?? getenv('TWILIO_SID') ?: ''; }
function _getTwilioToken(): string { return $_ENV['TWILIO_TOKEN'] ?? getenv('TWILIO_TOKEN') ?: ''; }
function _getTwilioPhone(): string { return $_ENV['TWILIO_PHONE'] ?? getenv('TWILIO_PHONE') ?: ''; }
function _getTwilioPhoneBR(): string { return $_ENV['TWILIO_PHONE_BR'] ?? getenv('TWILIO_PHONE_BR') ?: ''; }
// Backward compat: define constants if not already defined
if (!defined('TWILIO_SID')) define('TWILIO_SID', _getTwilioSid());
if (!defined('TWILIO_TOKEN')) define('TWILIO_TOKEN', _getTwilioToken());
if (!defined('TWILIO_PHONE')) define('TWILIO_PHONE', _getTwilioPhone());
if (!defined('TWILIO_PHONE_BR')) define('TWILIO_PHONE_BR', _getTwilioPhoneBR());

/**
 * Retorna o melhor numero Twilio para ligar/enviar SMS para o destino.
 * Numeros BR (+55) usam o numero BR, resto usa o US.
 */
function getTwilioCallerFor(string $to): string {
    $br = defined('TWILIO_PHONE_BR') ? TWILIO_PHONE_BR : '';
    if ($br && preg_match('/^\+?55/', preg_replace('/\D/', '', $to))) {
        return $br;
    }
    return TWILIO_PHONE;
}

/**
 * Envia SMS — despacha para Telnyx ou Twilio conforme SMS_PROVIDER.
 * @param string $to Telefone com codigo do pais (ex: +5511999999999)
 * @param string $body Corpo da mensagem
 * @return array ['success' => bool, 'sid' => string|null, 'message' => string]
 */
function sendSMS(string $to, string $body): array {
    $provider = strtolower($_ENV['SMS_PROVIDER'] ?? getenv('SMS_PROVIDER') ?: 'twilio');

    if ($provider === 'telnyx') {
        return _sendSMSViaTelnyx($to, $body);
    }

    return _sendSMSViaTwilio($to, $body);
}

/**
 * Send SMS via Telnyx API
 */
function _sendSMSViaTelnyx(string $to, string $body): array {
    require_once __DIR__ . '/telnyx-client.php';
    $telnyx = getTelnyxClient();

    if (!$telnyx->isConfigured()) {
        error_log("[sms] Telnyx not configured — falling back to Twilio");
        return _sendSMSViaTwilio($to, $body);
    }

    $result = $telnyx->sendSMS($to, $body);

    // Normalize to match Twilio format (sid key)
    return [
        'success' => $result['success'],
        'sid'     => $result['id'] ?? null,
        'message' => $result['message'],
    ];
}

/**
 * Send SMS via Twilio API (original implementation)
 */
function _sendSMSViaTwilio(string $to, string $body): array {
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

    $from = getTwilioCallerFor($to);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'From' => $from,
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
 * Formata telefone para formato E.164 (+15551234567)
 * Aceita numeros brasileiros e internacionais
 * O frontend já envia com código do país
 */
function formatPhoneForTwilio(string $phone): string {
    $phone = preg_replace('/\D/', '', $phone);

    // Brazilian numbers: 10-11 digits without any known country code → prepend 55
    // Numbers starting with known country codes (1=US/CA, 44=UK, etc.) should NOT get 55
    if (strlen($phone) >= 10 && strlen($phone) <= 11 && !preg_match('/^(55|1|44|34|49|33|61|81|86|91|52|54|56|57|58|595|598)/', $phone)) {
        $phone = '55' . $phone;
    }

    // Aceita numeros de 10 a 15 digitos (com codigo do pais)
    // US numbers: 1 + 10 digits = 11 digits
    if (strlen($phone) < 11 || strlen($phone) > 15) {
        return '';
    }
    return '+' . $phone;
}
