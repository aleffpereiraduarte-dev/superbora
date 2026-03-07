<?php
/**
 * POST /api/mercado/webhooks/voice-sms.php
 * Internal endpoint — sends order confirmation SMS from voice server
 * Protected by X-Internal-Key header
 */

// Load env
if (file_exists(__DIR__ . '/../../../.env')) {
    $envFile = file(__DIR__ . '/../../../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($envFile as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $_ENV[trim($key)] = trim(trim($value), '"\'');
        }
    }
}

header('Content-Type: application/json');

// Validate internal key
$internalKey = $_SERVER['HTTP_X_INTERNAL_KEY'] ?? '';
if ($internalKey !== 'superbora-voice-2026') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../helpers/twilio-sms.php';

$phone = preg_replace('/[^0-9+]/', '', $_POST['phone'] ?? '');
$orderNumber = preg_replace('/[^a-zA-Z0-9\-_#]/', '', $_POST['order_number'] ?? '');
$storeName = strip_tags($_POST['store_name'] ?? '');
$items = strip_tags($_POST['items'] ?? '');
$total = preg_replace('/[^0-9.,]/', '', $_POST['total'] ?? '0');

if (empty($phone) || empty($orderNumber)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing phone or order_number']);
    exit;
}

$smsBody = "SuperBora - Pedido Confirmado!\n\n"
    . "Pedido: {$orderNumber}\n"
    . "Loja: {$storeName}\n"
    . "Itens: {$items}\n"
    . "Total: R\${$total}\n\n"
    . "Acompanhe: superbora.com.br/tracking/{$orderNumber}";

try {
    $result = sendSMS($phone, $smsBody);
    echo json_encode(['success' => true, 'result' => $result], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
} catch (Exception $e) {
    error_log("[voice-sms] Failed: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Failed to send SMS'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}
