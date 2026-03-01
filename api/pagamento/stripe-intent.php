<?php
/**
 * POST /api/pagamento/stripe-intent.php
 * Creates a Stripe PaymentIntent BEFORE order creation.
 * Used by the mobile app checkout (card, Apple Pay, Google Pay).
 *
 * Body: { amount: 5000, currency: 'brl', customer_id: 1, customer_email: '', customer_name: '' }
 * Returns: { clientSecret: 'pi_xxx_secret_xxx', paymentIntentId: 'pi_xxx' }
 */

header('Content-Type: application/json; charset=utf-8');

// SECURITY: Restrict CORS to known origins
$allowedOrigins = ['https://app.superbora.com.br', 'https://painel.superbora.com.br', 'https://www.superbora.com.br', 'https://superbora.com.br'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
} elseif (empty($origin)) {
    // Mobile app / server-to-server (no Origin header)
} else {
    header('Access-Control-Allow-Origin: https://app.superbora.com.br');
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'Method not allowed']));
}

// Load .env
$envPath = dirname(__DIR__, 2) . '/.env';
if (file_exists($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            [$key, $value] = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($value);
        }
    }
}

// Load Stripe keys from .env.stripe (BR + US accounts)
$stripeEnv = dirname(__DIR__, 2) . '/.env.stripe';
$STRIPE_SK_BR = ''; $STRIPE_PK_BR = '';
$STRIPE_SK_US = ''; $STRIPE_PK_US = '';
if (file_exists($stripeEnv)) {
    foreach (file($stripeEnv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            [$key, $value] = explode('=', $line, 2);
            $k = trim($key);
            $v = trim($value);
            if ($k === 'STRIPE_SECRET_KEY') $STRIPE_SK_BR = $v;
            if ($k === 'STRIPE_PUBLIC_KEY') $STRIPE_PK_BR = $v;
            if ($k === 'STRIPE_SECRET_KEY_US') $STRIPE_SK_US = $v;
            if ($k === 'STRIPE_PUBLIC_KEY_US') $STRIPE_PK_US = $v;
        }
    }
}

if (empty($STRIPE_SK_BR) || strpos($STRIPE_SK_BR, 'XXX') !== false) {
    http_response_code(503);
    exit(json_encode(['success' => false, 'message' => 'Stripe nao configurado. Configure .env.stripe com chaves reais.']));
}

// Auth: validate JWT from Authorization header
require_once dirname(__DIR__) . '/mercado/config/database.php';
require_once dirname(__DIR__, 2) . '/includes/classes/OmAuth.php';

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();
    if (!$token) {
        http_response_code(401);
        exit(json_encode(['success' => false, 'message' => 'Autenticacao necessaria']));
    }
    $payload = om_auth()->validateToken($token);
    if (!$payload) {
        http_response_code(401);
        exit(json_encode(['success' => false, 'message' => 'Token invalido']));
    }
    $customerId = (int)$payload['uid'];

    // Read input
    $input = json_decode(file_get_contents('php://input'), true);
    $amount = (int)($input['amount'] ?? 0); // already in cents from frontend
    $currency = strtolower($input['currency'] ?? 'brl');
    $customerEmail = trim($input['customer_email'] ?? '');
    $customerName = trim($input['customer_name'] ?? '');

    // Route to BR or US Stripe account based on card brand
    $stripeAccount = strtolower(trim($input['stripe_account'] ?? 'br'));
    if ($stripeAccount === 'us' && !empty($STRIPE_SK_US)) {
        $STRIPE_SK = $STRIPE_SK_US;
        $STRIPE_PK = $STRIPE_PK_US;
    } else {
        $STRIPE_SK = $STRIPE_SK_BR;
        $STRIPE_PK = $STRIPE_PK_BR;
        $stripeAccount = 'br';
    }

    if ($amount < 100) { // R$1.00 minimum
        http_response_code(400);
        exit(json_encode(['success' => false, 'message' => 'Valor minimo R$ 1,00']));
    }

    if ($amount > 10000000) { // R$100,000 max safety
        http_response_code(400);
        exit(json_encode(['success' => false, 'message' => 'Valor excede o limite']));
    }

    // SECURITY: Validate client amount against server-side cart total
    // Prevents creating PaymentIntents for manipulated amounts
    try {
        $stmtCart = $db->prepare("
            SELECT COALESCE(SUM(
                CASE WHEN p.special_price > 0 AND p.special_price < p.price THEN p.special_price ELSE p.price END * c.quantity
            ), 0) as subtotal
            FROM om_market_cart c
            INNER JOIN om_market_products p ON c.product_id = p.product_id
            WHERE c.customer_id = ?
        ");
        $stmtCart->execute([$customerId]);
        $cartSubtotal = (float)$stmtCart->fetchColumn();
        $cartSubtotalCents = (int)round($cartSubtotal * 100);

        // Amount should be reasonable vs cart subtotal (coupons/cashback/points can reduce total significantly)
        // Lower bound: R$1.00 minimum (already validated above) — coupons can give up to 100% off items
        // Upper bound: 3x subtotal (fees + tip can add significant amount)
        if ($cartSubtotalCents > 0) {
            if ($amount > $cartSubtotalCents * 3) {
                error_log("[stripe-intent] SECURITY: Amount too high! Client={$amount} cents, Cart={$cartSubtotalCents} cents, Customer={$customerId}");
                http_response_code(400);
                exit(json_encode(['success' => false, 'message' => 'Valor do pagamento inconsistente com o carrinho']));
            }
        }
    } catch (Exception $cartErr) {
        error_log("[stripe-intent] Cart validation error: " . $cartErr->getMessage());
        // Don't block — processar.php will do final verification
    }

    // Find or create Stripe Customer
    $stripeCustomerId = null;
    try {
        $stmt = $db->prepare("SELECT stripe_customer_id FROM om_customers WHERE customer_id = ?");
        $stmt->execute([$customerId]);
        $stripeCustomerId = $stmt->fetchColumn() ?: null;
    } catch (Exception $e) {
        // Column may not exist yet
    }

    if (!$stripeCustomerId && ($customerEmail || $customerName)) {
        $custData = ['metadata[superbora_customer_id]' => $customerId];
        if ($customerEmail) $custData['email'] = $customerEmail;
        if ($customerName) $custData['name'] = $customerName;

        $r = stripeAPI($STRIPE_SK, 'customers', 'POST', $custData);
        if ($r['code'] === 200 && !empty($r['data']['id'])) {
            $stripeCustomerId = $r['data']['id'];
            try {
                $db->exec("ALTER TABLE om_customers ADD COLUMN IF NOT EXISTS stripe_customer_id VARCHAR(50) DEFAULT NULL");
                $db->prepare("UPDATE om_customers SET stripe_customer_id = ? WHERE customer_id = ?")
                    ->execute([$stripeCustomerId, $customerId]);
            } catch (Exception $e) { /* ignore */ }
        }
    }

    // Create PaymentIntent with automatic_payment_methods (enables card, Apple Pay, Google Pay, Link)
    $piData = [
        'amount' => $amount,
        'currency' => $currency,
        'description' => "SuperBora Mercado - Cliente #{$customerId}",
        'automatic_payment_methods[enabled]' => 'true',
        'metadata[customer_id]' => $customerId,
        'metadata[source]' => 'superbora_mobile_app',
        'metadata[stripe_account]' => $stripeAccount,
    ];
    if ($stripeCustomerId) {
        $piData['customer'] = $stripeCustomerId;
    }
    if ($customerEmail) {
        $piData['receipt_email'] = $customerEmail;
    }

    $r = stripeAPI($STRIPE_SK, 'payment_intents', 'POST', $piData);

    if ($r['code'] !== 200 || empty($r['data']['client_secret'])) {
        $err = $r['data']['error']['message'] ?? 'Erro ao criar pagamento';
        error_log("[stripe-intent] Stripe error: " . json_encode($r['data']));
        http_response_code(500);
        exit(json_encode(['success' => false, 'message' => $err]));
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'clientSecret' => $r['data']['client_secret'],
            'paymentIntentId' => $r['data']['id'],
            'publishableKey' => $STRIPE_PK,
            'account' => $stripeAccount,
        ],
    ]);

} catch (Exception $e) {
    error_log("[stripe-intent] Erro: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro ao processar pagamento']);
}

function stripeAPI(string $secretKey, string $endpoint, string $method = 'GET', array $data = null): array {
    $ch = curl_init("https://api.stripe.com/v1/{$endpoint}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $secretKey,
            'Content-Type: application/x-www-form-urlencoded',
            'Stripe-Version: 2023-10-16'
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    if ($method === 'POST' && $data) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    }
    $result = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'data' => json_decode($result, true)];
}
