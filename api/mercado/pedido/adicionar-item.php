<?php
/**
 * POST /api/mercado/pedido/adicionar-item.php
 * Adds an item to an existing order (within 30-min window, BoraUm delivery only).
 * Body: { order_id, product_id, quantity }
 */
require_once __DIR__ . "/../config/database.php";
setCorsHeaders();
require_once dirname(__DIR__, 2) . "/rate-limit/RateLimiter.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmPricing.php";
require_once dirname(__DIR__, 3) . "/includes/classes/PusherService.php";
require_once __DIR__ . "/../helpers/notify.php";

if (!RateLimiter::check(15, 60)) {
    exit;
}

try {
    $input = getInput();
    $db = getDB();
    $customer_id = requireCustomerAuth();

    $order_id = (int)($input['order_id'] ?? 0);
    $product_id = (int)($input['product_id'] ?? 0);
    $quantity = max(1, (int)($input['quantity'] ?? 1));

    if (!$order_id || !$product_id) {
        response(false, null, "order_id e product_id obrigatorios", 400);
    }

    $db->beginTransaction();

    // Lock order row and validate
    $orderStmt = $db->prepare("
        SELECT order_id, partner_id, customer_id, status, subtotal, delivery_fee,
               service_fee, tip_amount, coupon_discount, loyalty_discount, cashback_discount,
               total, order_number, delivery_type, is_pickup, date_added
        FROM om_market_orders
        WHERE order_id = ? FOR UPDATE
    ");
    $orderStmt->execute([$order_id]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order || (int)$order['customer_id'] !== $customer_id) {
        $db->rollBack();
        response(false, null, "Pedido nao encontrado", 404);
    }

    // Validate: BoraUm delivery only
    if ($order['is_pickup'] || $order['delivery_type'] !== 'boraum') {
        $db->rollBack();
        response(false, null, "Adicionar itens disponivel apenas para entregas BoraUm", 400);
    }

    // Validate: status must be early
    $addableStatuses = ['pendente', 'confirmado', 'aceito'];
    if (!in_array($order['status'], $addableStatuses)) {
        $db->rollBack();
        response(false, null, "Pedido nao pode ser editado (status: {$order['status']})", 400);
    }

    // Validate: 30-minute window
    $createdAt = strtotime($order['date_added']);
    $limit = $createdAt + (30 * 60);
    if (time() > $limit) {
        $db->rollBack();
        response(false, null, "Tempo limite para adicionar itens excedido", 400);
    }

    // Lock and validate product stock
    $prodStmt = $db->prepare("
        SELECT product_id, name, price, special_price, quantity AS stock, partner_id
        FROM om_market_products
        WHERE product_id = ? FOR UPDATE
    ");
    $prodStmt->execute([$product_id]);
    $product = $prodStmt->fetch(PDO::FETCH_ASSOC);

    if (!$product || (int)$product['partner_id'] !== (int)$order['partner_id']) {
        $db->rollBack();
        response(false, null, "Produto nao encontrado nesta loja", 404);
    }

    if ($quantity > (int)$product['stock']) {
        $db->rollBack();
        response(false, null, "Estoque insuficiente (disponivel: {$product['stock']})", 400);
    }

    // Determine price
    $price = ($product['special_price'] && (float)$product['special_price'] > 0 && (float)$product['special_price'] < (float)$product['price'])
        ? (float)$product['special_price'] : (float)$product['price'];
    $itemTotal = round($price * $quantity, 2);

    // Check if this product already exists in the order
    $existStmt = $db->prepare("SELECT item_id, quantity, price FROM om_market_order_items WHERE order_id = ? AND product_id = ?");
    $existStmt->execute([$order_id, $product_id]);
    $existingItem = $existStmt->fetch(PDO::FETCH_ASSOC);

    if ($existingItem) {
        // Update quantity
        $newQty = (int)$existingItem['quantity'] + $quantity;
        $newTotal = round($price * $newQty, 2);
        $db->prepare("UPDATE om_market_order_items SET quantity = ?, total = ? WHERE item_id = ?")
           ->execute([$newQty, $newTotal, $existingItem['item_id']]);
    } else {
        // Insert new item
        $db->prepare("INSERT INTO om_market_order_items (order_id, product_id, name, quantity, price, total) VALUES (?, ?, ?, ?, ?, ?)")
           ->execute([$order_id, $product_id, $product['name'], $quantity, $price, $itemTotal]);
    }

    // Decrement stock
    $stockStmt = $db->prepare("UPDATE om_market_products SET quantity = quantity - ? WHERE product_id = ? AND quantity >= ?");
    $stockStmt->execute([$quantity, $product_id, $quantity]);
    if ($stockStmt->rowCount() === 0) {
        $db->rollBack();
        response(false, null, "Estoque insuficiente", 400);
    }

    // Recalculate order totals
    $totalsStmt = $db->prepare("SELECT COALESCE(SUM(total), 0) as new_subtotal FROM om_market_order_items WHERE order_id = ?");
    $totalsStmt->execute([$order_id]);
    $newSubtotal = round((float)$totalsStmt->fetchColumn(), 2);

    $newTotal = max(0, $newSubtotal
        + (float)$order['delivery_fee']
        + (float)($order['service_fee'] ?? OmPricing::TAXA_SERVICO)
        + (float)$order['tip_amount']
        - (float)$order['coupon_discount']
        - (float)$order['loyalty_discount']
        - (float)$order['cashback_discount']
    );

    $db->prepare("UPDATE om_market_orders SET subtotal = ?, total = ?, date_modified = NOW() WHERE order_id = ?")
       ->execute([$newSubtotal, round($newTotal, 2), $order_id]);

    $db->commit();

    // Notify partner
    $orderNumber = $order['order_number'];
    try {
        notifyPartner($db, (int)$order['partner_id'],
            "Item adicionado ao pedido #{$orderNumber}",
            "{$quantity}x {$product['name']} - Novo total: R$ " . number_format($newTotal, 2, ',', '.'),
            '/painel/mercado/pedidos.php'
        );
    } catch (Exception $e) {
        error_log("[adicionar-item] notifyPartner erro: " . $e->getMessage());
    }

    try {
        PusherService::orderUpdate($order_id, [
            'action' => 'item_added',
            'product_name' => $product['name'],
            'quantity' => $quantity,
            'new_total' => round($newTotal, 2),
        ]);
    } catch (Exception $e) {
        error_log("[adicionar-item] Pusher erro: " . $e->getMessage());
    }

    response(true, [
        'order_id' => $order_id,
        'product_name' => $product['name'],
        'quantity_added' => $quantity,
        'item_price' => $price,
        'item_total' => $itemTotal,
        'new_subtotal' => $newSubtotal,
        'new_total' => round($newTotal, 2),
    ]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("[adicionar-item] Erro: " . $e->getMessage());
    response(false, null, "Erro ao adicionar item", 500);
}
