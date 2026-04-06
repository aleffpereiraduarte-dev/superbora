<?php
/**
 * POST /api/mercado/webhooks/voice-sms-verify.php
 * Internal endpoint — sends verification code via WhatsApp (preferred) or SMS (fallback)
 * Protected by X-Internal-Key header
 */

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

if (($_SERVER['HTTP_X_INTERNAL_KEY'] ?? '') !== 'superbora-voice-2026') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../helpers/twilio-whatsapp.php';

$phone = preg_replace('/[^0-9+]/', '', $_POST['phone'] ?? '');
$code = preg_replace('/\D/', '', $_POST['code'] ?? '');

if (empty($phone) || empty($code)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing phone or code']);
    exit;
}

$body = "SuperBora - Verificacao\n\nSeu codigo: *{$code}*\n\nInforme este codigo na ligacao para confirmar seu pedido.";

$result = sendWhatsAppOrSMS($phone, $body);
error_log("[voice-verify] code={$code} sent_via={$result['sent_via']} to={$phone}");
echo json_encode(['success' => $result['success'], 'sent_via' => $result['sent_via']]);
