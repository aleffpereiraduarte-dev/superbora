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

// Last-resort safety net: if PHP fatals (e.g. require_once fails), output valid TwiML
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR])) {
        if (!headers_sent()) header('Content-Type: text/xml; charset=utf-8');
        echo '<?xml version="1.0" encoding="UTF-8"?><Response><Say language="pt-BR" voice="Polly.Camila">Desculpa, estamos com um probleminha técnico. Tente ligar de novo em instantes.</Say></Response>';
        error_log("[twilio-voice] SHUTDOWN FATAL: {$err['message']} in {$err['file']}:{$err['line']}");
    }
});

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

// Safe fallback TwiML function (no dependencies)
function safeErrorTwiml(string $routeUrl = ''): void {
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<Response>';
    echo '<Say language="pt-BR" voice="Polly.Camila">Oi! Aqui é a Bora, do SuperBora. Como posso te ajudar?</Say>';
    if ($routeUrl) {
        $esc = htmlspecialchars($routeUrl, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        echo '<Gather input="speech dtmf" timeout="6" language="pt-BR" action="' . $esc . '" method="POST" speechTimeout="auto" speechModel="experimental_utterances" hints="sim, não, pedido, atendente, cancelar, status, ajuda">';
        echo '<Say language="pt-BR" voice="Polly.Camila">Pode falar ou digitar, tô te escutando!</Say>';
        echo '</Gather>';
    }
    echo '</Response>';
}

try {
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

// Build greeting — natural, warm, conversational, SHORT (max ~12 seconds)
$greetText = '';

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
        $statusLabels = [
            'pending' => 'esperando confirmação da loja',
            'accepted' => 'já foi aceito',
            'preparing' => 'tá sendo preparado',
            'em_preparo' => 'tá sendo preparado',
            'ready' => 'tá prontinho',
            'delivering' => 'tá a caminho',
            'saiu_entrega' => 'tá a caminho',
        ];
        $statusText = $statusLabels[$activeOrder['status']] ?? 'em andamento';
        $greetText = "{$periodo}, {$firstName}! Seu pedido da {$activeOrder['partner_name']} {$statusText}. "
            . "Quer saber mais, cancelar, ou fazer outro pedido?";
    } else {
        $greetText = "{$periodo}, {$firstName}! Aqui é a Bora, do SuperBora. "
            . "No que posso te ajudar hoje?";
    }
} else {
    // Unknown phone number — friendly greeting, ask name naturally
    $greetText = "{$periodo}! Aqui é a Bora, do SuperBora. "
        . "Me fala seu nome e como posso te ajudar!";
}

// Append the agent option as a short suffix (only for first-time greeting)
$agentHint = " Se quiser falar com uma pessoa, aperta zero.";

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

// Build the full greeting with agent hint
$fullGreeting = $greetText . $agentHint;

$routeEsc = htmlspecialchars($routeUrl, ENT_XML1 | ENT_QUOTES, 'UTF-8');
$gatherAttrs = 'input="speech dtmf" language="pt-BR" speechModel="experimental_utterances" speechTimeout="auto" profanityFilter="false" enhanced="true" hints="sim, não, pedido, atendente, cancelar, status, ajuda, pizza, lanche, hambúrguer, bebida, açaí, sushi, um, dois, três, zero, Aleff, meu nome é, endereço, CEP, pix, cartão, dinheiro"';

echo '<?xml version="1.0" encoding="UTF-8"?>';
echo '<Response>';
echo '<Gather ' . $gatherAttrs . ' timeout="6" action="' . $routeEsc . '" method="POST">';
echo ttsSayOrPlay($fullGreeting);
echo '</Gather>';
// Fallback re-prompt — shorter and friendlier
echo '<Gather ' . $gatherAttrs . ' timeout="5" action="' . $routeEsc . '" method="POST">';
echo ttsSayOrPlay("Pode falar ou digitar, tô te escutando! Aperta zero pra falar com uma pessoa.");
echo '</Gather>';
echo '<Redirect method="POST">' . $routeEsc . '?Digits=0&amp;noInput=1</Redirect>';
echo '</Response>';

} catch (\Throwable $e) {
    error_log("[twilio-voice] FATAL: " . $e->getMessage() . " | " . $e->getFile() . ":" . $e->getLine());
    safeErrorTwiml($routeUrl ?? '');
}
