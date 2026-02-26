<?php
/**
 * API: Finalizar Pedido
 * Cria pedido apos pagamento confirmado (PIX ou Cartao)
 * Adaptado para tabelas om_market_*
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

require_once __DIR__ . '/../../includes/om_bootstrap.php';

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Dados invalidos']);
    exit;
}

$paymentMethod = $input['payment_method'] ?? '';
$paymentIntentId = $input['payment_intent_id'] ?? '';
$chargeId = $input['charge_id'] ?? '';
$total = floatval($input['total'] ?? 0);
$shippingCost = floatval($input['shipping_cost'] ?? 0);
$shippingMethod = $input['shipping_method'] ?? 'pac';

// Validacoes
if (empty($paymentMethod)) {
    echo json_encode(['success' => false, 'error' => 'Metodo de pagamento nao informado']);
    exit;
}

if ($paymentMethod === 'cartao' && empty($paymentIntentId)) {
    echo json_encode(['success' => false, 'error' => 'Payment Intent ID nao informado']);
    exit;
}

if ($paymentMethod === 'pix' && empty($chargeId)) {
    echo json_encode(['success' => false, 'error' => 'Charge ID do PIX nao informado']);
    exit;
}

if ($total <= 0) {
    echo json_encode(['success' => false, 'error' => 'Total invalido']);
    exit;
}

try {
    $db = om_db();
    $cart = om_cart();
    $customer = om_customer();

    // Verificar carrinho
    if (!$cart->hasProducts()) {
        echo json_encode(['success' => false, 'error' => 'Carrinho vazio']);
        exit;
    }

    // Dados do cliente
    $customerId = $customer->isLogged() ? $customer->getId() : 0;
    $customerName = trim($customer->getFirstName() . ' ' . $customer->getLastName()) ?: 'Cliente';
    
    // Endereco
    $address = $customer->getAddress() ?: [];
    $addressJson = json_encode($address);

    // Calcular subtotal
    $subtotal = $total - $shippingCost;

    // Contar itens
    $products = $cart->getProducts();
    $itemsCount = count($products);

    // Iniciar transacao
    $db->begin_transaction();

    // Inserir pedido na tabela om_market_orders
    $stmt = $db->prepare("
        INSERT INTO om_market_orders (
            customer_id, partner_id, shopper_id, status,
            subtotal, delivery_fee, total,
            customer_name, items_count, address,
            created_at, date_added
        ) VALUES (?, NULL, NULL, 'paid', ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");

    $stmt->bind_param(
        "idddsss",
        $customerId,
        $subtotal,
        $shippingCost,
        $total,
        $customerName,
        $itemsCount,
        $addressJson
    );

    if (!$stmt->execute()) {
        throw new Exception('Erro ao criar pedido: ' . $stmt->error);
    }

    $orderId = $db->insert_id;

    // Inserir itens do pedido
    foreach ($products as $product) {
        $stmtItem = $db->prepare("
            INSERT INTO om_market_order_items (
                order_id, product_id, name, quantity, price, total, status, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, 'paid', NOW())
        ");

        $productId = $product['product_id'] ?? 0;
        $productName = $product['name'] ?? 'Produto';
        $quantity = $product['quantity'] ?? 1;
        $price = $product['price'] ?? 0;
        $productTotal = $product['total'] ?? ($price * $quantity);

        $stmtItem->bind_param(
            "iisidd",
            $orderId,
            $productId,
            $productName,
            $quantity,
            $price,
            $productTotal
        );
        $stmtItem->execute();
    }

    // Registrar evento de pagamento (usar coluna 'message' em vez de 'event_data')
    $paymentRef = $paymentMethod === 'pix' ? $chargeId : $paymentIntentId;
    $eventMessage = sprintf(
        "Pagamento %s confirmado. Ref: %s. Total: R$ %.2f. Frete: R$ %.2f (%s)",
        $paymentMethod === 'pix' ? 'PIX' : 'Cartao',
        $paymentRef,
        $total,
        $shippingCost,
        strtoupper($shippingMethod)
    );
    $eventCreatedBy = 'api_pagamento';

    $stmtEvent = $db->prepare("
        INSERT INTO om_market_order_events (
            order_id, event_type, message, created_by, created_at
        ) VALUES (?, 'payment_confirmed', ?, ?, NOW())
    ");
    $stmtEvent->bind_param("iss", $orderId, $eventMessage, $eventCreatedBy);
    $stmtEvent->execute();

    // Limpar carrinho
    $cart->clear();

    $db->commit();

    // Log do pagamento
    om_log("Pedido #$orderId criado. Pagamento: $paymentMethod ($paymentRef). Total: R$$total", 'info');

    // Resposta de sucesso
    echo json_encode([
        'success' => true,
        'order_id' => $orderId,
        'total' => $total,
        'payment_method' => $paymentMethod === 'pix' ? 'PIX' : 'Cartao de Credito',
        'redirect' => '/pedido-confirmado.php?id=' . $orderId
    ]);

} catch (Exception $e) {
    if (isset($db)) {
        $db->rollback();
    }

    om_log('Erro ao finalizar pedido: ' . $e->getMessage(), 'error');

    echo json_encode([
        'success' => false,
        'error' => 'Erro ao processar pedido. Tente novamente.'
    ]);
}
