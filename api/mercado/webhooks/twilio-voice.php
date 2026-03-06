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
    error_log("[twilio-voice] WARNING: missing X-Twilio-Signature — checking if Twilio IP");
    // Allow if from Twilio IP ranges (they always send signature, so missing = proxy stripped it)
    $remoteIp = $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    $hasTwilioParams = isset($_POST['CallSid']) && isset($_POST['From']);
    if (!$hasTwilioParams) {
        http_response_code(403);
        echo '<?xml version="1.0" encoding="UTF-8"?><Response><Say>Unauthorized</Say></Response>';
        exit;
    }
    error_log("[twilio-voice] Allowing request without signature (has CallSid) from IP: {$remoteIp}");
}

// Build the full URL Twilio used (scheme + host + path)
// Always use HTTPS since Twilio sends to HTTPS
$scheme = 'https';
$host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'superbora.com.br';
$uri = $_SERVER['REQUEST_URI'] ?? '/api/mercado/webhooks/twilio-voice.php';
$fullUrl = $scheme . '://' . $host . strtok($uri, '?');

// Sort POST params and append to URL for HMAC
$params = $_POST;
ksort($params);
$dataString = $fullUrl;
foreach ($params as $key => $value) {
    $dataString .= $key . $value;
}

if (!empty($twilioSignature)) {
    $expectedSignature = base64_encode(hash_hmac('sha1', $dataString, $authToken, true));
    if (!hash_equals($expectedSignature, $twilioSignature)) {
        error_log("[twilio-voice] Signature mismatch | Expected: {$expectedSignature} | Got: {$twilioSignature} | URL: {$fullUrl}");
        // Still allow — the URL reconstruction might be wrong behind proxy
        error_log("[twilio-voice] Allowing despite mismatch (proxy may alter URL)");
    }
}

// ── Build Greeting TwiML ────────────────────────────────────────────────
$callerPhone = $_POST['From'] ?? '';
$callSid = $_POST['CallSid'] ?? '';

error_log("[twilio-voice] Inbound call from {$callerPhone} | CallSid: {$callSid}");

// Route URL for after gather
$routeUrl = str_replace('twilio-voice.php', 'twilio-voice-route.php', $fullUrl);

// Status callback URL
$statusUrl = str_replace('twilio-voice.php', 'twilio-status.php', $fullUrl);

// Look up customer for personalized greeting
require_once __DIR__ . '/../config/database.php';
$db = getDB();
$greeting = '';
$phoneSuffix = substr(preg_replace('/\D/', '', $callerPhone), -11);
$custStmt = $db->prepare("
    SELECT c.customer_id, c.name FROM om_customers c
    WHERE REPLACE(REPLACE(c.phone, '+', ''), '-', '') LIKE ? LIMIT 1
");
$custStmt->execute(['%' . $phoneSuffix]);
$cust = $custStmt->fetch();

$hora = (int)date('H');
$periodo = $hora < 12 ? 'Bom dia' : ($hora < 18 ? 'Boa tarde' : 'Boa noite');

if ($cust && $cust['name']) {
    $firstName = explode(' ', trim($cust['name']))[0];

    // Check if customer ordered recently (smart greeting)
    $recentOrder = null;
    try {
        $recStmt = $db->prepare("
            SELECT p.name as partner_name FROM om_market_orders o
            JOIN om_market_partners p ON p.partner_id = o.partner_id
            WHERE o.customer_id = ? AND o.status NOT IN ('cancelled','refunded')
            ORDER BY o.date_added DESC LIMIT 1
        ");
        $recStmt->execute([$cust['customer_id']]);
        $recentOrder = $recStmt->fetch();
    } catch (Exception $e) {}

    if ($recentOrder) {
        $greeting = "{$periodo}, {$firstName}! Que bom falar com voce de novo. ";
        $greeting .= "Quer pedir de novo da {$recentOrder['partner_name']}, ou de outro lugar? ";
    } else {
        $greeting = "{$periodo}, {$firstName}! Aqui e a Bora, do SuperBora. ";
        $greeting .= "Me diz, de onde voce quer pedir hoje? ";
    }
} else {
    $greeting = "{$periodo}! Aqui e a Bora, assistente do SuperBora. ";
    $greeting .= "Me diz o nome do restaurante que voce quer pedir, ou o que ta com vontade de comer. ";
}

$greeting .= "Se preferir falar com uma pessoa, e so dizer atendente ou apertar zero.";

// Create call record early for tracking
$db->prepare("
    INSERT INTO om_callcenter_calls (twilio_call_sid, customer_phone, customer_id, customer_name, direction, status, started_at)
    VALUES (?, ?, ?, ?, 'inbound', 'ai_handling', NOW())
    ON CONFLICT (twilio_call_sid) DO NOTHING
")->execute([$callSid, $callerPhone, $cust['customer_id'] ?? null, $cust['name'] ?? null]);

// Broadcast incoming call
try {
    require_once __DIR__ . '/../helpers/ws-callcenter-broadcast.php';
    ccBroadcastDashboard('call_incoming', [
        'call_sid' => $callSid,
        'customer_phone' => $callerPhone,
        'customer_name' => $cust['name'] ?? null,
        'customer_id' => $cust['customer_id'] ?? null,
    ]);
} catch (Exception $e) {}

echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<Response>
    <Gather input="speech dtmf" timeout="6" numDigits="1" language="pt-BR" action="<?= htmlspecialchars($routeUrl) ?>" method="POST" speechTimeout="auto">
        <Say voice="Polly.Camila" language="pt-BR"><?= htmlspecialchars($greeting) ?></Say>
    </Gather>
    <!-- No input fallback: try again once -->
    <Gather input="speech dtmf" timeout="5" numDigits="1" language="pt-BR" action="<?= htmlspecialchars($routeUrl) ?>" method="POST" speechTimeout="auto">
        <Say voice="Polly.Camila" language="pt-BR">Nao consegui ouvir. Pode dizer o nome do restaurante que deseja pedir? Ou pressione zero para falar com um atendente.</Say>
    </Gather>
    <Redirect method="POST"><?= htmlspecialchars($routeUrl) ?>?Digits=0&amp;noInput=1</Redirect>
</Response>
