<?php
/**
 * POST /api/mercado/webhooks/voice-payment-link.php
 * Internal endpoint — creates Stripe Checkout Session and sends SMS with payment link
 * Called by voice-server (Node.js) when customer chooses credit card payment
 * Protected by X-Internal-Key header
 */

// Load .env
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

// Load .env.stripe
$stripeEnv = __DIR__ . '/../../../.env.stripe';
$STRIPE_SK = '';
if (file_exists($stripeEnv)) {
    $lines = file($stripeEnv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($k, $v) = explode('=', $line, 2);
            if (trim($k) === 'STRIPE_SECRET_KEY') $STRIPE_SK = trim($v);
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

if (empty($STRIPE_SK) || strpos($STRIPE_SK, 'XXX') !== false) {
    http_response_code(503);
    echo json_encode(['error' => 'Stripe not configured']);
    exit;
}

require_once __DIR__ . '/../helpers/twilio-whatsapp.php';

// Parse input (JSON body)
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!$input) {
    // Fallback to POST form data
    $input = $_POST;
}

$phone = preg_replace('/[^0-9+]/', '', $input['phone'] ?? '');
$total = (float)($input['total'] ?? 0);
$items = $input['items'] ?? [];
$storeName = strip_tags($input['store_name'] ?? 'SuperBora');
$orderNumber = strip_tags($input['order_number'] ?? '');
$callSid = strip_tags($input['call_sid'] ?? '');

if (empty($phone)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing phone']);
    exit;
}
if ($total <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Total must be > 0']);
    exit;
}

// Build Stripe line items
$lineItems = [];
if (is_array($items) && count($items) > 0) {
    foreach ($items as $item) {
        $unitPrice = (float)($item['price'] ?? 0);
        $qty = (int)($item['quantity'] ?? 1);
        $name = $item['name'] ?? 'Item';
        if ($qty > 1) $name = "{$qty}x {$name}";

        $lineItems[] = [
            'price_data' => [
                'currency' => 'brl',
                'unit_amount' => (int)round($unitPrice * 100),
                'product_data' => ['name' => $name],
            ],
            'quantity' => $qty,
        ];
    }
} else {
    // Single line item for total
    $lineItems[] = [
        'price_data' => [
            'currency' => 'brl',
            'unit_amount' => (int)round($total * 100),
            'product_data' => ['name' => "Pedido {$storeName}"],
        ],
        'quantity' => 1,
    ];
}

$baseUrl = $_ENV['APP_URL'] ?? 'https://superbora.com.br';

// Build Stripe Checkout Session
$stripeData = http_build_query([
    'mode' => 'payment',
    'success_url' => $baseUrl . '/pagamento-confirmado?session_id={CHECKOUT_SESSION_ID}',
    'cancel_url' => $baseUrl . '/pagamento-cancelado',
    'metadata[source]' => 'voice_ai',
    'metadata[call_sid]' => $callSid,
    'metadata[order_number]' => $orderNumber,
    'payment_method_types[0]' => 'card',
]);

foreach ($lineItems as $i => $li) {
    $stripeData .= '&' . http_build_query([
        "line_items[{$i}][price_data][currency]" => $li['price_data']['currency'],
        "line_items[{$i}][price_data][unit_amount]" => $li['price_data']['unit_amount'],
        "line_items[{$i}][price_data][product_data][name]" => $li['price_data']['product_data']['name'],
        "line_items[{$i}][quantity]" => $li['quantity'],
    ]);
}

$ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $stripeData,
    CURLOPT_USERPWD => $STRIPE_SK . ':',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
]);

$stripeResult = curl_exec($ch);
$stripeHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    error_log("[voice-payment-link] Stripe cURL error: {$curlError}");
    http_response_code(502);
    echo json_encode(['error' => 'Stripe connection error']);
    exit;
}

$stripeResponse = json_decode($stripeResult, true);

if ($stripeHttpCode < 200 || $stripeHttpCode >= 300) {
    $errorMsg = $stripeResponse['error']['message'] ?? "HTTP {$stripeHttpCode}";
    error_log("[voice-payment-link] Stripe error: {$errorMsg}");
    http_response_code(502);
    echo json_encode(['error' => 'Stripe error: ' . $errorMsg]);
    exit;
}

$sessionId = $stripeResponse['id'] ?? '';
$paymentUrl = $stripeResponse['url'] ?? '';

if (empty($sessionId) || empty($paymentUrl)) {
    error_log("[voice-payment-link] Stripe response missing id/url");
    http_response_code(502);
    echo json_encode(['error' => 'Invalid Stripe response']);
    exit;
}

// Send payment link via WhatsApp (preferred) or SMS (fallback)
$totalStr = number_format($total, 2, ',', '.');
$msgBody = "SuperBora - Link de Pagamento\n\n"
    . "Loja: {$storeName}\n"
    . "Total: R\${$totalStr}\n\n"
    . "Pague com cartao de credito:\n{$paymentUrl}\n\n"
    . "Link valido por 30 minutos.";

$sendResult = sendWhatsAppOrSMS($phone, $msgBody);
$smsSent = $sendResult['success'];

error_log("[voice-payment-link] Created: session={$sessionId} total={$total} phone={$phone} sent_via={$sendResult['sent_via']}");

echo json_encode([
    'success' => true,
    'payment_url' => $paymentUrl,
    'session_id' => $sessionId,
    'sms_sent' => $smsSent,
    'total' => $total,
]);
