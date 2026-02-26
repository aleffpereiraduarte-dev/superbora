<?php
/**
 * ╔══════════════════════════════════════════════════════════════════════════════╗
 * ║  API CRIAR PAGAMENTO PENDENTE - CHECKOUT                                     ║
 * ╠══════════════════════════════════════════════════════════════════════════════╣
 * ║  Chamar ANTES de gerar PIX ou processar cartão                               ║
 * ║  POST /api/create_payment.php                                                ║
 * ║  Retorna: charge_id para usar no Pagar.me                                    ║
 * ╚══════════════════════════════════════════════════════════════════════════════╝
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once dirname(__DIR__) . '/config/database.php';

try {
    $pdo = getPDO();
} catch (PDOException $e) {
    echo json_encode(array('success' => false, 'error' => 'Erro de conexão'));
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) $input = $_POST;

// Dados obrigatórios
$customer_id = isset($input['customer_id']) ? (int)$input['customer_id'] : 0;
$partner_id = isset($input['partner_id']) ? (int)$input['partner_id'] : 0;
$payment_method = isset($input['payment_method']) ? $input['payment_method'] : 'pix';

if (!$customer_id || !$partner_id) {
    echo json_encode(array('success' => false, 'error' => 'customer_id e partner_id são obrigatórios'));
    exit;
}

try {
    // Buscar carrinho do cliente
    $stmt = $pdo->prepare("
        SELECT c.*, p.name as product_name, p.image
        FROM om_market_cart c
        LEFT JOIN om_market_products_base p ON c.product_id = p.product_id
        WHERE c.customer_id = ? AND c.partner_id = ?
    ");
    $stmt->execute(array($customer_id, $partner_id));
    $cart_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($cart_items)) {
        echo json_encode(array('success' => false, 'error' => 'Carrinho vazio'));
        exit;
    }
    
    // Buscar dados do cliente
    $stmt = $pdo->prepare("SELECT * FROM oc_customer WHERE customer_id = ?");
    $stmt->execute(array($customer_id));
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$customer) {
        echo json_encode(array('success' => false, 'error' => 'Cliente não encontrado'));
        exit;
    }
    
    // Buscar parceiro
    $stmt = $pdo->prepare("SELECT * FROM om_market_partners WHERE partner_id = ?");
    $stmt->execute(array($partner_id));
    $partner = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$partner) {
        echo json_encode(array('success' => false, 'error' => 'Parceiro não encontrado'));
        exit;
    }
    
    // Calcular totais
    $subtotal = 0;
    $items_data = array();
    foreach ($cart_items as $item) {
        $item_total = $item['price'] * $item['quantity'];
        $subtotal += $item_total;
        $items_data[] = array(
            'product_id' => $item['product_id'],
            'name' => $item['product_name'],
            'quantity' => $item['quantity'],
            'price' => $item['price'],
            'total' => $item_total
        );
    }
    
    $delivery_fee = isset($input['delivery_fee']) ? (float)$input['delivery_fee'] : 9.90;
    $discount = isset($input['discount']) ? (float)$input['discount'] : 0;
    $total = $subtotal + $delivery_fee - $discount;
    
    // Dados de entrega
    $shipping = array(
        'address' => isset($input['shipping_address']) ? $input['shipping_address'] : '',
        'number' => isset($input['shipping_number']) ? $input['shipping_number'] : '',
        'complement' => isset($input['shipping_complement']) ? $input['shipping_complement'] : '',
        'neighborhood' => isset($input['shipping_neighborhood']) ? $input['shipping_neighborhood'] : '',
        'city' => isset($input['shipping_city']) ? $input['shipping_city'] : '',
        'state' => isset($input['shipping_state']) ? $input['shipping_state'] : '',
        'cep' => isset($input['shipping_cep']) ? $input['shipping_cep'] : ''
    );
    
    // Criar charge_id único
    $charge_id = 'ch_' . bin2hex(random_bytes(16));
    
    // Montar session_data completo
    $session_data = array(
        'customer' => array(
            'id' => $customer_id,
            'name' => trim($customer['firstname'] . ' ' . $customer['lastname']),
            'email' => $customer['email'],
            'phone' => $customer['telephone']
        ),
        'partner' => array(
            'id' => $partner_id,
            'name' => $partner['name']
        ),
        'items' => $items_data,
        'totals' => array(
            'subtotal' => $subtotal,
            'delivery_fee' => $delivery_fee,
            'discount' => $discount,
            'total' => $total
        ),
        'shipping' => $shipping,
        'payment_method' => $payment_method,
        'created_at' => date('Y-m-d H:i:s')
    );
    
    // Inserir em om_payments_pending
    $stmt = $pdo->prepare("
        INSERT INTO om_payments_pending (
            charge_id, customer_id, partner_id, 
            amount, payment_method, session_data,
            status, expires_at, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, 'pending', DATE_ADD(NOW(), INTERVAL 30 MINUTE), NOW())
    ");
    $stmt->execute(array(
        $charge_id,
        $customer_id,
        $partner_id,
        $total,
        $payment_method,
        json_encode($session_data)
    ));
    
    echo json_encode(array(
        'success' => true,
        'charge_id' => $charge_id,
        'amount' => $total,
        'amount_cents' => (int)($total * 100),
        'expires_at' => date('Y-m-d H:i:s', strtotime('+30 minutes')),
        'customer' => array(
            'name' => $session_data['customer']['name'],
            'email' => $session_data['customer']['email'],
            'phone' => $session_data['customer']['phone']
        ),
        'items_count' => count($items_data)
    ));
    
} catch (Exception $e) {
    echo json_encode(array('success' => false, 'error' => $e->getMessage()));
}
