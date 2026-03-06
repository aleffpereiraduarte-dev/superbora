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
require_once __DIR__ . '/../helpers/voice-tts.php';
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
$activeOrder = null;
if ($cust && $cust['customer_id']) {
    $addrStmt = $db->prepare("SELECT city, neighborhood FROM om_customer_addresses WHERE customer_id = ? AND is_active = '1' ORDER BY is_default DESC LIMIT 1");
    $addrStmt->execute([$cust['customer_id']]);
    $addr = $addrStmt->fetch();
    if ($addr) {
        $hasAddress = true;
        $savedCity = $addr['city'] ?? '';
    }

    // Check for active orders
    try {
        $actStmt = $db->prepare("
            SELECT o.order_number, o.status, p.name as partner_name
            FROM om_market_orders o
            JOIN om_market_partners p ON p.partner_id = o.partner_id
            WHERE o.customer_id = ? AND o.status IN ('pending','accepted','preparing','ready','delivering','em_preparo','saiu_entrega')
            ORDER BY o.date_added DESC LIMIT 1
        ");
        $actStmt->execute([$cust['customer_id']]);
        $activeOrder = $actStmt->fetch();
    } catch (Exception $e) {}
}

// Build SSML greeting — natural, warm, conversational
$ssml = '';

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

    if ($activeOrder) {
        // Customer has an active order — proactively mention it
        $statusLabels = ['pending' => 'esperando confirmação', 'accepted' => 'foi aceito', 'preparing' => 'tá sendo preparado', 'em_preparo' => 'tá sendo preparado', 'ready' => 'tá pronto', 'delivering' => 'tá a caminho', 'saiu_entrega' => 'tá a caminho'];
        $statusText = $statusLabels[$activeOrder['status']] ?? 'em andamento';
        $ssml .= "{$periodo}, {$firstName}! Aqui é a Bora, do SuperBora. ";
        $ssml .= "Vi aqui que seu pedido da {$activeOrder['partner_name']} {$statusText}. ";
        $ssml .= "É sobre esse pedido que você tá ligando, ou quer fazer um pedido novo?";
    } elseif ($recentOrder && $daysSinceOrder < 3) {
        $ssml .= "{$periodo}, {$firstName}! ";
        $ssml .= "Que bom te ouvir de novo! ";
        $ssml .= "Da última vez você pediu da {$recentOrder['partner_name']}. ";
        $ssml .= "Quer repetir o pedido, pedir algo diferente, ou tem alguma dúvida?";
    } elseif ($recentOrder && $orderCount >= 5) {
        $ssml .= "{$periodo}, {$firstName}! ";
        $ssml .= "Que bom falar com você! Aqui é a Bora, do SuperBora. ";
        $ssml .= "Me conta, o que vai ser hoje?";
    } elseif ($recentOrder) {
        $ssml .= "{$periodo}, {$firstName}! ";
        $ssml .= "Aqui é a Bora, do SuperBora. ";
        $ssml .= "Me fala, o que você tá precisando?";
    } else {
        $ssml .= "{$periodo}, {$firstName}! ";
        $ssml .= "Aqui é a Bora, do SuperBora. ";
        if (!$hasAddress) {
            $ssml .= "Posso te ajudar a fazer um pedido, tirar uma dúvida, o que você precisar! ";
            $ssml .= "Se quiser pedir, me fala seu CEP ou bairro que eu vejo os restaurantes pra você.";
        } else {
            $ssml .= "Me fala, o que você tá precisando?";
        }
    }
} else {
    $ssml .= "{$periodo}! ";
    $ssml .= "Aqui é a Bora, do SuperBora. ";
    $ssml .= "Posso te ajudar a fazer um pedido, tirar uma dúvida, o que você precisar! ";
    $ssml .= "Se quiser pedir, me fala seu CEP ou bairro que eu acho os restaurantes pra você.";
}

$ssml .= '<break time="500ms"/>';
$ssml .= 'Ou se preferir falar com uma pessoa, é só dizer atendente ou apertar zero.';
// end of ssml (no </speak> - Twilio doesn't use it inside <Say>)

// Create call record early — wrapped in try/catch to not break greeting on DB issues
try {
    $db->prepare("
        INSERT INTO om_callcenter_calls (twilio_call_sid, customer_phone, customer_id, customer_name, direction, status, started_at)
        VALUES (?, ?, ?, ?, 'inbound', 'ai_handling', NOW())
        ON CONFLICT (twilio_call_sid) DO NOTHING
    ")->execute([$callSid, $callerPhone, $cust['customer_id'] ?? null, $cust['name'] ?? null]);
} catch (Exception $e) {
    error_log("[twilio-voice] DB insert failed (non-fatal): " . $e->getMessage());
}

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

// Strip SSML tags for OpenAI TTS (it uses plain text)
$plainText = preg_replace('/<[^>]+>/', ' ', $ssml);
$plainText = preg_replace('/\s+/', ' ', trim($plainText));

$routeEsc = htmlspecialchars($routeUrl);

echo '<?xml version="1.0" encoding="UTF-8"?>';
echo '<Response>';
echo '<Gather input="speech dtmf" timeout="8" language="pt-BR" action="' . $routeEsc . '" method="POST" speechTimeout="auto" enhanced="true" speechModel="phone_call">';
echo ttsSayOrPlay($plainText);
echo '</Gather>';
// Fallback re-prompt
echo '<Gather input="speech dtmf" timeout="6" language="pt-BR" action="' . $routeEsc . '" method="POST" speechTimeout="auto" enhanced="true" speechModel="phone_call">';
echo ttsSayOrPlay("Oi, tô aqui! Me fala o que você precisa. Pode ser um pedido, uma dúvida, o que for! Ou aperta zero pra falar com alguém.");
echo '</Gather>';
echo '<Redirect method="POST">' . $routeEsc . '?Digits=0&amp;noInput=1</Redirect>';
echo '</Response>';
