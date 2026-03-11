<?php
/**
 * WhatsApp + SMS Helper - SuperBora
 * Envia mensagem via WhatsApp (Twilio) com SMS fallback para internacionais
 */

require_once __DIR__ . '/twilio-sms.php';
require_once __DIR__ . '/zapi-whatsapp.php';

/**
 * Envia mensagem via WhatsApp (Twilio) com SMS fallback
 * @return array ['success' => bool, 'sent_via' => 'whatsapp'|'sms'|'none', 'message' => string]
 */
function sendWhatsAppOrSMS(string $to, string $body): array {
    // Twilio WhatsApp (primary)
    $waResult = sendWhatsAppWithRetry($to, $body, 2);
    if ($waResult['success']) {
        error_log("[wa-sms] WhatsApp (Twilio) sent to " . substr($to, -4));
        return ['success' => true, 'sent_via' => 'whatsapp', 'message' => 'WhatsApp enviado'];
    }
    error_log("[wa-sms] Twilio WhatsApp failed: {$waResult['message']}");

    // SMS fallback for non-BR numbers
    $cleanTo = preg_replace('/\D/', '', $to);
    $isBrazilian = str_starts_with($cleanTo, '55') || (strlen($cleanTo) <= 11);

    if (!$isBrazilian) {
        $smsResult = sendSMS($to, $body);
        if ($smsResult['success']) {
            return ['success' => true, 'sent_via' => 'sms', 'message' => 'SMS enviado'];
        }
    }

    error_log("[wa-sms] Could not deliver to {$to} — WhatsApp failed and SMS blocked for BR");
    return ['success' => false, 'sent_via' => 'none', 'message' => 'Nao foi possivel enviar mensagem'];
}
