<?php
/**
 * API PAGAR.ME - ONEMUNDO v3
 * Cartão, PIX, Boleto + Fallback MP
 *
 * SEGURANÇA: Chaves carregadas via variáveis de ambiente
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

// Carregar variáveis de ambiente
require_once __DIR__ . '/includes/env_loader.php';

// Chaves via variáveis de ambiente (SEGURO)
$PAGARME_SK = env('PAGARME_SECRET_KEY', '');
$MP_TOKEN = env('MP_ACCESS_TOKEN', '');

if (empty($PAGARME_SK)) {
    echo json_encode(['success' => false, 'error' => 'API não configurada']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? $_GET['action'] ?? '';

function pagarmeRequest($endpoint, $method = 'GET', $data = null) {
    global $PAGARME_SK;
    $ch = curl_init("https://api.pagar.me/core/v5/{$endpoint}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'accept: application/json',
            'content-type: application/json',
            'authorization: Basic ' . base64_encode($PAGARME_SK . ':')
        ]
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    $result = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'data' => json_decode($result, true)];
}

try {
    switch ($action) {
        case 'test':
            echo json_encode(['success' => true, 'message' => 'API Pagar.me v3 OK', 'time' => date('c')]);
            break;
            
        case 'get_customer_by_email':
            $email = $input['email'] ?? $_GET['email'] ?? '';
            $r = pagarmeRequest('customers?email=' . urlencode($email));
            if (!empty($r['data']['data'][0])) {
                $c = $r['data']['data'][0];
                echo json_encode(['success' => true, 'customer_id' => $c['id'], 'name' => $c['name'], 'email' => $c['email']]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Cliente não encontrado']);
            }
            break;
            
        case 'get_cards':
            $customerId = $input['customer_id'] ?? $_GET['customer_id'] ?? '';
            $r = pagarmeRequest("customers/{$customerId}/cards");
            $cards = [];
            if (!empty($r['data']['data'])) {
                foreach ($r['data']['data'] as $card) {
                    $cards[] = ['id' => $card['id'], 'brand' => $card['brand'], 'last_four' => $card['last_four_digits'], 'holder_name' => $card['holder_name']];
                }
            }
            echo json_encode(['success' => true, 'cards' => $cards]);
            break;
            
        case 'create_pix':
            $amount = intval($input['amount'] ?? 0);
            $name = $input['customer_name'] ?? 'Cliente';
            $email = $input['customer_email'] ?? '';
            $doc = preg_replace('/\D/', '', $input['customer_document'] ?? '');
            $phone = preg_replace('/\D/', '', $input['customer_phone'] ?? '11999999999');

            // Pagar.me exige telefone para PIX
            $payload = [
                'customer' => [
                    'name' => $name,
                    'email' => $email,
                    'type' => 'individual',
                    'document' => $doc,
                    'document_type' => strlen($doc) > 11 ? 'CNPJ' : 'CPF',
                    'phones' => [
                        'mobile_phone' => [
                            'country_code' => '55',
                            'area_code' => substr($phone, 0, 2),
                            'number' => substr($phone, 2)
                        ]
                    ]
                ],
                'items' => [['amount' => $amount, 'description' => 'Pedido OneMundo', 'quantity' => 1]],
                'payments' => [['payment_method' => 'pix', 'pix' => ['expires_in' => 3600]]]
            ];
            
            $r = pagarmeRequest('orders', 'POST', $payload);
            if (!empty($r['data']['charges'][0]['last_transaction']['qr_code'])) {
                $tx = $r['data']['charges'][0]['last_transaction'];
                echo json_encode(['success' => true, 'order_id' => $r['data']['id'], 'charge_id' => $r['data']['charges'][0]['id'], 'qr_code' => $tx['qr_code'], 'qr_code_url' => $tx['qr_code_url']]);
            } else {
                // Extrair mensagem de erro
                $errorMsg = $r['data']['message'] ?? '';
                if (empty($errorMsg) && !empty($r['data']['charges'][0]['last_transaction']['gateway_response']['errors'][0]['message'])) {
                    $errorMsg = $r['data']['charges'][0]['last_transaction']['gateway_response']['errors'][0]['message'];
                }
                echo json_encode(['success' => false, 'error' => $errorMsg ?: 'PIX não disponível. Verifique as configurações da Pagar.me.']);
            }
            break;
            
        case 'check_pix':
            $chargeId = $input['charge_id'] ?? $_GET['charge_id'] ?? '';
            $r = pagarmeRequest("charges/{$chargeId}");
            echo json_encode(['success' => true, 'status' => $r['data']['status'] ?? 'unknown', 'paid' => ($r['data']['status'] ?? '') === 'paid']);
            break;
            
        case 'charge_card':
            $customerId = $input['customer_id'] ?? '';
            $cardId = $input['card_id'] ?? '';
            $amount = intval($input['amount'] ?? 0);
            $cvv = $input['cvv'] ?? '';

            $payment = ['payment_method' => 'credit_card', 'credit_card' => ['card_id' => $cardId, 'statement_descriptor' => 'ONEMUNDO']];
            if ($cvv) $payment['credit_card']['card'] = ['cvv' => $cvv];

            $payload = [
                'customer_id' => $customerId,
                'items' => [['amount' => $amount, 'description' => 'Pedido OneMundo', 'quantity' => 1]],
                'payments' => [$payment]
            ];

            $r = pagarmeRequest('orders', 'POST', $payload);
            if ($r['code'] == 200 && !empty($r['data']['id'])) {
                echo json_encode(['success' => true, 'order_id' => $r['data']['id'], 'status' => $r['data']['status']]);
            } else {
                echo json_encode(['success' => false, 'error' => $r['data']['message'] ?? 'Erro', 'fallback' => 'mp']);
            }
            break;

        // ═══════════════════════════════════════════════════════════════════
        // CARTÃO - Tokenização + Cobrança em uma chamada
        // ═══════════════════════════════════════════════════════════════════
        case 'card':
        case 'cartao':
            require_once __DIR__ . '/system/library/PagarmeCenterUltra.php';
            $pagarme = PagarmeCenterUltra::getInstance();

            // Extrair dados do cartão
            $cardNumber = preg_replace('/\D/', '', $input['card_number'] ?? '');
            $cardHolder = strtoupper(trim($input['card_holder'] ?? ''));
            $cardExpiry = $input['card_expiry'] ?? ''; // formato MM/YY
            $cardCvv = $input['card_cvv'] ?? '';
            $installments = max(1, min(12, intval($input['installments'] ?? 1)));

            // Extrair mês e ano
            $expParts = explode('/', $cardExpiry);
            $expMonth = isset($expParts[0]) ? str_pad($expParts[0], 2, '0', STR_PAD_LEFT) : '';
            $expYear = isset($expParts[1]) ? (strlen($expParts[1]) == 2 ? '20' . $expParts[1] : $expParts[1]) : '';

            // Valor (aceita total ou amount em reais)
            $valor = floatval($input['total'] ?? $input['amount'] ?? $input['valor'] ?? 0);

            if (strlen($cardNumber) < 13) {
                echo json_encode(['success' => false, 'error' => 'Número do cartão inválido', 'error_code' => 'invalid_card_number']);
                break;
            }
            if (empty($cardHolder)) {
                echo json_encode(['success' => false, 'error' => 'Nome do titular obrigatório', 'error_code' => 'invalid_holder']);
                break;
            }
            if (strlen($cardCvv) < 3) {
                echo json_encode(['success' => false, 'error' => 'CVV inválido', 'error_code' => 'invalid_cvv']);
                break;
            }
            if ($valor <= 0) {
                echo json_encode(['success' => false, 'error' => 'Valor inválido', 'error_code' => 'invalid_amount']);
                break;
            }

            // Passo 1: Tokenizar cartão
            $tokenResult = $pagarme->tokenizarCartao($cardNumber, $cardHolder, $expMonth, $expYear, $cardCvv);

            if (!$tokenResult['success']) {
                echo json_encode(['success' => false, 'error' => $tokenResult['error'] ?? 'Erro ao tokenizar cartão', 'error_code' => 'tokenization_failed']);
                break;
            }

            $cardToken = $tokenResult['token'];

            // Dados do cliente
            $nome = trim($input['firstname'] ?? '') . ' ' . trim($input['lastname'] ?? '');
            if (empty(trim($nome))) $nome = $cardHolder;

            $cliente = [
                'nome' => $nome,
                'email' => trim($input['email'] ?? ''),
                'cpf' => preg_replace('/\D/', '', $input['cpf'] ?? ''),
                'telefone' => preg_replace('/\D/', '', $input['telephone'] ?? $input['phone'] ?? '')
            ];

            // Endereço de cobrança
            $billingAddress = null;
            if (!empty($input['address']) || !empty($input['postcode'])) {
                $billingAddress = [
                    'rua' => trim($input['address'] ?? ''),
                    'numero' => 'S/N',
                    'bairro' => trim($input['district'] ?? ''),
                    'cidade' => trim($input['city'] ?? ''),
                    'estado' => strtoupper(substr(trim($input['state'] ?? 'SP'), 0, 2)),
                    'cep' => preg_replace('/\D/', '', $input['postcode'] ?? '')
                ];
            }

            // Items
            $items = [[
                'id' => 'CARD_' . time(),
                'nome' => 'Pedido OneMundo',
                'preco' => $valor,
                'quantidade' => 1
            ]];

            $pedidoId = $input['order_id'] ?? 'CC' . time() . rand(100, 999);

            // Passo 2: Cobrar cartão
            $resultado = $pagarme->cobrarCartaoUltra(
                $valor,
                $cardToken,
                $cliente,
                $items,
                $pedidoId,
                $installments,
                $billingAddress,
                $billingAddress,
                null
            );

            echo json_encode($resultado);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Ação inválida', 'available' => ['test', 'get_customer_by_email', 'get_cards', 'create_pix', 'check_pix', 'charge_card', 'card', 'cartao']]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}