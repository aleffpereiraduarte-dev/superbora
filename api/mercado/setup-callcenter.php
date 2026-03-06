<?php
/**
 * ONE-TIME SETUP: Call Center AI — runs migrations + configures Z-API webhook.
 * DELETE THIS FILE after running!
 *
 * Usage: curl https://superbora.com.br/api/mercado/setup-callcenter.php?key=SETUP_KEY_2026
 */

header('Content-Type: text/plain; charset=utf-8');

// Security: require key
$key = $_GET['key'] ?? $_POST['key'] ?? '';
if ($key !== 'abc123') {
    http_response_code(403);
    die("Forbidden. Key received: [" . substr($key, 0, 5) . "...] len=" . strlen($key) . "\n");
}

echo "=== SuperBora Call Center Setup ===\n\n";

// 1. Database migrations
echo "--- Step 1: Running SQL migrations ---\n";

try {
    require_once __DIR__ . '/config/database.php';
    $db = getDB();
    echo "DB connected OK\n";

    // Migration 043
    $sql043 = file_get_contents(__DIR__ . '/sql/043_callcenter_complete.sql');
    if ($sql043) {
        $db->exec($sql043);
        echo "043_callcenter_complete.sql: OK\n";
    } else {
        echo "043_callcenter_complete.sql: FILE NOT FOUND\n";
    }

    // Migration 044
    $sql044 = file_get_contents(__DIR__ . '/sql/044_callcenter_outbound_safeguards.sql');
    if ($sql044) {
        $db->exec($sql044);
        echo "044_callcenter_outbound_safeguards.sql: OK\n";
    } else {
        echo "044_callcenter_outbound_safeguards.sql: FILE NOT FOUND\n";
    }

} catch (Exception $e) {
    echo "DB ERROR: " . $e->getMessage() . "\n";
}

// 2. Configure Z-API webhooks
echo "\n--- Step 2: Configuring Z-API webhooks ---\n";

$envPath = $_SERVER['DOCUMENT_ROOT'] . '/.env';
if (file_exists($envPath)) {
    $envFile = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($envFile as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $_ENV[trim($key)] = trim(trim($value), '"\'');
        }
    }
}

$instanceId = $_ENV['ZAPI_INSTANCE_ID'] ?? '';
$instanceToken = $_ENV['ZAPI_INSTANCE_TOKEN'] ?? '';
$clientToken = $_ENV['ZAPI_CLIENT_TOKEN'] ?? '';

if (empty($instanceId) || empty($instanceToken) || empty($clientToken)) {
    echo "Z-API credentials not found in .env!\n";
} else {
    $baseUrl = "https://api.z-api.io/instances/{$instanceId}/token/{$instanceToken}";
    $webhookUrl = "https://superbora.com.br/api/mercado/webhooks/whatsapp-ai.php";

    // Set received messages webhook
    $ch = curl_init("{$baseUrl}/update-webhook-received");
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS => json_encode(['value' => $webhookUrl]),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Client-Token: ' . $clientToken,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo "Webhook received: HTTP {$httpCode} — {$result}\n";

    // Set message status webhook
    $ch = curl_init("{$baseUrl}/update-webhook-message-status");
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS => json_encode(['value' => $webhookUrl]),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Client-Token: ' . $clientToken,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo "Webhook status: HTTP {$httpCode} — {$result}\n";

    // Verify webhooks
    $ch = curl_init("{$baseUrl}/webhooks");
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            'Client-Token: ' . $clientToken,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo "\nWebhook config: HTTP {$httpCode}\n{$result}\n";
}

// 3. Quick syntax check
echo "\n--- Step 3: PHP file checks ---\n";
$files = [
    'webhooks/whatsapp-ai.php',
    'webhooks/twilio-voice-ai.php',
    'webhooks/twilio-voice-outbound.php',
    'helpers/ai-safeguards.php',
    'helpers/outbound-calls.php',
    'admin/callcenter/outbound-calls.php',
];
foreach ($files as $f) {
    $path = __DIR__ . '/' . $f;
    if (file_exists($path)) {
        $size = filesize($path);
        echo "{$f}: EXISTS ({$size} bytes)\n";
    } else {
        echo "{$f}: MISSING!\n";
    }
}

// 4. Test sending a WhatsApp message
echo "\n--- Step 4: Test WhatsApp send ---\n";
require_once __DIR__ . '/helpers/zapi-whatsapp.php';
$testResult = sendWhatsApp('5533999652818', 'Oi! Sou a Bora, assistente virtual da SuperBora. Como posso te ajudar? 😊');
echo "Test send: " . ($testResult['success'] ? 'OK' : 'FAILED') . " — " . ($testResult['message'] ?? '') . "\n";

echo "\n=== Setup complete! DELETE this file now! ===\n";
