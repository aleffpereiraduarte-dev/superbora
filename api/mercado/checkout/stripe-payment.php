<?php
/**
 * POST /api/mercado/checkout/stripe-payment.php
 * Cria Payment Intent do Stripe para pedido do mercado
 * Body: { "order_id": 123 }
 *
 * Retorna client_secret para o frontend confirmar com Stripe.js
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

// Carregar chaves Stripe
$stripeEnv = dirname(__DIR__, 3) . '/.env.stripe';
$STRIPE_SK = '';
$STRIPE_PK = '';
if (file_exists($stripeEnv)) {
    $lines = file($stripeEnv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $k = trim($key);
            $v = trim($value);
            if ($k === 'STRIPE_SECRET_KEY') $STRIPE_SK = $v;
            if ($k === 'STRIPE_PUBLIC_KEY') $STRIPE_PK = $v;
        }
    }
}

if (empty($STRIPE_SK) || strpos($STRIPE_SK, 'XXX') !== false) {
    response(false, null, "Stripe nao configurado", 503);
}

function stripeAPI(string $endpoint, string $method = 'GET', array $data = null): array {
    global $STRIPE_SK;
    $ch = curl_init("https://api.stripe.com/v1/{$endpoint}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $STRIPE_SK,
            'Content-Type: application/x-www-form-urlencoded',
            'Stripe-Version: 2023-10-16'
        ],
        CURLOPT_TIMEOUT => 30
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

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Autenticacao necessaria", 401);
    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== 'customer') response(false, null, "Token invalido", 401);
    $customerId = (int)$payload['uid'];

    $input = getInput();
    $orderId = (int)($input['order_id'] ?? 0);
    if (!$orderId) response(false, null, "order_id obrigatorio", 400);

    // Buscar pedido com lock para evitar criacao duplicada de PaymentIntent
    $db->beginTransaction();
    $stmt = $db->prepare("
        SELECT order_id, order_number, total, customer_name, customer_email, customer_phone, payment_status, payment_method, payment_id
        FROM om_market_orders WHERE order_id = ? AND customer_id = ?
        FOR UPDATE
    ");
    $stmt->execute([$orderId, $customerId]);
    $order = $stmt->fetch();
    if (!$order) { $db->rollBack(); response(false, null, "Pedido nao encontrado", 404); }

    if ($order['payment_status'] === 'paid') {
        $db->commit();
        response(false, null, "Pedido ja foi pago", 400);
    }

    $amount = intval(round((float)$order['total'] * 100)); // centavos
    if ($amount < 100) response(false, null, "Valor minimo R$ 1,00", 400);

    // Criar ou buscar Stripe customer
    $stmtCust = $db->prepare("SELECT stripe_customer_id FROM om_customers WHERE customer_id = ?");
    $stmtCust->execute([$customerId]);
    $stripeCustomerId = $stmtCust->fetchColumn();

    if (!$stripeCustomerId) {
        $r = stripeAPI('customers', 'POST', [
            'email' => $order['customer_email'],
            'name' => $order['customer_name'],
            'phone' => $order['customer_phone'],
            'metadata[superbora_customer_id]' => $customerId
        ]);
        if ($r['code'] === 200 && !empty($r['data']['id'])) {
            $stripeCustomerId = $r['data']['id'];
            // Column stripe_customer_id on om_customers created via migration
            $db->prepare("UPDATE om_customers SET stripe_customer_id = ? WHERE customer_id = ?")
                ->execute([$stripeCustomerId, $customerId]);
        }
    }

    // Reuse existing PaymentIntent if order already has one (prevents double-charge)
    $existingPiId = $order['payment_id'] ?? '';
    if (!empty($existingPiId) && strpos($existingPiId, 'pi_') === 0) {
        $existingPi = stripeAPI('payment_intents/' . urlencode($existingPiId));
        if ($existingPi['code'] === 200 && !empty($existingPi['data']['client_secret'])) {
            $piStatus = $existingPi['data']['status'] ?? '';
            // Reusable statuses: not yet paid/completed, still actionable by the client
            if (in_array($piStatus, ['requires_payment_method', 'requires_confirmation', 'requires_action'])) {
                error_log("[stripe-payment] Reusing existing PI {$existingPiId} (status: {$piStatus}) for order {$orderId}");
                $db->commit();
                response(true, [
                    "client_secret" => $existingPi['data']['client_secret'],
                    "payment_intent_id" => $existingPiId,
                    "publishable_key" => $STRIPE_PK,
                    "amount" => $amount,
                    "amount_formatted" => "R$ " . number_format($order['total'], 2, ',', '.')
                ]);
            }
            // If PI is succeeded/processing/canceled, fall through to create a new one
            error_log("[stripe-payment] Existing PI {$existingPiId} not reusable (status: {$piStatus}), creating new one for order {$orderId}");
        }
    }

    // Criar Payment Intent
    // Inclui card, Apple Pay, Google Pay via Payment Request, e Link (1-click checkout)
    $piData = [
        'amount' => $amount,
        'currency' => 'brl',
        'description' => "Pedido #{$order['order_number']} - SuperBora",
        'automatic_payment_methods[enabled]' => 'true', // Habilita Apple Pay, Google Pay, Link automaticamente
        'metadata[order_id]' => $orderId,
        'metadata[order_number]' => $order['order_number'],
        'metadata[customer_id]' => $customerId,
        'metadata[source]' => 'superbora_mercado'
    ];
    if ($stripeCustomerId) {
        $piData['customer'] = $stripeCustomerId;
        $piData['setup_future_usage'] = 'off_session';
    }
    if ($order['customer_email']) {
        $piData['receipt_email'] = $order['customer_email'];
    }

    $r = stripeAPI('payment_intents', 'POST', $piData);

    if ($r['code'] !== 200 || empty($r['data']['client_secret'])) {
        $err = $r['data']['error']['message'] ?? 'Erro ao criar pagamento';
        error_log("[stripe-payment] Stripe error for order {$orderId}: {$err}");
        response(false, null, "Erro ao processar pagamento. Tente novamente.", 500);
    }

    // Salvar payment_intent_id no pedido
    $db->prepare("UPDATE om_market_orders SET payment_id = ?, pagarme_status = 'pending' WHERE order_id = ?")
        ->execute([$r['data']['id'], $orderId]);
    $db->commit();

    response(true, [
        "client_secret" => $r['data']['client_secret'],
        "payment_intent_id" => $r['data']['id'],
        "publishable_key" => $STRIPE_PK,
        "amount" => $amount,
        "amount_formatted" => "R$ " . number_format($order['total'], 2, ',', '.')
    ]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log("[stripe-payment] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar pagamento", 500);
}
