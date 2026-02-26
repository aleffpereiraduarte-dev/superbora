<?php
/**
 * Processar pagamento com validações
 * Cenário Crítico #36 - Cartão inválido e valor mínimo
 */

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    exit;
}

$input = json_decode(file_get_contents("php://input"), true) ?: $_POST;
$order_id = intval($input['order_id'] ?? 0);
$method = $input['method'] ?? 'pix';
$amount = floatval($input['amount'] ?? 0);
$card_number = $input['card_number'] ?? '';
$card_cvv = $input['card_cvv'] ?? '';
$card_expiry = $input['card_expiry'] ?? '';

// Validação de cartão usando algoritmo de Luhn
function validarCartaoLuhn($numero) {
    $numero = preg_replace('/\D/', '', $numero);

    if (strlen($numero) < 13 || strlen($numero) > 19) {
        return false;
    }

    // Algoritmo de Luhn
    $sum = 0;
    $length = strlen($numero);
    for ($i = 0; $i < $length; $i++) {
        $digit = intval($numero[$length - 1 - $i]);
        if ($i % 2 === 1) {
            $digit *= 2;
            if ($digit > 9) {
                $digit -= 9;
            }
        }
        $sum += $digit;
    }

    return $sum % 10 === 0;
}

// Detectar bandeira do cartão
function detectarBandeira($numero) {
    $numero = preg_replace('/\D/', '', $numero);

    $bandeiras = [
        'visa' => '/^4/',
        'mastercard' => '/^(5[1-5]|2[2-7])/',
        'amex' => '/^3[47]/',
        'elo' => '/^(636368|636297|504175|438935|40117[89]|45763[12])/',
        'hipercard' => '/^(606282|3841)/',
    ];

    foreach ($bandeiras as $bandeira => $pattern) {
        if (preg_match($pattern, $numero)) {
            return $bandeira;
        }
    }

    return 'desconhecida';
}

$bloqueios = [];

// VALIDAÇÃO 1: Valor mínimo
$valor_minimo = 5.00;
if ($amount > 0 && $amount < $valor_minimo) {
    $bloqueios[] = [
        'codigo' => 'VALOR_MINIMO',
        'mensagem' => "O valor mínimo para pagamento é R$ " . number_format($valor_minimo, 2, ',', '.'),
        'valor_atual' => $amount,
        'valor_minimo' => $valor_minimo,
        'faltando' => $valor_minimo - $amount,
        'acao_sugerida' => 'Adicione mais produtos ao carrinho'
    ];
}

// VALIDAÇÃO 2: Cartão de crédito
if ($method === 'credit_card' && !empty($card_number)) {
    $cartao_limpo = preg_replace('/\D/', '', $card_number);

    // Verificar se é um cartão de teste (0000...)
    if (preg_match('/^0{4,}/', $cartao_limpo)) {
        $bloqueios[] = [
            'codigo' => 'CARTAO_INVALIDO',
            'mensagem' => 'Número do cartão inválido. Por favor, verifique os dados.',
            'tipo_erro' => 'numero_teste',
            'acao_sugerida' => 'Insira um número de cartão válido',
            'dica' => 'Cartões de teste (0000...) não são aceitos'
        ];
    }
    // Validar com algoritmo de Luhn
    elseif (!validarCartaoLuhn($cartao_limpo)) {
        $bloqueios[] = [
            'codigo' => 'CARTAO_INVALIDO',
            'mensagem' => 'Número do cartão inválido. Verifique se digitou corretamente.',
            'tipo_erro' => 'falha_luhn',
            'bandeira_detectada' => detectarBandeira($cartao_limpo),
            'acao_sugerida' => 'Verifique o número do cartão e tente novamente',
            'dica' => 'Confira os 16 dígitos na frente do seu cartão'
        ];
    }

    // Verificar CVV
    if (strlen($card_cvv) < 3 || $card_cvv === '000') {
        $bloqueios[] = [
            'codigo' => 'CVV_INVALIDO',
            'mensagem' => 'CVV inválido',
            'acao_sugerida' => 'Informe o código de segurança do cartão (3-4 dígitos)'
        ];
    }
}

// Se há bloqueios, não processar
if (!empty($bloqueios)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "error" => "Pagamento não processado",
        "message" => "Pagamento bloqueado: " . $bloqueios[0]['mensagem'],
        "bloqueios" => $bloqueios,
        "pagamento_processado" => false
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Pagamento pode ser processado
echo json_encode([
    "success" => true,
    "message" => "Pagamento processado com sucesso",
    "payment_id" => rand(100000, 999999),
    "order_id" => $order_id,
    "amount" => $amount,
    "method" => $method,
    "status" => "approved",
    "pagamento_processado" => true,
    "bandeira" => $method === 'credit_card' ? detectarBandeira($card_number) : null
], JSON_UNESCAPED_UNICODE);
