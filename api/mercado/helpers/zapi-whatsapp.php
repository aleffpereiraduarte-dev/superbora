<?php
/**
 * Twilio WhatsApp Integration - SuperBora
 * Envia mensagens WhatsApp via Twilio Content Templates
 *
 * Migrado de Z-API para Twilio (Mar 2026) — mesmas funcoes, mesma interface
 * Usa Content Templates para envio sem janela de 24h
 *
 * Required environment variables:
 *   TWILIO_SID - Twilio Account SID
 *   TWILIO_TOKEN - Twilio Auth Token
 *   TWILIO_WA_FROM - WhatsApp sender (whatsapp:+15705299780)
 *   TWILIO_WA_OTP_TEMPLATE - Content SID for OTP messages
 *   TWILIO_WA_NOTIFY_TEMPLATE - Content SID for general notifications
 */

// Load environment variables if not already loaded
$envPath = dirname(__DIR__, 3) . '/.env';
if (!isset($_ENV['TWILIO_SID']) && file_exists($envPath)) {
    $envFile = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($envFile as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $_ENV[trim($key)] = trim(trim($value), '"\'');
        }
    }
}

// Twilio credentials
$_twilioWaSid = $_ENV['TWILIO_SID'] ?? '';
$_twilioWaToken = $_ENV['TWILIO_TOKEN'] ?? '';
$_twilioWaFrom = $_ENV['TWILIO_WA_FROM'] ?? 'whatsapp:+15705299780';
$_twilioWaOtpTemplate = $_ENV['TWILIO_WA_OTP_TEMPLATE'] ?? '';
$_twilioWaNotifyTemplate = $_ENV['TWILIO_WA_NOTIFY_TEMPLATE'] ?? '';

if (empty($_twilioWaSid) || empty($_twilioWaToken)) {
    error_log("[twilio-wa] CRITICAL: Twilio credentials not configured");
}

// Backward compat constants for files that check ZAPI_BASE_URL
if (!defined('ZAPI_BASE_URL')) define('ZAPI_BASE_URL', !empty($_twilioWaSid) ? 'twilio' : '');
if (!defined('ZAPI_CLIENT_TOKEN')) define('ZAPI_CLIENT_TOKEN', $_twilioWaToken);
if (!defined('ZAPI_INSTANCE_ID')) define('ZAPI_INSTANCE_ID', $_twilioWaSid);
if (!defined('ZAPI_INSTANCE_TOKEN')) define('ZAPI_INSTANCE_TOKEN', $_twilioWaToken);
if (!defined('ZAPI_MAX_RETRIES')) define('ZAPI_MAX_RETRIES', 3);
if (!defined('ZAPI_RETRY_BASE_DELAY')) define('ZAPI_RETRY_BASE_DELAY', 1);

/**
 * Internal: Send WhatsApp via Twilio Content Template
 * @param string $phone Phone number
 * @param string $message Message body (used as variable {{1}} in template)
 * @param string|null $templateSid Specific template SID (null = general notification)
 * @param array $variables Template variables (e.g. ['1' => 'value'])
 * @return array ['success' => bool, 'message' => string, 'messageId' => string|null]
 */
function _twilioWaSend(string $phone, string $message, ?string $templateSid = null, array $variables = []): array {
    global $_twilioWaSid, $_twilioWaToken, $_twilioWaFrom, $_twilioWaNotifyTemplate;

    if (empty($_twilioWaSid) || empty($_twilioWaToken)) {
        return ['success' => false, 'message' => 'Twilio WhatsApp nao configurado'];
    }

    $to = _formatPhoneForTwilioWa($phone);
    if (!$to) {
        return ['success' => false, 'message' => 'Telefone invalido'];
    }

    $url = "https://api.twilio.com/2010-04-01/Accounts/{$_twilioWaSid}/Messages.json";

    // Use template if available, otherwise send freeform
    $contentSid = $templateSid ?: $_twilioWaNotifyTemplate;

    $fields = [
        'From' => $_twilioWaFrom,
        'To' => 'whatsapp:' . $to,
    ];

    if ($contentSid && !empty($variables)) {
        $fields['ContentSid'] = $contentSid;
        $fields['ContentVariables'] = json_encode($variables);
    } elseif ($contentSid) {
        $fields['ContentSid'] = $contentSid;
        $fields['ContentVariables'] = json_encode(['1' => $message]);
    } else {
        // Fallback: freeform message (only works within 24h conversation window)
        $fields['Body'] = $message;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_USERPWD => $_twilioWaSid . ':' . $_twilioWaToken,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);

    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        error_log("[twilio-wa] cURL error: $error");
        return ['success' => false, 'message' => "Erro conexao: $error"];
    }

    $data = json_decode($result, true);

    if ($httpCode >= 200 && $httpCode < 300) {
        $msgSid = $data['sid'] ?? '';
        $phoneMasked = substr($to, 0, 4) . '****' . substr($to, -2);
        error_log("[twilio-wa] Enviado para {$phoneMasked} | SID: {$msgSid}");
        return ['success' => true, 'message' => 'Enviado', 'messageId' => $msgSid];
    }

    $errMsg = $data['message'] ?? "HTTP $httpCode";
    $errCode = $data['code'] ?? '';
    $phoneMasked = substr($to, 0, 4) . '****' . substr($to, -2);
    error_log("[twilio-wa] Falha para {$phoneMasked}: [{$errCode}] {$errMsg}");
    return ['success' => false, 'message' => $errMsg];
}

/**
 * Format phone for Twilio WhatsApp (returns +XXXXXXXXXXX without whatsapp: prefix)
 */
function _formatPhoneForTwilioWa(string $phone): string {
    $phone = preg_replace('/\D/', '', $phone);

    // Brazilian numbers without country code: prepend 55
    if (strlen($phone) >= 10 && strlen($phone) <= 11 && !preg_match('/^(55|1|44|34|49|33|61|81|86|91|52|54|56|57|58|595|598)/', $phone)) {
        $phone = '55' . $phone;
    }

    if (strlen($phone) < 11 || strlen($phone) > 15) {
        return '';
    }

    return '+' . $phone;
}

// === Public API (backward compatible with Z-API interface) ===

/**
 * Send WhatsApp with automatic retry
 */
function sendWhatsAppWithRetry(string $phone, string $message, int $maxRetries = ZAPI_MAX_RETRIES): array {
    $attempt = 0;
    $lastError = '';

    while ($attempt < $maxRetries) {
        $result = sendWhatsAppInternal($phone, $message);

        if ($result['success']) {
            if ($attempt > 0) {
                error_log("[twilio-wa] Success after " . ($attempt + 1) . " attempts for phone ending " . substr($phone, -4));
            }
            return $result;
        }

        $lastError = $result['message'] ?? 'Unknown error';
        $attempt++;

        if ($attempt < $maxRetries) {
            $delay = ZAPI_RETRY_BASE_DELAY * pow(2, $attempt - 1);
            error_log("[twilio-wa] Retry $attempt/$maxRetries in {$delay}s for phone ending " . substr($phone, -4));
            sleep($delay);
        }
    }

    error_log("[twilio-wa] All $maxRetries retries failed for phone ending " . substr($phone, -4) . ": $lastError");
    return ['success' => false, 'message' => "Failed after $maxRetries attempts: $lastError"];
}

/**
 * Send WhatsApp message (with automatic retry)
 */
function sendWhatsApp(string $phone, string $message): array {
    return sendWhatsAppWithRetry($phone, $message);
}

/**
 * Internal: Send single WhatsApp message via Twilio
 */
function sendWhatsAppInternal(string $phone, string $message): array {
    return _twilioWaSend($phone, $message);
}

/**
 * Send WhatsApp with interactive buttons
 * Note: Twilio Content Templates don't support dynamic buttons the same way.
 * Falls back to text message with numbered options.
 */
function sendWhatsAppButtons(string $phone, string $message, array $buttons): array {
    $buttonText = '';
    foreach (array_slice($buttons, 0, 3) as $i => $btn) {
        $label = $btn['label'] ?? '';
        $buttonText .= "\n" . ($i + 1) . ". {$label}";
    }
    return _twilioWaSend($phone, $message . "\n" . $buttonText);
}

/**
 * Send WhatsApp with list menu
 * Falls back to text message with section headers and items.
 */
function sendWhatsAppList(string $phone, string $message, string $buttonLabel, array $sections): array {
    $listText = $message;
    foreach ($sections as $section) {
        $listText .= "\n\n*{$section['title']}*";
        foreach (($section['rows'] ?? []) as $row) {
            $title = $row['title'] ?? '';
            $desc = $row['description'] ?? '';
            $listText .= "\n- {$title}";
            if ($desc) $listText .= " — {$desc}";
        }
    }
    return _twilioWaSend($phone, $listText);
}

/**
 * Send image via WhatsApp
 * Uses Twilio MMS with MediaUrl
 */
function sendWhatsAppImage(string $phone, string $imageUrl, string $caption = ''): array {
    global $_twilioWaSid, $_twilioWaToken, $_twilioWaFrom;

    if (empty($_twilioWaSid) || empty($_twilioWaToken)) {
        return ['success' => false, 'message' => 'Twilio WhatsApp nao configurado'];
    }

    $to = _formatPhoneForTwilioWa($phone);
    if (!$to) return ['success' => false, 'message' => 'Telefone invalido'];

    $url = "https://api.twilio.com/2010-04-01/Accounts/{$_twilioWaSid}/Messages.json";

    $fields = [
        'From' => $_twilioWaFrom,
        'To' => 'whatsapp:' . $to,
        'MediaUrl' => $imageUrl,
    ];
    if ($caption) $fields['Body'] = $caption;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_USERPWD => $_twilioWaSid . ':' . $_twilioWaToken,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);

    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        error_log("[twilio-wa] cURL error (image): $error");
        return ['success' => false, 'message' => "Erro conexao: $error"];
    }

    $data = json_decode($result, true);
    $ok = $httpCode >= 200 && $httpCode < 300;
    $phoneMasked = substr($to, 0, 4) . '****' . substr($to, -2);

    if ($ok) {
        error_log("[twilio-wa] Imagem enviada para {$phoneMasked}");
    } else {
        error_log("[twilio-wa] Falha imagem para {$phoneMasked}: " . ($data['message'] ?? "HTTP $httpCode"));
    }

    return ['success' => $ok, 'message' => $ok ? 'Enviado' : ($data['message'] ?? 'Erro')];
}

// Alias for compatibility
if (!function_exists('sendWhatsAppText')) {
    function sendWhatsAppText(string $phone, string $message): array {
        return sendWhatsApp($phone, $message);
    }
}

// Keep old function name for compatibility
function formatPhoneForZapi(string $phone): string {
    $formatted = _formatPhoneForTwilioWa($phone);
    return $formatted ? ltrim($formatted, '+') : '';
}

// === Message Templates (same interface as before) ===

function whatsappOrderCreated(string $phone, string $orderNumber, float $total, string $partnerName): array {
    $totalFmt = number_format($total, 2, ',', '.');
    $msg = "🛒 *Pedido Confirmado!*\n\n"
         . "Pedido: *#{$orderNumber}*\n"
         . "Valor: *R\$ {$totalFmt}*\n"
         . "Loja: {$partnerName}\n\n"
         . "Acompanhe seu pedido pelo app SuperBora! 📱";
    return sendWhatsApp($phone, $msg);
}

function whatsappOrderAccepted(string $phone, string $orderNumber, string $partnerName = '', int $etaMinutes = 0, string $etaTime = ''): array {
    $storeInfo = $partnerName ? " da *{$partnerName}*" : '';
    $msg = "✅ *Pedido Aceito!*\n\n"
         . "Seu pedido *#{$orderNumber}*{$storeInfo} foi aceito!\n";
    if ($etaMinutes > 0 && $etaTime) {
        $msg .= "Previsao de entrega: *~{$etaMinutes} minutos* (por volta das {$etaTime}) 🕐\n";
    } elseif ($etaMinutes > 0) {
        $msg .= "Previsao de entrega: *~{$etaMinutes} minutos* 🕐\n";
    }
    $msg .= "\nJa estamos cuidando dele! Voce recebe atualizacoes aqui mesmo 📲";
    return sendWhatsApp($phone, $msg);
}

function whatsappOrderPreparing(string $phone, string $orderNumber, string $partnerName = '', int $prepMinutes = 0): array {
    $storeInfo = $partnerName ? " na *{$partnerName}*" : '';
    $msg = "👨‍🍳 *Preparando seu pedido!*\n\n"
         . "Pedido *#{$orderNumber}* ta sendo preparado{$storeInfo}!\n";
    if ($prepMinutes > 0) {
        $msg .= "Deve ficar pronto em *~{$prepMinutes} minutos* ⏳\n";
    }
    $msg .= "\nVoce vai saber assim que sair pra entrega 🚀";
    return sendWhatsApp($phone, $msg);
}

function whatsappOrderReady(string $phone, string $orderNumber, string $partnerName = '', int $deliveryMinutes = 0, bool $isPickup = false): array {
    $storeInfo = $partnerName ? " na *{$partnerName}*" : '';
    if ($isPickup) {
        $msg = "🎉 *Pedido Pronto pra Retirada!*\n\n"
             . "Seu pedido *#{$orderNumber}*{$storeInfo} ta pronto!\n"
             . "E so passar la pra retirar 🏃";
    } else {
        $msg = "🎉 *Pedido Pronto!*\n\n"
             . "Seu pedido *#{$orderNumber}*{$storeInfo} ta pronto e saindo pra entrega!\n";
        if ($deliveryMinutes > 0) {
            $msg .= "Chega em *~{$deliveryMinutes} minutos* 🏍️\n";
        } else {
            $msg .= "Estamos buscando um entregador pra voce 🏍️\n";
        }
    }
    return sendWhatsApp($phone, $msg);
}

function whatsappOrderOnTheWay(string $phone, string $orderNumber): array {
    $msg = "🏍️ *Saiu para entrega!*\n\n"
         . "Pedido *#{$orderNumber}* esta a caminho!\n"
         . "Prepare-se para receber. 📦";
    return sendWhatsApp($phone, $msg);
}

function whatsappOrderDelivered(string $phone, string $orderNumber, string $partnerName = ''): array {
    $storeInfo = $partnerName ? " da *{$partnerName}*" : '';
    $msg = "✅ *Pedido Entregue!*\n\n"
         . "Pedido *#{$orderNumber}*{$storeInfo} foi entregue! 😋\n"
         . "Bom apetite e obrigado por usar o SuperBora! ⭐\n\n"
         . "Que tal avaliar sua experiencia? Responda com uma nota de 1 a 5!";
    return sendWhatsApp($phone, $msg);
}

function whatsappOrderCancelled(string $phone, string $orderNumber, string $reason = ''): array {
    $msg = "❌ *Pedido Cancelado*\n\n"
         . "Pedido *#{$orderNumber}* foi cancelado.";
    if ($reason) $msg .= "\nMotivo: {$reason}";
    $msg .= "\n\nSe precisar de ajuda, entre em contato conosco.";
    return sendWhatsApp($phone, $msg);
}

function whatsappShopperAssigned(string $phone, string $orderNumber, string $shopperName): array {
    $msg = "🛍️ *Shopper a caminho!*\n\n"
         . "Pedido *#{$orderNumber}*\n"
         . "Shopper: *{$shopperName}*\n\n"
         . "Seu shopper esta indo ao mercado buscar seus itens! 🏃";
    return sendWhatsApp($phone, $msg);
}

/**
 * Send OTP code via WhatsApp using dedicated OTP template
 */
function whatsappOTP(string $phone, string $code, string $app = 'SuperBora'): array {
    global $_twilioWaOtpTemplate;

    // Use dedicated OTP template with code as variable
    if (!empty($_twilioWaOtpTemplate)) {
        return _twilioWaSend($phone, $code, $_twilioWaOtpTemplate, ['1' => $code]);
    }

    // Fallback: general template
    $msg = "🔐 *{$app} - Codigo de Verificacao*\n\n"
         . "Seu codigo: *{$code}*\n\n"
         . "Valido por 5 minutos. Nao compartilhe este codigo.";
    return sendWhatsApp($phone, $msg);
}

function whatsappAskRating(string $phone, string $orderNumber, string $partnerName): array {
    $msg = "⭐ *Como foi seu pedido?*\n\n"
         . "Pedido *#{$orderNumber}* da *{$partnerName}*\n\n"
         . "De 1 a 5, como voce avalia?\n"
         . "1 ⭐ — Ruim\n"
         . "2 ⭐⭐ — Regular\n"
         . "3 ⭐⭐⭐ — Bom\n"
         . "4 ⭐⭐⭐⭐ — Muito bom\n"
         . "5 ⭐⭐⭐⭐⭐ — Excelente\n\n"
         . "Responda com o numero ou mande um comentario!";
    return sendWhatsApp($phone, $msg);
}

function whatsappNewOrderPartner(string $phone, string $orderNumber, float $total, string $customerName): array {
    $totalFmt = number_format($total, 2, ',', '.');
    $msg = "🔔 *Novo Pedido!*\n\n"
         . "Pedido: *#{$orderNumber}*\n"
         . "Cliente: {$customerName}\n"
         . "Valor: *R\$ {$totalFmt}*\n\n"
         . "Acesse o painel para confirmar! 📋";
    return sendWhatsApp($phone, $msg);
}
