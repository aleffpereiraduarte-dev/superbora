<?php
/**
 * API STRIPE - ONEMUNDO
 * Cartão de Crédito, Apple Pay, Google Pay
 *
 * Endpoints:
 * - create_payment_intent: Cria intent para pagamento
 * - confirm_payment: Confirma pagamento
 * - get_config: Retorna chave pública
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

// Carregar variáveis de ambiente
$envFile = __DIR__ . '/.env.stripe';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($value);
        }
    }
}

$STRIPE_SK = $_ENV['STRIPE_SECRET_KEY'] ?? '';
$STRIPE_PK = $_ENV['STRIPE_PUBLIC_KEY'] ?? '';
$STRIPE_WEBHOOK = $_ENV['STRIPE_WEBHOOK_SECRET'] ?? '';

// Validar configuração
if (empty($STRIPE_SK) || $STRIPE_SK === 'SUA_CHAVE_SECRETA_AQUI') {
    echo json_encode(['success' => false, 'error' => 'Stripe não configurado. Adicione a chave secreta em .env.stripe']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? $_GET['action'] ?? '';

/**
 * Requisição para API do Stripe
 */
function stripeRequest($endpoint, $method = 'GET', $data = null) {
    global $STRIPE_SK;

    $ch = curl_init("https://api.stripe.com/v1/{$endpoint}");

    $headers = [
        'Authorization: Bearer ' . $STRIPE_SK,
        'Content-Type: application/x-www-form-urlencoded',
        'Stripe-Version: 2023-10-16'
    ];

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30
    ]);

    if ($method === 'POST' && $data) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    }

    $result = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['code' => 0, 'data' => ['error' => ['message' => $error]]];
    }

    return ['code' => $code, 'data' => json_decode($result, true)];
}

/**
 * Formatar valor para centavos
 */
function toCents($value) {
    return intval(round(floatval($value) * 100));
}

try {
    switch ($action) {

        // ══════════════════════════════════════════════════════════════
        // CONFIGURAÇÃO
        // ══════════════════════════════════════════════════════════════
        case 'get_config':
            echo json_encode([
                'success' => true,
                'publishable_key' => $STRIPE_PK,
                'country' => 'BR',
                'currency' => 'brl'
            ]);
            break;

        case 'test':
            // Testar conexão com Stripe
            $r = stripeRequest('balance');
            if ($r['code'] === 200) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Stripe conectado!',
                    'available' => $r['data']['available'][0]['amount'] ?? 0
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => $r['data']['error']['message'] ?? 'Erro de conexão'
                ]);
            }
            break;

        // ══════════════════════════════════════════════════════════════
        // PAYMENT INTENT (Cartão + Apple Pay + Google Pay)
        // ══════════════════════════════════════════════════════════════
        case 'create_payment_intent':
            $amount = toCents($input['amount'] ?? 0);
            $currency = $input['currency'] ?? 'brl';
            $description = $input['description'] ?? 'Pedido OneMundo';
            $customerEmail = $input['customer_email'] ?? '';
            $customerName = $input['customer_name'] ?? '';
            $orderId = $input['order_id'] ?? '';

            if ($amount < 100) { // Mínimo R$ 1,00
                echo json_encode(['success' => false, 'error' => 'Valor mínimo R$ 1,00']);
                break;
            }

            $payload = [
                'amount' => $amount,
                'currency' => $currency,
                'description' => $description,
                'payment_method_types' => ['card'],
                'metadata' => [
                    'order_id' => $orderId,
                    'customer_name' => $customerName,
                    'customer_email' => $customerEmail,
                    'source' => 'onemundo_checkout'
                ]
            ];

            // Se tiver email, adicionar receipt
            if ($customerEmail) {
                $payload['receipt_email'] = $customerEmail;
            }

            $r = stripeRequest('payment_intents', 'POST', $payload);

            if ($r['code'] === 200 && !empty($r['data']['client_secret'])) {
                echo json_encode([
                    'success' => true,
                    'client_secret' => $r['data']['client_secret'],
                    'payment_intent_id' => $r['data']['id'],
                    'amount' => $amount,
                    'status' => $r['data']['status']
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => $r['data']['error']['message'] ?? 'Erro ao criar pagamento'
                ]);
            }
            break;

        // ══════════════════════════════════════════════════════════════
        // CONFIRMAR PAGAMENTO
        // ══════════════════════════════════════════════════════════════
        case 'confirm_payment':
            $paymentIntentId = $input['payment_intent_id'] ?? '';
            $paymentMethodId = $input['payment_method_id'] ?? '';

            if (empty($paymentIntentId)) {
                echo json_encode(['success' => false, 'error' => 'Payment Intent ID obrigatório']);
                break;
            }

            $payload = ['payment_method' => $paymentMethodId];

            $r = stripeRequest("payment_intents/{$paymentIntentId}/confirm", 'POST', $payload);

            if ($r['code'] === 200) {
                $status = $r['data']['status'];
                $paid = in_array($status, ['succeeded', 'processing']);

                echo json_encode([
                    'success' => true,
                    'paid' => $paid,
                    'status' => $status,
                    'payment_intent_id' => $r['data']['id'],
                    'amount' => $r['data']['amount'],
                    'requires_action' => $status === 'requires_action',
                    'next_action' => $r['data']['next_action'] ?? null
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => $r['data']['error']['message'] ?? 'Erro ao confirmar'
                ]);
            }
            break;

        // ══════════════════════════════════════════════════════════════
        // VERIFICAR STATUS
        // ══════════════════════════════════════════════════════════════
        case 'check_status':
            $paymentIntentId = $input['payment_intent_id'] ?? $_GET['payment_intent_id'] ?? '';

            if (empty($paymentIntentId)) {
                echo json_encode(['success' => false, 'error' => 'Payment Intent ID obrigatório']);
                break;
            }

            $r = stripeRequest("payment_intents/{$paymentIntentId}");

            if ($r['code'] === 200) {
                $status = $r['data']['status'];
                echo json_encode([
                    'success' => true,
                    'paid' => $status === 'succeeded',
                    'status' => $status,
                    'amount' => $r['data']['amount'],
                    'payment_method' => $r['data']['payment_method'] ?? null
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => $r['data']['error']['message'] ?? 'Erro ao verificar'
                ]);
            }
            break;

        // ══════════════════════════════════════════════════════════════
        // CRIAR CUSTOMER (para salvar cartões)
        // ══════════════════════════════════════════════════════════════
        case 'create_customer':
            $email = $input['email'] ?? '';
            $name = $input['name'] ?? '';
            $phone = $input['phone'] ?? '';

            if (empty($email)) {
                echo json_encode(['success' => false, 'error' => 'Email obrigatório']);
                break;
            }

            // Verificar se já existe
            $r = stripeRequest('customers?email=' . urlencode($email));
            if ($r['code'] === 200 && !empty($r['data']['data'][0])) {
                echo json_encode([
                    'success' => true,
                    'customer_id' => $r['data']['data'][0]['id'],
                    'existing' => true
                ]);
                break;
            }

            // Criar novo
            $payload = [
                'email' => $email,
                'name' => $name,
                'phone' => $phone,
                'metadata' => ['source' => 'onemundo']
            ];

            $r = stripeRequest('customers', 'POST', $payload);

            if ($r['code'] === 200 && !empty($r['data']['id'])) {
                echo json_encode([
                    'success' => true,
                    'customer_id' => $r['data']['id'],
                    'existing' => false
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => $r['data']['error']['message'] ?? 'Erro ao criar cliente'
                ]);
            }
            break;

        // ══════════════════════════════════════════════════════════════
        // LISTAR CARTÕES DO CLIENTE
        // ══════════════════════════════════════════════════════════════
        case 'list_cards':
            $customerId = $input['customer_id'] ?? $_GET['customer_id'] ?? '';

            if (empty($customerId)) {
                echo json_encode(['success' => false, 'error' => 'Customer ID obrigatório']);
                break;
            }

            $r = stripeRequest("customers/{$customerId}/payment_methods?type=card");

            $cards = [];
            if ($r['code'] === 200 && !empty($r['data']['data'])) {
                foreach ($r['data']['data'] as $pm) {
                    $cards[] = [
                        'id' => $pm['id'],
                        'brand' => $pm['card']['brand'],
                        'last4' => $pm['card']['last4'],
                        'exp_month' => $pm['card']['exp_month'],
                        'exp_year' => $pm['card']['exp_year'],
                        'holder_name' => $pm['billing_details']['name'] ?? ''
                    ];
                }
            }

            echo json_encode(['success' => true, 'cards' => $cards]);
            break;

        // ══════════════════════════════════════════════════════════════
        // SALVAR CARTÃO
        // ══════════════════════════════════════════════════════════════
        case 'save_card':
            $customerId = $input['customer_id'] ?? '';
            $paymentMethodId = $input['payment_method_id'] ?? '';

            if (empty($customerId) || empty($paymentMethodId)) {
                echo json_encode(['success' => false, 'error' => 'Dados incompletos']);
                break;
            }

            // Anexar payment method ao customer
            $r = stripeRequest("payment_methods/{$paymentMethodId}/attach", 'POST', [
                'customer' => $customerId
            ]);

            if ($r['code'] === 200) {
                echo json_encode([
                    'success' => true,
                    'card_id' => $r['data']['id'],
                    'brand' => $r['data']['card']['brand'],
                    'last4' => $r['data']['card']['last4']
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => $r['data']['error']['message'] ?? 'Erro ao salvar cartão'
                ]);
            }
            break;

        // ══════════════════════════════════════════════════════════════
        // COBRAR CARTÃO SALVO
        // ══════════════════════════════════════════════════════════════
        case 'charge_saved_card':
            $customerId = $input['customer_id'] ?? '';
            $paymentMethodId = $input['payment_method_id'] ?? '';
            $amount = toCents($input['amount'] ?? 0);
            $description = $input['description'] ?? 'Pedido OneMundo';

            if (empty($customerId) || empty($paymentMethodId) || $amount < 100) {
                echo json_encode(['success' => false, 'error' => 'Dados incompletos']);
                break;
            }

            $payload = [
                'amount' => $amount,
                'currency' => 'brl',
                'customer' => $customerId,
                'payment_method' => $paymentMethodId,
                'off_session' => 'true',
                'confirm' => 'true',
                'description' => $description
            ];

            $r = stripeRequest('payment_intents', 'POST', $payload);

            if ($r['code'] === 200 && $r['data']['status'] === 'succeeded') {
                echo json_encode([
                    'success' => true,
                    'paid' => true,
                    'payment_intent_id' => $r['data']['id'],
                    'amount' => $r['data']['amount']
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => $r['data']['error']['message'] ?? 'Pagamento recusado'
                ]);
            }
            break;

        // ══════════════════════════════════════════════════════════════
        // REEMBOLSO
        // ══════════════════════════════════════════════════════════════
        case 'refund':
            $paymentIntentId = $input['payment_intent_id'] ?? '';
            $amount = isset($input['amount']) ? toCents($input['amount']) : null;
            $reason = $input['reason'] ?? 'requested_by_customer';

            if (empty($paymentIntentId)) {
                echo json_encode(['success' => false, 'error' => 'Payment Intent ID obrigatório']);
                break;
            }

            $payload = ['payment_intent' => $paymentIntentId];
            if ($amount) {
                $payload['amount'] = $amount; // Reembolso parcial
            }
            $payload['reason'] = $reason;

            $r = stripeRequest('refunds', 'POST', $payload);

            if ($r['code'] === 200) {
                echo json_encode([
                    'success' => true,
                    'refund_id' => $r['data']['id'],
                    'status' => $r['data']['status'],
                    'amount' => $r['data']['amount']
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => $r['data']['error']['message'] ?? 'Erro no reembolso'
                ]);
            }
            break;

        default:
            echo json_encode([
                'success' => false,
                'error' => 'Ação não reconhecida',
                'available_actions' => [
                    'get_config', 'test', 'create_payment_intent',
                    'confirm_payment', 'check_status', 'create_customer',
                    'list_cards', 'save_card', 'charge_saved_card', 'refund'
                ]
            ]);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erro interno: ' . $e->getMessage()
    ]);
}
