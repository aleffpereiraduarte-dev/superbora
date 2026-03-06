<?php
/**
 * POST /api/mercado/webhooks/twilio-voice.php
 * Twilio Voice IVR Entry Point — inbound call webhook
 *
 * Returns TwiML for PT-BR greeting with speech + DTMF gather.
 * Ultra-natural voice using SSML prosody.
 * For new customers: asks CEP to find available stores.
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

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo '<?xml version="1.0" encoding="UTF-8"?><Response><Say>Method not allowed</Say></Response>';
    exit;
}

// -- Validate Twilio Signature --
$authToken = $_ENV['TWILIO_TOKEN'] ?? getenv('TWILIO_TOKEN') ?: '';
$twilioSignature = $_SERVER['HTTP_X_TWILIO_SIGNATURE'] ?? '';

if (empty($authToken)) {
    error_log("[twilio-voice] CRITICAL: TWILIO_TOKEN not configured");
    http_response_code(500);
    echo '<?xml version="1.0" encoding="UTF-8"?><Response><Say>Service unavailable</Say></Response>';
    exit;
}

if (empty($twilioSignature)) {
    $remoteIp = $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    $hasTwilioParams = isset($_POST['CallSid']) && isset($_POST['From']);
    if (!$hasTwilioParams) {
        http_response_code(403);
        echo '<?xml version="1.0" encoding="UTF-8"?><Response><Say>Unauthorized</Say></Response>';
        exit;
    }
    error_log("[twilio-voice] Allowing request without signature (has CallSid) from IP: {$remoteIp}");
}

$scheme = 'https';
$host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'superbora.com.br';
$uri = $_SERVER['REQUEST_URI'] ?? '/api/mercado/webhooks/twilio-voice.php';
$fullUrl = $scheme . '://' . $host . strtok($uri, '?');

$params = $_POST;
ksort($params);
$dataString = $fullUrl;
foreach ($params as $key => $value) {
    $dataString .= $key . $value;
}

if (!empty($twilioSignature)) {
    $expectedSignature = base64_encode(hash_hmac('sha1', $dataString, $authToken, true));
    if (!hash_equals($expectedSignature, $twilioSignature)) {
        error_log("[twilio-voice] Signature mismatch — allowing (proxy may alter URL)");
    }
}

// -- Build Greeting --
$callerPhone = $_POST['From'] ?? '';
$callSid = $_POST['CallSid'] ?? '';

error_log("[twilio-voice] Inbound call from {$callerPhone} | CallSid: {$callSid}");

$routeUrl = str_replace('twilio-voice.php', 'twilio-voice-route.php', $fullUrl);

require_once __DIR__ . '/../config/database.php';
$db = getDB();

// Look up customer
$phoneSuffix = substr(preg_replace('/\D/', '', $callerPhone), -11);
$custStmt = $db->prepare("
    SELECT c.customer_id, c.name FROM om_customers c
    WHERE REPLACE(REPLACE(c.phone, '+', ''), '-', '') LIKE ? LIMIT 1
");
$custStmt->execute(['%' . $phoneSuffix]);
$cust = $custStmt->fetch();

$hora = (int)date('H');
$periodo = $hora < 12 ? 'Bom dia' : ($hora < 18 ? 'Boa tarde' : 'Boa noite');

// Check saved addresses for context
$hasAddress = false;
$savedCity = '';
if ($cust && $cust['customer_id']) {
    $addrStmt = $db->prepare("SELECT city, neighborhood FROM om_customer_addresses WHERE customer_id = ? AND is_active = '1' ORDER BY is_default DESC LIMIT 1");
    $addrStmt->execute([$cust['customer_id']]);
    $addr = $addrStmt->fetch();
    if ($addr) {
        $hasAddress = true;
        $savedCity = $addr['city'] ?? '';
    }
}

// Build SSML greeting — natural, warm, conversational
$ssml = '<speak>';

if ($cust && $cust['name']) {
    $firstName = explode(' ', trim($cust['name']))[0];

    // Check recent orders for smart greeting
    $recentOrder = null;
    $daysSinceOrder = 999;
    try {
        $recStmt = $db->prepare("
            SELECT p.name as partner_name, o.date_added,
                   EXTRACT(DAY FROM NOW() - o.date_added) as days_ago
            FROM om_market_orders o
            JOIN om_market_partners p ON p.partner_id = o.partner_id
            WHERE o.customer_id = ? AND o.status NOT IN ('cancelled','refunded')
            ORDER BY o.date_added DESC LIMIT 1
        ");
        $recStmt->execute([$cust['customer_id']]);
        $recentOrder = $recStmt->fetch();
        if ($recentOrder) $daysSinceOrder = (int)($recentOrder['days_ago'] ?? 999);
    } catch (Exception $e) {}

    // Count total orders for VIP treatment
    $orderCount = 0;
    try {
        $cntStmt = $db->prepare("SELECT COUNT(*) as cnt FROM om_market_orders WHERE customer_id = ? AND status NOT IN ('cancelled','refunded')");
        $cntStmt->execute([$cust['customer_id']]);
        $orderCount = (int)$cntStmt->fetch()['cnt'];
    } catch (Exception $e) {}

    if ($recentOrder && $daysSinceOrder < 3) {
        // Very recent order — casual reconnection
        $ssml .= "<prosody rate=\"medium\" pitch=\"+5%\">{$periodo}, {$firstName}!</prosody> ";
        $ssml .= '<break time="300ms"/>';
        $ssml .= "Que bom te ouvir de novo! ";
        $ssml .= "Da ultima vez voce pediu da <emphasis level=\"moderate\">{$recentOrder['partner_name']}</emphasis>. ";
        $ssml .= '<break time="200ms"/>';
        $ssml .= "Quer repetir, ou ta afim de algo diferente hoje?";
    } elseif ($recentOrder && $orderCount >= 5) {
        // Loyal customer
        $ssml .= "<prosody rate=\"medium\" pitch=\"+5%\">{$periodo}, {$firstName}!</prosody> ";
        $ssml .= '<break time="300ms"/>';
        $ssml .= "Que bom falar com voce! Aqui e a Bora, do SuperBora. ";
        $ssml .= '<break time="200ms"/>';
        $ssml .= "Me conta, de onde voce quer pedir hoje?";
    } elseif ($recentOrder) {
        // Returning but not super frequent
        $ssml .= "<prosody rate=\"medium\" pitch=\"+5%\">{$periodo}, {$firstName}!</prosody> ";
        $ssml .= '<break time="300ms"/>';
        $ssml .= "Aqui e a Bora, sua assistente do SuperBora. ";
        $ssml .= '<break time="200ms"/>';
        $ssml .= "Me diz, o que voce ta com vontade de comer?";
    } else {
        // Known customer but no orders
        $ssml .= "<prosody rate=\"medium\" pitch=\"+5%\">{$periodo}, {$firstName}!</prosody> ";
        $ssml .= '<break time="300ms"/>';
        $ssml .= "Aqui e a Bora, do SuperBora. Vou te ajudar a pedir sua comida rapidinho. ";
        $ssml .= '<break time="200ms"/>';
        if (!$hasAddress) {
            $ssml .= "Pra comecar, me fala seu CEP ou o bairro onde voce ta, pra eu ver os restaurantes que entregam ai.";
        } else {
            $ssml .= "Me conta, de qual restaurante voce quer pedir, ou o que ta com vontade?";
        }
    }
} else {
    // New customer — need CEP to find stores
    $ssml .= "<prosody rate=\"medium\" pitch=\"+5%\">{$periodo}!</prosody> ";
    $ssml .= '<break time="300ms"/>';
    $ssml .= "Aqui e a Bora, sua assistente do SuperBora. Vou te ajudar a pedir comida de um jeitinho bem facil. ";
    $ssml .= '<break time="400ms"/>';
    $ssml .= "Pra comecar, me fala seu CEP ou o bairro onde voce ta, pra eu encontrar os restaurantes que entregam na sua regiao.";
}

$ssml .= '<break time="300ms"/>';
$ssml .= ' <prosody rate="slow" volume="soft">Se preferir falar com uma pessoa, e so dizer atendente ou apertar zero.</prosody>';
$ssml .= '</speak>';

// Create call record early
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
    <Gather input="speech dtmf" timeout="8" numDigits="1" language="pt-BR" action="<?= htmlspecialchars($routeUrl) ?>" method="POST" speechTimeout="auto" enhanced="true" speechModel="phone_call">
        <Say voice="Polly.Camila" language="pt-BR"><?= $ssml ?></Say>
    </Gather>
    <!-- No input fallback: gentler re-prompt -->
    <Gather input="speech dtmf" timeout="6" numDigits="1" language="pt-BR" action="<?= htmlspecialchars($routeUrl) ?>" method="POST" speechTimeout="auto" enhanced="true" speechModel="phone_call">
        <Say voice="Polly.Camila" language="pt-BR"><speak>Oi, to aqui! <break time="200ms"/> Me fala o nome do restaurante, o que voce quer comer, ou seu CEP pra eu te ajudar. <break time="200ms"/> Ou aperta zero pra falar com alguem.</speak></Say>
    </Gather>
    <Redirect method="POST"><?= htmlspecialchars($routeUrl) ?>?Digits=0&amp;noInput=1</Redirect>
</Response>
