<?php
/**
 * Z-API WhatsApp Integration - SuperBora
 * Envia mensagens WhatsApp via Z-API (canal padrao de notificacao)
 *
 * Docs: https://developer.z-api.io/
 *
 * Required environment variables:
 *   ZAPI_INSTANCE_ID - Z-API instance identifier
 *   ZAPI_INSTANCE_TOKEN - Z-API instance token
 *   ZAPI_CLIENT_TOKEN - Z-API client token
 */

// Load environment variables if not already loaded
// SECURITY: Use __DIR__ relative path instead of DOCUMENT_ROOT to prevent path manipulation
$envPath = dirname(__DIR__, 3) . '/.env';
if (!isset($_ENV['ZAPI_INSTANCE_ID']) && file_exists($envPath)) {
    $envFile = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($envFile as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $_ENV[trim($key)] = trim(trim($value), '"\'');
        }
    }
}

// Get credentials from environment variables (REQUIRED - no fallback for security)
$zapiInstanceId = $_ENV['ZAPI_INSTANCE_ID'] ?? '';
$zapiInstanceToken = $_ENV['ZAPI_INSTANCE_TOKEN'] ?? '';
$zapiClientToken = $_ENV['ZAPI_CLIENT_TOKEN'] ?? '';

if (empty($zapiInstanceId) || empty($zapiInstanceToken) || empty($zapiClientToken)) {
    error_log("[zapi] CRITICAL: Z-API credentials not configured in environment variables");
}

if (!defined('ZAPI_INSTANCE_ID')) define('ZAPI_INSTANCE_ID', $zapiInstanceId);
if (!defined('ZAPI_INSTANCE_TOKEN')) define('ZAPI_INSTANCE_TOKEN', $zapiInstanceToken);
if (!defined('ZAPI_CLIENT_TOKEN')) define('ZAPI_CLIENT_TOKEN', $zapiClientToken);
define('ZAPI_BASE_URL', !empty($zapiInstanceId) && !empty($zapiInstanceToken)
    ? 'https://api.z-api.io/instances/' . ZAPI_INSTANCE_ID . '/token/' . ZAPI_INSTANCE_TOKEN
    : '');

// Retry configuration
define('ZAPI_MAX_RETRIES', 3);
define('ZAPI_RETRY_BASE_DELAY', 1); // segundos

/**
 * Send WhatsApp with automatic retry and exponential backoff
 */
function sendWhatsAppWithRetry(string $phone, string $message, int $maxRetries = ZAPI_MAX_RETRIES): array {
    $attempt = 0;
    $lastError = '';

    while ($attempt < $maxRetries) {
        $result = sendWhatsAppInternal($phone, $message);

        if ($result['success']) {
            if ($attempt > 0) {
                error_log("[zapi] Success after " . ($attempt + 1) . " attempts for phone ending " . substr($phone, -4));
            }
            return $result;
        }

        $lastError = $result['message'] ?? 'Unknown error';
        $attempt++;

        if ($attempt < $maxRetries) {
            $delay = ZAPI_RETRY_BASE_DELAY * pow(2, $attempt - 1); // 1s, 2s, 4s
            error_log("[zapi] Retry $attempt/$maxRetries in {$delay}s for phone ending " . substr($phone, -4));
            sleep($delay);
        }
    }

    error_log("[zapi] All $maxRetries retries failed for phone ending " . substr($phone, -4) . ": $lastError");
    return ['success' => false, 'message' => "Failed after $maxRetries attempts: $lastError"];
}

/**
 * Send WhatsApp message (with automatic retry)
 */
function sendWhatsApp(string $phone, string $message): array {
    return sendWhatsAppWithRetry($phone, $message);
}

/**
 * Internal: Envia mensagem de texto via WhatsApp (single attempt)
 * @param string $phone Telefone com DDD (ex: 11999999999 ou +5511999999999)
 * @param string $message Texto da mensagem
 * @return array ['success' => bool, 'message' => string]
 */
function sendWhatsAppInternal(string $phone, string $message): array {
    // Check if Z-API is configured
    if (empty(ZAPI_BASE_URL) || empty(ZAPI_CLIENT_TOKEN)) {
        error_log("[zapi] Z-API not configured - skipping message send");
        return ['success' => false, 'message' => 'Z-API nao configurado'];
    }

    $phone = formatPhoneForZapi($phone);
    if (!$phone) {
        return ['success' => false, 'message' => 'Telefone invalido'];
    }

    $payload = json_encode([
        'phone' => $phone,
        'message' => $message
    ]);

    $ch = curl_init(ZAPI_BASE_URL . '/send-text');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Client-Token: ' . ZAPI_CLIENT_TOKEN
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15
    ]);

    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        error_log("[zapi] cURL error: $error");
        return ['success' => false, 'message' => "Erro conexao: $error"];
    }

    $data = json_decode($result, true);

    // Z-API retorna zaapId ou messageId ou id quando sucesso
    $messageId = $data['zaapId'] ?? $data['messageId'] ?? $data['id'] ?? null;
    if ($httpCode >= 200 && $httpCode < 300 && $messageId) {
        error_log("[zapi] Mensagem enviada para " . substr($phone, 0, 4) . "****" . substr($phone, -2) . " | ID: $messageId");
        return ['success' => true, 'message' => 'Enviado', 'messageId' => $messageId];
    }

    $errMsg = $data['error'] ?? $data['message'] ?? "HTTP $httpCode";
    error_log("[zapi] Falha enviar para " . substr($phone, 0, 4) . "****" . substr($phone, -2) . ": $errMsg");
    return ['success' => false, 'message' => $errMsg];
}

/**
 * Envia mensagem com botoes interativos (ate 3 botoes).
 *
 * Z-API endpoint: POST /send-button-list
 * Payload:
 *   { "phone": "55...", "message": "text",
 *     "buttonList": { "buttons": [{"id": "btn_1", "label": "Button Text"}, ...] } }
 *
 * @param string $phone  Telefone com DDD
 * @param string $message Texto da mensagem
 * @param array  $buttons Array de botoes: [['id' => 'btn_x', 'label' => 'Text'], ...]
 * @return array ['success' => bool, 'message' => string]
 */
function sendWhatsAppButtons(string $phone, string $message, array $buttons): array {
    if (empty(ZAPI_BASE_URL) || empty(ZAPI_CLIENT_TOKEN)) {
        return ['success' => false, 'message' => 'Z-API nao configurado'];
    }

    $phone = formatPhoneForZapi($phone);
    if (!$phone) return ['success' => false, 'message' => 'Telefone invalido'];

    // Enforce WhatsApp limit of 3 buttons
    $buttons = array_slice($buttons, 0, 3);

    $buttonList = [];
    foreach ($buttons as $btn) {
        $buttonList[] = [
            'id'    => $btn['id'] ?? uniqid('btn_'),
            'label' => mb_substr($btn['label'] ?? '', 0, 20), // WhatsApp 20-char limit
        ];
    }

    $payload = json_encode([
        'phone'      => $phone,
        'message'    => $message,
        'buttonList' => [
            'buttons' => $buttonList,
        ],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init(ZAPI_BASE_URL . '/send-button-list');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Client-Token: ' . ZAPI_CLIENT_TOKEN
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15
    ]);

    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        error_log("[zapi] cURL error (buttons): $error");
        return ['success' => false, 'message' => "Erro conexao: $error"];
    }

    $data = json_decode($result, true);
    $messageId = $data['zaapId'] ?? $data['messageId'] ?? $data['id'] ?? null;
    $ok = $httpCode >= 200 && $httpCode < 300 && $messageId;

    if ($ok) {
        error_log("[zapi] Buttons enviados para " . substr($phone, 0, 4) . "****" . substr($phone, -2) . " | ID: $messageId");
    } else {
        error_log("[zapi] Falha enviar buttons para " . substr($phone, 0, 4) . "****" . substr($phone, -2) . ": " . ($data['error'] ?? "HTTP $httpCode"));
    }

    return ['success' => $ok, 'message' => $ok ? 'Enviado' : ($data['error'] ?? $data['message'] ?? "HTTP $httpCode")];
}

/**
 * Envia mensagem com lista de opcoes (menu com secoes).
 *
 * Z-API endpoint: POST /send-option-list
 * Payload:
 *   { "phone": "55...", "message": "text",
 *     "optionList": {
 *       "title": "Menu Title",
 *       "buttonLabel": "Ver opcoes",
 *       "options": [
 *         { "title": "Section", "rows": [{"title": "Item", "description": "desc", "rowId": "id"}, ...] }
 *       ]
 *     }
 *   }
 *
 * @param string $phone        Telefone com DDD
 * @param string $message      Texto da mensagem
 * @param string $buttonLabel  Label do botao que abre a lista
 * @param array  $sections     Array de secoes: [['title' => 'Section', 'rows' => [['title' => 'Item', 'description' => 'desc', 'rowId' => 'id'], ...]], ...]
 * @return array ['success' => bool, 'message' => string]
 */
function sendWhatsAppList(string $phone, string $message, string $buttonLabel, array $sections): array {
    if (empty(ZAPI_BASE_URL) || empty(ZAPI_CLIENT_TOKEN)) {
        return ['success' => false, 'message' => 'Z-API nao configurado'];
    }

    $phone = formatPhoneForZapi($phone);
    if (!$phone) return ['success' => false, 'message' => 'Telefone invalido'];

    // Build options array with WhatsApp limits
    $options = [];
    foreach ($sections as $section) {
        $rows = [];
        foreach (($section['rows'] ?? []) as $row) {
            $rows[] = [
                'title'       => mb_substr($row['title'] ?? '', 0, 24),       // WhatsApp 24-char limit
                'description' => mb_substr($row['description'] ?? '', 0, 72), // WhatsApp 72-char limit
                'rowId'       => $row['rowId'] ?? uniqid('row_'),
            ];
        }
        if (!empty($rows)) {
            $options[] = [
                'title' => mb_substr($section['title'] ?? '', 0, 24),
                'rows'  => array_slice($rows, 0, 10), // WhatsApp max 10 rows per section
            ];
        }
    }

    if (empty($options)) {
        return ['success' => false, 'message' => 'Nenhuma opcao fornecida'];
    }

    $payload = json_encode([
        'phone'      => $phone,
        'message'    => $message,
        'optionList' => [
            'title'       => mb_substr($buttonLabel, 0, 20),
            'buttonLabel' => mb_substr($buttonLabel, 0, 20),
            'options'     => array_slice($options, 0, 10), // WhatsApp max 10 sections
        ],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init(ZAPI_BASE_URL . '/send-option-list');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Client-Token: ' . ZAPI_CLIENT_TOKEN
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15
    ]);

    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        error_log("[zapi] cURL error (list): $error");
        return ['success' => false, 'message' => "Erro conexao: $error"];
    }

    $data = json_decode($result, true);
    $messageId = $data['zaapId'] ?? $data['messageId'] ?? $data['id'] ?? null;
    $ok = $httpCode >= 200 && $httpCode < 300 && $messageId;

    if ($ok) {
        error_log("[zapi] List enviada para " . substr($phone, 0, 4) . "****" . substr($phone, -2) . " | ID: $messageId");
    } else {
        error_log("[zapi] Falha enviar list para " . substr($phone, 0, 4) . "****" . substr($phone, -2) . ": " . ($data['error'] ?? "HTTP $httpCode"));
    }

    return ['success' => $ok, 'message' => $ok ? 'Enviado' : ($data['error'] ?? $data['message'] ?? "HTTP $httpCode")];
}

/**
 * Envia imagem via WhatsApp
 * @param string $phone Telefone com DDD
 * @param string $imageUrl URL da imagem
 * @param string $caption Legenda da imagem
 * @return array ['success' => bool, 'message' => string]
 */
function sendWhatsAppImage(string $phone, string $imageUrl, string $caption = ''): array {
    if (empty(ZAPI_BASE_URL) || empty(ZAPI_CLIENT_TOKEN)) {
        return ['success' => false, 'message' => 'Z-API nao configurado'];
    }

    $phone = formatPhoneForZapi($phone);
    if (!$phone) return ['success' => false, 'message' => 'Telefone invalido'];

    $payload = json_encode([
        'phone' => $phone,
        'image' => $imageUrl,
        'caption' => $caption
    ]);

    $ch = curl_init(ZAPI_BASE_URL . '/send-image');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Client-Token: ' . ZAPI_CLIENT_TOKEN
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15
    ]);

    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        error_log("[zapi] cURL error (image): $error");
        return ['success' => false, 'message' => "Erro conexao: $error"];
    }

    $data = json_decode($result, true);
    $ok = $httpCode >= 200 && $httpCode < 300;

    if ($ok) {
        error_log("[zapi] Imagem enviada para " . substr($phone, 0, 4) . "****" . substr($phone, -2));
    } else {
        error_log("[zapi] Falha enviar imagem para " . substr($phone, 0, 4) . "****" . substr($phone, -2) . ": " . ($data['error'] ?? "HTTP $httpCode"));
    }

    return ['success' => $ok, 'message' => $ok ? 'Enviado' : ($data['error'] ?? 'Erro')];
}

// Alias para compatibilidade
if (!function_exists('sendWhatsAppText')) {
    function sendWhatsAppText(string $phone, string $message): array {
        return sendWhatsApp($phone, $message);
    }
}

/**
 * Formata telefone para formato Z-API (codigo pais + numero)
 * Aceita numeros brasileiros e internacionais
 * O frontend já envia com código do país, então não adiciona 55 automaticamente
 */
function formatPhoneForZapi(string $phone): string {
    // Remover tudo que nao e digito
    $phone = preg_replace('/\D/', '', $phone);

    // Aceita numeros de 10 a 15 digitos (com codigo do pais)
    // Frontend já envia: código país + número local
    if (strlen($phone) < 10 || strlen($phone) > 15) {
        return '';
    }

    return $phone;
}

/**
 * Templates de mensagens para pedidos
 */
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

function whatsappOTP(string $phone, string $code, string $app = 'SuperBora'): array {
    $msg = "🔐 *{$app} - Codigo de Verificacao*\n\n"
         . "Seu codigo: *{$code}*\n\n"
         . "Valido por 5 minutos. Nao compartilhe este codigo.";
    return sendWhatsApp($phone, $msg);
}

/**
 * Notifica parceiro via WhatsApp sobre novo pedido
 */
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
