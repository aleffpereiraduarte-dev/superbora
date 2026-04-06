<?php
/**
 * POST /api/mercado/webhooks/voice-protocol-sms.php
 * Internal endpoint — sends protocol SMS after call ends
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

require_once __DIR__ . '/../helpers/twilio-whatsapp.php';

$phone = preg_replace('/[^0-9+]/', '', $_POST['phone'] ?? '');
$message = $_POST['message'] ?? '';

if (empty($phone) || empty($message)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing phone or message']);
    exit;
}

$result = sendWhatsAppOrSMS($phone, $message);
error_log("[voice-protocol-sms] phone={$phone} sent_via={$result['sent_via']}");
echo json_encode(['success' => $result['success'], 'sent_via' => $result['sent_via']]);
