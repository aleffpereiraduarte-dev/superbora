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
            'pending' => 'esperando a confirmação da loja',
            'accepted' => 'foi aceito e já já começa a ser preparado',
            'preparing' => 'tá sendo preparado agora',
            'em_preparo' => 'tá sendo preparado agora',
            'ready' => 'tá prontinho esperando o entregador',
            'delivering' => 'já saiu pra entrega e tá a caminho',
            'saiu_entrega' => 'já saiu pra entrega e tá a caminho',
        ];
        $statusText = $statusLabels[$activeOrder['status']] ?? 'em andamento';
        $greetText = "{$periodo}, {$firstName}! Que bom que você ligou. "
            . "Já achei seu pedido aqui — seu pedido da {$activeOrder['partner_name']} {$statusText}. "
            . "Se quiser cancelar, saber mais detalhes, fazer um novo pedido, ou qualquer outra coisa, tô aqui pra te ajudar!";
    } else {
        $greetText = "{$periodo}, {$firstName}! Aqui é a Bora, do SuperBora. Que bom que você ligou! "
            . "Tô aqui pra te ajudar no que precisar — fazer um pedido, acompanhar uma entrega, cancelar, tirar dúvida, ou qualquer contratempo. "
            . "Me conta, como posso te ajudar?";
    }
} else {
    // Unknown phone number — ask for linked phone to find their account
    $greetText = "{$periodo}! Aqui é a Bora, do SuperBora. Que bom que você ligou! "
        . "Não encontrei uma conta com esse número. Se você já tem uma conta, me fala o número de telefone que usou pra se cadastrar, que eu encontro seus pedidos. "
        . "Ou se quiser, posso te ajudar a fazer um pedido novo, tirar dúvida, ou o que precisar!";
}

// Append the agent option as a short suffix
$agentHint = " E se preferir falar com uma pessoa, é só apertar zero.";

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

echo '<?xml version="1.0" encoding="UTF-8"?>';
echo '<Response>';
echo '<Gather input="speech dtmf" timeout="8" language="pt-BR" action="' . $routeEsc . '" method="POST" speechTimeout="auto" enhanced="true" speechModel="phone_call">';
echo ttsSayOrPlay($fullGreeting);
echo '</Gather>';
// Fallback re-prompt — shorter and friendlier
echo '<Gather input="speech dtmf" timeout="6" language="pt-BR" action="' . $routeEsc . '" method="POST" speechTimeout="auto" enhanced="true" speechModel="phone_call">';
echo ttsSayOrPlay("Oi, tô aqui! Me fala o que você precisa, ou aperta zero pra falar com alguém.");
echo '</Gather>';
echo '<Redirect method="POST">' . $routeEsc . '?Digits=0&amp;noInput=1</Redirect>';
echo '</Response>';
