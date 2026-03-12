<?php
/**
 * POST /api/mercado/pagamento/stripe-intent.php
 * Cria Stripe PaymentIntent para Apple Pay / Google Pay (wallet)
 * Body: { amount (centavos), currency, customer_id, customer_email, customer_name }
 * Retorna: { clientSecret, paymentIntentId, publishableKey }
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

// Carregar chaves Stripe
$stripeEnv = dirname(__DIR__, 3) . '/.env.stripe';
$STRIPE_SK = '';
$STRIPE_PK = '';
if (file_exists($stripeEnv)) {
    foreach (file($stripeEnv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);
        if ($k === 'STRIPE_SECRET_KEY') $STRIPE_SK = $v;
        if ($k === 'STRIPE_PUBLIC_KEY') $STRIPE_PK = $v;
    }
}

if (empty($STRIPE_SK) || strpos($STRIPE_SK, 'XXX') !== false) {
    response(false, null, "Stripe nao configurado", 503);
}

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Autenticacao necessaria", 401);
    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== 'customer') response(false, null, "Token invalido", 401);
    $customerId = (int)$payload['uid'];

    $input = getInput();
    $amount = (int)($input['amount'] ?? 0);
    $currency = strtolower($input['currency'] ?? 'brl');
    $customerEmail = trim($input['customer_email'] ?? '');
    $customerName = trim($input['customer_name'] ?? '');

    if ($amount < 100) response(false, null, "Valor minimo R$ 1,00", 400);
    if ($amount > 1000000) response(false, null, "Valor maximo R$ 10.000", 400);

    // Buscar/criar Stripe customer
    $stmtCust = $db->prepare("SELECT stripe_customer_id FROM om_customers WHERE customer_id = ?");
    $stmtCust->execute([$customerId]);
    $stripeCustomerId = $stmtCust->fetchColumn();

    if (!$stripeCustomerId && $customerEmail) {
        $ch = curl_init("https://api.stripe.com/v1/customers");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $STRIPE_SK,
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_POSTFIELDS => http_build_query([
                'email' => $customerEmail,
                'name' => $customerName,
                'metadata[superbora_customer_id]' => $customerId,
            ]),
            CURLOPT_TIMEOUT => 15,
        ]);
        $result = json_decode(curl_exec($ch), true);
        curl_close($ch);
        if (!empty($result['id'])) {
            $stripeCustomerId = $result['id'];
            $db->prepare("UPDATE om_customers SET stripe_customer_id = ? WHERE customer_id = ?")
                ->execute([$stripeCustomerId, $customerId]);
        }
    }

    // Criar PaymentIntent com wallet methods habilitados
    $piData = [
        'amount' => $amount,
        'currency' => $currency,
        'automatic_payment_methods[enabled]' => 'true',
        'metadata[customer_id]' => $customerId,
        'metadata[source]' => 'superbora_wallet',
    ];
    if ($stripeCustomerId) {
        $piData['customer'] = $stripeCustomerId;
    }
    if ($customerEmail) {
        $piData['receipt_email'] = $customerEmail;
    }

    $ch = curl_init("https://api.stripe.com/v1/payment_intents");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $STRIPE_SK,
            'Content-Type: application/x-www-form-urlencoded',
            'Stripe-Version: 2023-10-16',
        ],
        CURLOPT_POSTFIELDS => http_build_query($piData),
        CURLOPT_TIMEOUT => 30,
    ]);
    $result = json_decode(curl_exec($ch), true);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || empty($result['client_secret'])) {
        $err = $result['error']['message'] ?? 'Erro ao criar pagamento';
        error_log("[stripe-intent] Stripe error: $err");
        response(false, null, "Erro ao processar pagamento", 500);
    }

    response(true, [
        'clientSecret' => $result['client_secret'],
        'paymentIntentId' => $result['id'],
        'publishableKey' => $STRIPE_PK,
        'amount' => $amount,
    ]);

} catch (Exception $e) {
    error_log("[stripe-intent] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar pagamento", 500);
}
