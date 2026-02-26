<?php
/**
 * 🛒 API CHECKOUT MERCADO - INTEGRADO COM CENTRAL ULTRA
 * Suporta: PIX, Cartão de Crédito, Boleto
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

session_start();

// Carregar Central Ultra
$centralPath = $_SERVER['DOCUMENT_ROOT'] . '/system/library/PagarmeCenterUltra.php';
if (!file_exists($centralPath)) {
    die(json_encode(['success' => false, 'error' => 'Central Ultra não instalada']));
}
require_once $centralPath;

// Carregar configurações do .env
require_once dirname(__DIR__) . '/includes/env_loader.php';

// Conexão
try {
    $pdo = getDbConnection();
} catch (PDOException $e) {
    die(json_encode(['success' => false, 'error' => 'Erro de conexão']));
}

$pagarme = PagarmeCenterUltra::getInstance();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ═══════════════════════════════════════════════════════════════════════════════
// GERAR PIX
// ═══════════════════════════════════════════════════════════════════════════════
if ($action === 'pix' || $action === 'gerar_pix') {
    $cliente = [
        'nome' => $_POST['nome'] ?? $_POST['customer_name'] ?? '',
        'email' => $_POST['email'] ?? $_POST['customer_email'] ?? '',
        'cpf' => $_POST['cpf'] ?? $_POST['document'] ?? '',
        'telefone' => $_POST['telefone'] ?? $_POST['phone'] ?? ''
    ];
    
    $valor = floatval($_POST['valor'] ?? $_POST['total'] ?? $_POST['amount'] ?? 0);
    
    // Items do carrinho
    $items = [];
    if (!empty($_POST['items'])) {
        $items = is_array($_POST['items']) ? $_POST['items'] : json_decode($_POST['items'], true);
    }
    if (empty($items)) {
        $items = [['id' => 'PROD1', 'nome' => 'Compra Mercado', 'preco' => $valor, 'quantidade' => 1]];
    }
    
    // Endereço
    $endereco = null;
    if (!empty($_POST['cep'])) {
        $endereco = [
            'cep' => $_POST['cep'] ?? '',
            'rua' => $_POST['rua'] ?? $_POST['address'] ?? '',
            'numero' => $_POST['numero'] ?? $_POST['number'] ?? '',
            'complemento' => $_POST['complemento'] ?? '',
            'bairro' => $_POST['bairro'] ?? $_POST['neighborhood'] ?? '',
            'cidade' => $_POST['cidade'] ?? $_POST['city'] ?? '',
            'estado' => $_POST['estado'] ?? $_POST['state'] ?? ''
        ];
    }
    
    // Anti-fraude
    $antifraud = [
        'latitude' => $_POST['latitude'] ?? $_POST['antifraud_latitude'] ?? null,
        'longitude' => $_POST['longitude'] ?? $_POST['antifraud_longitude'] ?? null
    ];
    
    $pedidoId = 'MKT' . time() . rand(100, 999);
    
    $resultado = $pagarme->gerarPixUltra($valor, $cliente, $items, $pedidoId, $endereco, $antifraud);
    
    if ($resultado['success']) {
        // Salvar pedido no mercado
        salvarPedidoMercado($pdo, $pedidoId, $cliente, $endereco, $items, $valor, 'pix', $resultado);
    }
    
    echo json_encode($resultado);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════════
// COBRAR CARTÃO
// ═══════════════════════════════════════════════════════════════════════════════
if ($action === 'cartao' || $action === 'card' || $action === 'credit_card') {
    $cliente = [
        'nome' => $_POST['nome'] ?? $_POST['customer_name'] ?? '',
        'email' => $_POST['email'] ?? $_POST['customer_email'] ?? '',
        'cpf' => $_POST['cpf'] ?? $_POST['document'] ?? '',
        'telefone' => $_POST['telefone'] ?? $_POST['phone'] ?? ''
    ];

    $valor = floatval($_POST['valor'] ?? $_POST['total'] ?? $_POST['amount'] ?? 0);
    $cardToken = $_POST['card_token'] ?? $_POST['token'] ?? '';
    $parcelas = intval($_POST['parcelas'] ?? $_POST['installments'] ?? 1);

    // Se não tem token, criar token a partir dos dados do cartão
    if (empty($cardToken)) {
        $cardNumber = $_POST['card_number'] ?? '';
        $cardName = $_POST['card_name'] ?? '';
        $cardMonth = $_POST['card_exp_month'] ?? '';
        $cardYear = $_POST['card_exp_year'] ?? '';
        $cardCvv = $_POST['card_cvv'] ?? '';

        if (empty($cardNumber) || empty($cardMonth) || empty($cardYear) || empty($cardCvv)) {
            die(json_encode(['success' => false, 'error' => 'Dados do cartão incompletos']));
        }

        // Tokenizar via Pagar.me API
        $tokenResult = $pagarme->tokenizarCartao($cardNumber, $cardName, $cardMonth, $cardYear, $cardCvv);
        if (!$tokenResult['success']) {
            die(json_encode(['success' => false, 'error' => $tokenResult['error'] ?? 'Erro ao processar cartão']));
        }
        $cardToken = $tokenResult['token'];
    }
    
    // Items
    $items = [];
    if (!empty($_POST['items'])) {
        $items = is_array($_POST['items']) ? $_POST['items'] : json_decode($_POST['items'], true);
    }
    if (empty($items)) {
        $items = [['id' => 'PROD1', 'nome' => 'Compra Mercado', 'preco' => $valor, 'quantidade' => 1]];
    }
    
    // Endereço
    $endereco = null;
    if (!empty($_POST['cep'])) {
        $endereco = [
            'cep' => $_POST['cep'] ?? '',
            'rua' => $_POST['rua'] ?? $_POST['address'] ?? '',
            'numero' => $_POST['numero'] ?? $_POST['number'] ?? '',
            'complemento' => $_POST['complemento'] ?? '',
            'bairro' => $_POST['bairro'] ?? $_POST['neighborhood'] ?? '',
            'cidade' => $_POST['cidade'] ?? $_POST['city'] ?? '',
            'estado' => $_POST['estado'] ?? $_POST['state'] ?? ''
        ];
    }
    
    // Anti-fraude
    $antifraud = [
        'latitude' => $_POST['latitude'] ?? $_POST['antifraud_latitude'] ?? null,
        'longitude' => $_POST['longitude'] ?? $_POST['antifraud_longitude'] ?? null
    ];
    
    $pedidoId = 'MKT' . time() . rand(100, 999);
    
    $resultado = $pagarme->cobrarCartaoUltra(
        $valor, $cardToken, $cliente, $items, $pedidoId, 
        $parcelas, $endereco, $endereco, $antifraud
    );
    
    if ($resultado['success']) {
        salvarPedidoMercado($pdo, $pedidoId, $cliente, $endereco, $items, $valor, 'credit_card', $resultado);
    }
    
    echo json_encode($resultado);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════════
// GERAR BOLETO
// ═══════════════════════════════════════════════════════════════════════════════
if ($action === 'boleto') {
    $cliente = [
        'nome' => $_POST['nome'] ?? '',
        'email' => $_POST['email'] ?? '',
        'cpf' => $_POST['cpf'] ?? '',
        'telefone' => $_POST['telefone'] ?? ''
    ];
    
    $valor = floatval($_POST['valor'] ?? $_POST['total'] ?? 0);
    
    $items = [];
    if (!empty($_POST['items'])) {
        $items = is_array($_POST['items']) ? $_POST['items'] : json_decode($_POST['items'], true);
    }
    if (empty($items)) {
        $items = [['id' => 'PROD1', 'nome' => 'Compra Mercado', 'preco' => $valor, 'quantidade' => 1]];
    }
    
    $endereco = null;
    if (!empty($_POST['cep'])) {
        $endereco = [
            'cep' => $_POST['cep'] ?? '',
            'rua' => $_POST['rua'] ?? '',
            'numero' => $_POST['numero'] ?? '',
            'bairro' => $_POST['bairro'] ?? '',
            'cidade' => $_POST['cidade'] ?? '',
            'estado' => $_POST['estado'] ?? ''
        ];
    }
    
    $pedidoId = 'MKT' . time() . rand(100, 999);
    
    $resultado = $pagarme->gerarBoletoUltra($valor, $cliente, $items, $pedidoId, $endereco);
    
    if ($resultado['success']) {
        salvarPedidoMercado($pdo, $pedidoId, $cliente, $endereco, $items, $valor, 'boleto', $resultado);
    }
    
    echo json_encode($resultado);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════════
// VERIFICAR PAGAMENTO
// ═══════════════════════════════════════════════════════════════════════════════
if ($action === 'verificar' || $action === 'check' || $action === 'status') {
    $chargeId = $_POST['charge_id'] ?? $_GET['charge_id'] ?? '';
    
    if (empty($chargeId)) {
        die(json_encode(['success' => false, 'error' => 'charge_id não informado']));
    }
    
    $resultado = $pagarme->verificarPagamento($chargeId);
    echo json_encode($resultado);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════════
// REEMBOLSO
// ═══════════════════════════════════════════════════════════════════════════════
if ($action === 'reembolso' || $action === 'refund') {
    $chargeId = $_POST['charge_id'] ?? '';
    $valor = isset($_POST['valor']) ? floatval($_POST['valor']) : null;
    
    if (empty($chargeId)) {
        die(json_encode(['success' => false, 'error' => 'charge_id não informado']));
    }
    
    $resultado = $pagarme->reembolsar($chargeId, $valor);
    echo json_encode($resultado);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════════
// GET PUBLIC KEY (para tokenização no frontend)
// ═══════════════════════════════════════════════════════════════════════════════
if ($action === 'public_key' || $action === 'get_key') {
    echo json_encode([
        'success' => true,
        'public_key' => $pagarme->getPublicKey()
    ]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════════
// FUNÇÃO SALVAR PEDIDO
// ═══════════════════════════════════════════════════════════════════════════════
function salvarPedidoMercado($pdo, $pedidoId, $cliente, $endereco, $items, $valor, $metodo, $resultado) {
    try {
        // Criar tabela de pagamentos API se não existir
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS om_checkout_payments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                pedido_id VARCHAR(50) NOT NULL,
                customer_name VARCHAR(100),
                customer_email VARCHAR(100),
                customer_phone VARCHAR(20),
                customer_document VARCHAR(20),
                address_street VARCHAR(200),
                address_number VARCHAR(20),
                address_complement VARCHAR(100),
                address_neighborhood VARCHAR(100),
                address_city VARCHAR(100),
                address_state VARCHAR(2),
                address_zipcode VARCHAR(10),
                items JSON,
                total DECIMAL(10,2),
                payment_method VARCHAR(20),
                payment_status VARCHAR(20) DEFAULT 'pending',
                pagarme_order_id VARCHAR(100),
                pagarme_charge_id VARCHAR(100),
                qr_code TEXT,
                qr_code_url TEXT,
                boleto_url TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_pedido_id (pedido_id),
                INDEX idx_charge_id (pagarme_charge_id),
                INDEX idx_status (payment_status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $stmt = $pdo->prepare("
            INSERT INTO om_checkout_payments
            (pedido_id, customer_name, customer_email, customer_phone, customer_document,
             address_street, address_number, address_complement, address_neighborhood,
             address_city, address_state, address_zipcode, items, total, payment_method,
             pagarme_order_id, pagarme_charge_id, qr_code, qr_code_url, boleto_url)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $pedidoId,
            $cliente['nome'] ?? '',
            $cliente['email'] ?? '',
            $cliente['telefone'] ?? '',
            $cliente['cpf'] ?? '',
            $endereco['rua'] ?? '',
            $endereco['numero'] ?? '',
            $endereco['complemento'] ?? '',
            $endereco['bairro'] ?? '',
            $endereco['cidade'] ?? '',
            $endereco['estado'] ?? '',
            $endereco['cep'] ?? '',
            json_encode($items),
            $valor,
            $metodo,
            $resultado['order_id'] ?? null,
            $resultado['charge_id'] ?? null,
            $resultado['qr_code'] ?? null,
            $resultado['qr_code_url'] ?? null,
            $resultado['boleto_url'] ?? null
        ]);
        
    } catch (Exception $e) {
        error_log("Erro ao salvar pedido mercado: " . $e->getMessage());
    }
}

// Ação não reconhecida
echo json_encode(['success' => false, 'error' => 'Ação não reconhecida', 'actions' => ['pix', 'cartao', 'boleto', 'verificar', 'reembolso', 'public_key']]);