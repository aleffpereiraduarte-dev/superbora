<?php
/**
 * POST /api/mercado/admin/order-create-manual.php
 *
 * Cria um pedido manual em nome de um cliente a partir do painel administrativo.
 *
 * Body: {
 *   customer_id: int,
 *   partner_id: int,
 *   items: [{product_id: int, quantity: int}],
 *   address_id: int,
 *   payment_method: string,
 *   notes?: string
 * }
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmPricing.php";
require_once __DIR__ . "/../helpers/notify.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();
    $admin_id = (int)$payload['uid'];

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') response(false, null, "Metodo nao permitido", 405);

    $input = getInput();
    $customer_id = (int)($input['customer_id'] ?? 0);
    $partner_id = (int)($input['partner_id'] ?? 0);
    $items = $input['items'] ?? [];
    $address_id = (int)($input['address_id'] ?? 0);
    $payment_method = strip_tags(trim($input['payment_method'] ?? ''));
    $notes = strip_tags(trim($input['notes'] ?? ''));

    // Validation
    if (!$customer_id) response(false, null, "customer_id obrigatorio", 400);
    if (!$partner_id) response(false, null, "partner_id obrigatorio", 400);
    if (empty($items) || !is_array($items)) response(false, null, "items obrigatorio (array com product_id e quantity)", 400);
    if (!$payment_method) response(false, null, "payment_method obrigatorio", 400);

    // Validate customer exists
    $stmt = $db->prepare("SELECT customer_id, name, email, phone FROM om_customers WHERE customer_id = ?");
    $stmt->execute([$customer_id]);
    $customer = $stmt->fetch();
    if (!$customer) response(false, null, "Cliente nao encontrado", 404);

    // Validate partner exists and is active
    $stmt = $db->prepare("SELECT partner_id, name, phone, email FROM om_market_partners WHERE partner_id = ?");
    $stmt->execute([$partner_id]);
    $partner = $stmt->fetch();
    if (!$partner) response(false, null, "Parceiro nao encontrado", 404);

    // Validate address if provided
    $address = null;
    if ($address_id) {
        $stmt = $db->prepare("
            SELECT address_id, street, number, complement, neighborhood, city, state, zipcode, lat, lng
            FROM om_customer_addresses
            WHERE address_id = ? AND customer_id = ? AND is_active = '1'
        ");
        $stmt->execute([$address_id, $customer_id]);
        $address = $stmt->fetch();
        if (!$address) response(false, null, "Endereco nao encontrado ou nao pertence ao cliente", 404);
    }

    // Validate and fetch product details
    $product_ids = array_map(fn($i) => (int)($i['product_id'] ?? 0), $items);
    $product_ids = array_filter($product_ids);
    if (empty($product_ids)) response(false, null, "Nenhum product_id valido informado", 400);

    $placeholders = implode(',', array_fill(0, count($product_ids), '?'));
    $stmt = $db->prepare("
        SELECT product_id, name, price, special_price, quantity as estoque, image
        FROM om_market_products
        WHERE product_id IN ({$placeholders}) AND partner_id = ?
    ");
    $queryParams = array_merge($product_ids, [$partner_id]);
    $stmt->execute($queryParams);
    $products = $stmt->fetchAll();

    // Index products by ID
    $productMap = [];
    foreach ($products as $p) {
        $productMap[(int)$p['product_id']] = $p;
    }

    // Validate all items have matching products and calculate totals
    $subtotal = 0;
    $orderItems = [];
    foreach ($items as $item) {
        $pid = (int)($item['product_id'] ?? 0);
        $qty = max(1, (int)($item['quantity'] ?? 1));

        if (!isset($productMap[$pid])) {
            response(false, null, "Produto #{$pid} nao encontrado neste parceiro", 404);
        }

        $product = $productMap[$pid];

        // Check stock
        if ((int)$product['estoque'] > 0 && $qty > (int)$product['estoque']) {
            response(false, null, "'{$product['name']}' - estoque insuficiente (disponivel: {$product['estoque']})", 400);
        }

        // Use special price if active
        $price = ((float)($product['special_price'] ?? 0) > 0)
            ? (float)$product['special_price']
            : (float)$product['price'];

        $itemTotal = round($price * $qty, 2);
        $subtotal += $itemTotal;

        $orderItems[] = [
            'product_id' => $pid,
            'name' => $product['name'],
            'quantity' => $qty,
            'price' => $price,
            'total' => $itemTotal,
            'image' => $product['image'] ?? null,
        ];
    }

    // Calculate fees
    $service_fee = OmPricing::TAXA_SERVICO;
    $delivery_fee = 0; // Admin manual orders default to zero delivery fee — can be adjusted
    $total = round($subtotal + $service_fee + $delivery_fee, 2);

    // Build delivery address string
    $delivery_address = '';
    $shipping_address = '';
    $shipping_city = '';
    $shipping_state = '';
    $shipping_cep = '';
    $shipping_lat = null;
    $shipping_lng = null;
    if ($address) {
        $delivery_address = "{$address['street']}, {$address['number']}" .
            ($address['complement'] ? " - {$address['complement']}" : '') .
            " - {$address['neighborhood']}, {$address['city']}/{$address['state']}";
        $shipping_address = "{$address['street']}, {$address['number']}";
        $shipping_city = $address['city'];
        $shipping_state = $address['state'];
        $shipping_cep = $address['zipcode'] ?? '';
        $shipping_lat = $address['lat'];
        $shipping_lng = $address['lng'];
    }

    $codigo_entrega = strtoupper(bin2hex(random_bytes(3)));

    // Determine initial status
    $initial_status = 'pendente';
    $payment_status = 'pendente';
    if (in_array($payment_method, ['pay_on_delivery', 'dinheiro', 'maquininha'])) {
        $initial_status = 'confirmado';
        $payment_status = 'pendente_entrega';
    }

    $db->beginTransaction();

    // Create order
    $stmt = $db->prepare("
        INSERT INTO om_market_orders (
            partner_id, customer_id,
            customer_name, customer_phone, customer_email,
            status, subtotal, delivery_fee, service_fee, total,
            delivery_address, shipping_address, shipping_city, shipping_state, shipping_cep,
            shipping_lat, shipping_lng,
            notes, codigo_entrega, forma_pagamento, payment_status,
            source, created_by_admin,
            date_added
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'admin_manual', ?, NOW())
        RETURNING order_id
    ");
    $stmt->execute([
        $partner_id, $customer_id,
        $customer['name'], $customer['phone'], $customer['email'],
        $initial_status, $subtotal, $delivery_fee, $service_fee, $total,
        $delivery_address, $shipping_address, $shipping_city, $shipping_state, $shipping_cep,
        $shipping_lat, $shipping_lng,
        $notes, $codigo_entrega, $payment_method, $payment_status,
        $admin_id,
    ]);

    $row = $stmt->fetch();
    $order_id = (int)$row['order_id'];

    // Generate order number
    $order_number = 'SB' . str_pad($order_id, 5, '0', STR_PAD_LEFT);
    $db->prepare("UPDATE om_market_orders SET order_number = ? WHERE order_id = ?")->execute([$order_number, $order_id]);

    // Insert order items
    $stmtItem = $db->prepare("
        INSERT INTO om_market_order_items (order_id, product_id, name, quantity, price, total)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    foreach ($orderItems as $oi) {
        $stmtItem->execute([$order_id, $oi['product_id'], $oi['name'], $oi['quantity'], $oi['price'], $oi['total']]);
    }

    // Decrement stock
    $stmtStock = $db->prepare("UPDATE om_market_products SET quantity = quantity - ? WHERE product_id = ? AND quantity >= ?");
    foreach ($orderItems as $oi) {
        $stmtStock->execute([$oi['quantity'], $oi['product_id'], $oi['quantity']]);
    }

    // Timeline entry
    $db->prepare("
        INSERT INTO om_order_timeline (order_id, status, description, actor_type, actor_id, created_at)
        VALUES (?, ?, ?, 'admin', ?, NOW())
    ")->execute([$order_id, $initial_status, "Pedido criado manualmente pelo admin", $admin_id]);

    $db->commit();

    // Notify customer
    try {
        notifyCustomer(
            $db,
            $customer_id,
            "Novo pedido {$order_number}",
            "Um pedido foi criado para voce pelo suporte SuperBora. Total: R$ " . number_format($total, 2, ',', '.'),
            '/pedidos',
            ['order_id' => $order_id, 'type' => 'order_created']
        );
    } catch (Exception $e) {
        error_log("[order-create-manual] Notify error: " . $e->getMessage());
    }

    // Audit log
    om_audit()->log(
        'order_create_manual',
        'order',
        $order_id,
        null,
        ['customer_id' => $customer_id, 'partner_id' => $partner_id, 'total' => $total, 'items_count' => count($orderItems)],
        "Pedido {$order_number} criado manualmente pelo admin para cliente '{$customer['name']}'"
    );

    response(true, [
        'order_id' => $order_id,
        'order_number' => $order_number,
        'status' => $initial_status,
        'subtotal' => $subtotal,
        'service_fee' => $service_fee,
        'delivery_fee' => $delivery_fee,
        'total' => $total,
        'items_count' => count($orderItems),
    ], "Pedido criado com sucesso");

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log("[admin/order-create-manual] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
