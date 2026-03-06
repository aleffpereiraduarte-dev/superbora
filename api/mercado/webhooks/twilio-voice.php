<?php
/**
 * POST /api/mercado/webhooks/twilio-voice.php
 * Twilio Voice IVR Entry Point — inbound call webhook
 *
 * Returns TwiML for PT-BR greeting with speech + DTMF gather.
 * Validates X-Twilio-Signature HMAC before processing.
 */

// Load env manually (webhooks bypass rate limiting)
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

header('Content-Type: text/xml; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo '<?xml version="1.0" encoding="UTF-8"?><Response><Say>Method not allowed</Say></Response>';
    exit;
}

// ── Validate Twilio Signature ───────────────────────────────────────────
$authToken = $_ENV['TWILIO_TOKEN'] ?? getenv('TWILIO_TOKEN') ?: '';
$twilioSignature = $_SERVER['HTTP_X_TWILIO_SIGNATURE'] ?? '';

if (empty($authToken)) {
    error_log("[twilio-voice] CRITICAL: TWILIO_TOKEN not configured");
    http_response_code(500);
    echo '<?xml version="1.0" encoding="UTF-8"?><Response><Say>Service unavailable</Say></Response>';
    exit;
}

if (empty($twilioSignature)) {
    error_log("[twilio-voice] Rejected: missing X-Twilio-Signature");
    http_response_code(403);
    echo '<?xml version="1.0" encoding="UTF-8"?><Response><Say>Unauthorized</Say></Response>';
    exit;
}

// Build the full URL Twilio used (scheme + host + path)
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
$uri = $_SERVER['REQUEST_URI'] ?? '/api/mercado/webhooks/twilio-voice.php';
$fullUrl = $scheme . '://' . $host . strtok($uri, '?');

// Sort POST params and append to URL for HMAC
$params = $_POST;
ksort($params);
$dataString = $fullUrl;
foreach ($params as $key => $value) {
    $dataString .= $key . $value;
}

$expectedSignature = base64_encode(hash_hmac('sha1', $dataString, $authToken, true));
if (!hash_equals($expectedSignature, $twilioSignature)) {
    error_log("[twilio-voice] Rejected: invalid signature");
    http_response_code(403);
    echo '<?xml version="1.0" encoding="UTF-8"?><Response><Say>Unauthorized</Say></Response>';
    exit;
}

// ── Build Greeting TwiML ────────────────────────────────────────────────
$callerPhone = $_POST['From'] ?? '';
$callSid = $_POST['CallSid'] ?? '';

error_log("[twilio-voice] Inbound call from {$callerPhone} | CallSid: {$callSid}");

// Route URL for after gather
$routeUrl = str_replace('twilio-voice.php', 'twilio-voice-route.php', $fullUrl);

// Status callback URL
$statusUrl = str_replace('twilio-voice.php', 'twilio-status.php', $fullUrl);

echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<Response>
    <Gather input="speech dtmf" timeout="5" numDigits="1" language="pt-BR" action="<?= htmlspecialchars($routeUrl) ?>" method="POST" speechTimeout="auto">
        <Say voice="Polly.Camila" language="pt-BR">Ola! Bem-vindo ao SuperBora. Para fazer um pedido, diga o nome do restaurante ou produto desejado. Para falar com um atendente, pressione zero ou diga atendente.</Say>
    </Gather>
    <!-- No input fallback: route to agent -->
    <Redirect method="POST"><?= htmlspecialchars($routeUrl) ?>?Digits=0&amp;noInput=1</Redirect>
</Response>
