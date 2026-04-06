<?php
/**
 * POST /api/mercado/customer/order-modify.php
 * Customer modifies an existing order (add/remove/update item quantities).
 * Allowed when status is 'pendente', 'confirmado', 'aceito', or 'preparando'.
 * For 'aceito'/'preparando': 10-minute window from order creation enforced.
 *
 * Body: {
 *   "order_id": 123,
 *   "items": [
 *     { "product_id": 10, "quantity": 2, "action": "update" },
 *     { "product_id": 15, "quantity": 1, "action": "add" },
 *     { "product_id": 11, "quantity": 0, "action": "remove" }
 *   ],
 *   "note": "Sem cebola, por favor"
 * }
 *
 * action: "add" = add new item, "remove" = remove item, "update" = change qty
 * If action is omitted, defaults to "update".
 * Items with quantity 0 or action "remove" are removed from the order.
 */
require_once __DIR__ . "/../config/database.php";
setCorsHeaders();
require_once dirname(__DIR__, 3) . "/includes/classes/OmPricing.php";
require_once __DIR__ . "/../helpers/notify.php";
require_once __DIR__ . '/../helpers/ws-customer-broadcast.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, "Metodo nao permitido", 405);
}

$ct = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
if (stripos($ct, 'application/json') === false) {
    response(false, null, "Content-Type deve ser application/json", 400);
}

try {
    $db = getDB();
    $customerId = requireCustomerAuth($db);

    $input = getInput();
    $orderId = (int)($input['order_id'] ?? 0);
    $itemChanges = $input['items'] ?? null;
    $note = isset($input['note']) ? trim(substr((string)$input['note'], 0, 1000)) : null;

    if (!$orderId) {
        response(false, null, "order_id obrigatorio", 400);
    }

    if (!is_array($itemChanges) || empty($itemChanges)) {
        response(false, null, "items obrigatorio (array de {product_id, quantity, action})", 400);
    }

    $db->beginTransaction();

    // Lock order row and validate ownership + status
    $orderStmt = $db->prepare("
        SELECT order_id, partner_id, customer_id, status, subtotal, delivery_fee,
               service_fee, tip_amount, coupon_discount, loyalty_discount, cashback_discount,
               total, order_number, delivery_type, is_pickup, date_added, notes,
               coupon_id, forma_pagamento, distancia_km
        FROM om_market_orders
        WHERE order_id = ? FOR UPDATE
    ");
    $orderStmt->execute([$orderId]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order || (int)$order['customer_id'] !== $customerId) {
        $db->rollBack();
        response(false, null, "Pedido nao encontrado", 404);
    }

    // Validate status
    $editableStatuses = ['pendente', 'confirmado', 'aceito', 'preparando'];
    if (!in_array($order['status'], $editableStatuses)) {
        $db->rollBack();
        response(false, null, "Pedido nao pode ser modificado (status atual: {$order['status']})", 409);
    }

    // For aceito/preparando: enforce 10-minute window
    $timeRemaining = null;
    if (in_array($order['status'], ['aceito', 'preparando'])) {
        $createdAt = strtotime($order['date_added']);
        $timeLimitSeconds = 10 * 60;
        $elapsed = time() - $createdAt;
        if ($elapsed > $timeLimitSeconds) {
            $db->rollBack();
            response(false, null, "Tempo para modificacao expirou. Limite de 10 minutos apos o pedido.", 409);
        }
        $timeRemaining = $timeLimitSeconds - $elapsed;
    }

    $partnerId = (int)$order['partner_id'];

    // Fetch current order items
    $currentItemsStmt = $db->prepare("
        SELECT item_id, product_id, quantity, price
        FROM om_market_order_items
        WHERE order_id = ?
    ");
    $currentItemsStmt->execute([$orderId]);
    $currentItems = $currentItemsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Build map of current items: product_id => { item_id, qty, price }
    $currentMap = [];
    foreach ($currentItems as $ci) {
        $currentMap[(int)$ci['product_id']] = [
            'item_id' => (int)$ci['item_id'],
            'quantity' => (int)$ci['quantity'],
            'price' => (float)$ci['price'],
        ];
    }

    // Parse changes into final item set: start with current items, apply changes
    $finalItems = $currentMap; // product_id => { quantity, price }
    $newProductIds = [];

    foreach ($itemChanges as $change) {
        $pid = (int)($change['product_id'] ?? 0);
        $qty = (int)($change['quantity'] ?? 0);
        $action = strtolower(trim($change['action'] ?? 'update'));

        if ($pid <= 0) {
            $db->rollBack();
            response(false, null, "product_id invalido em items", 400);
        }

        if ($action === 'remove' || $qty <= 0) {
            // Remove item
            unset($finalItems[$pid]);
        } elseif ($action === 'add') {
            // Add new item or increase existing
            if ($qty > 99) $qty = 99;
            if (isset($finalItems[$pid])) {
                $finalItems[$pid]['quantity'] = $qty;
            } else {
                $finalItems[$pid] = ['quantity' => $qty, 'price' => 0]; // Price loaded below
                $newProductIds[] = $pid;
            }
        } else {
            // Update quantity
            if ($qty > 99) $qty = 99;
            if (isset($finalItems[$pid])) {
                $finalItems[$pid]['quantity'] = $qty;
            } else {
                $finalItems[$pid] = ['quantity' => $qty, 'price' => 0];
                $newProductIds[] = $pid;
            }
        }
    }

    if (empty($finalItems)) {
        $db->rollBack();
        response(false, null, "Pedido nao pode ficar sem itens. Para cancelar, use a opcao de cancelamento.", 400);
    }

    // Load product data for all final items (for price + stock validation)
    $allProductIds = array_keys($finalItems);
    $placeholders = implode(',', array_fill(0, count($allProductIds), '?'));
    $prodStmt = $db->prepare("
        SELECT product_id, name, price, special_price, quantity AS stock, partner_id, status
        FROM om_market_products
        WHERE product_id IN ($placeholders) FOR UPDATE
    ");
    $prodStmt->execute($allProductIds);
    $products = $prodStmt->fetchAll(PDO::FETCH_ASSOC);

    $productMap = [];
    foreach ($products as $p) {
        $productMap[(int)$p['product_id']] = $p;
    }

    // Validate each item
    $stockErrors = [];
    foreach ($finalItems as $pid => $item) {
        if (!isset($productMap[$pid])) {
            $db->rollBack();
            response(false, null, "Produto #{$pid} nao encontrado", 404);
        }
        $prod = $productMap[$pid];
        if ((int)$prod['partner_id'] !== $partnerId) {
            $db->rollBack();
            response(false, null, "Produto #{$pid} nao pertence a esta loja", 400);
        }

        $alreadyReserved = isset($currentMap[$pid]) ? $currentMap[$pid]['quantity'] : 0;
        $availableStock = (int)$prod['stock'] + $alreadyReserved;
        if ($item['quantity'] > $availableStock) {
            $stockErrors[] = "'{$prod['name']}' - estoque insuficiente (disponivel: {$availableStock})";
        }
    }

    if (!empty($stockErrors)) {
        $db->rollBack();
        response(false, ['stock_errors' => $stockErrors], "Estoque insuficiente: " . $stockErrors[0], 400);
    }

    // Restore stock for all current items
    foreach ($currentItems as $ci) {
        $db->prepare("UPDATE om_market_products SET quantity = quantity + ? WHERE product_id = ?")
           ->execute([(int)$ci['quantity'], (int)$ci['product_id']]);
    }

    // Delete all current order items
    $db->prepare("DELETE FROM om_market_order_items WHERE order_id = ?")->execute([$orderId]);

    // Insert new items and deduct stock
    $newSubtotal = 0;
    $insertedItems = [];
    $insertStmt = $db->prepare("
        INSERT INTO om_market_order_items (order_id, product_id, name, quantity, price, total)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stockDeductStmt = $db->prepare("
        UPDATE om_market_products SET quantity = quantity - ? WHERE product_id = ? AND quantity >= ?
    ");

    foreach ($finalItems as $pid => $item) {
        $prod = $productMap[$pid];
        $price = ($prod['special_price'] && (float)$prod['special_price'] > 0 && (float)$prod['special_price'] < (float)$prod['price'])
            ? (float)$prod['special_price'] : (float)$prod['price'];
        if ($price <= 0) {
            $db->rollBack();
            response(false, null, "Preco invalido para {$prod['name']}", 400);
        }
        $qty = $item['quantity'];
        $itemTotal = round($price * $qty, 2);
        $newSubtotal += $itemTotal;

        $insertStmt->execute([$orderId, $pid, $prod['name'], $qty, $price, $itemTotal]);

        $stockDeductStmt->execute([$qty, $pid, $qty]);
        if ($stockDeductStmt->rowCount() === 0) {
            $db->rollBack();
            response(false, null, "Estoque insuficiente para '{$prod['name']}'", 400);
        }

        $insertedItems[] = [
            'product_id' => $pid,
            'name' => $prod['name'],
            'quantity' => $qty,
            'price' => $price,
            'total' => $itemTotal,
        ];
    }

    $newSubtotal = round($newSubtotal, 2);

    // Recalculate totals
    $deliveryFee = (float)$order['delivery_fee'];
    $serviceFee = (float)($order['service_fee'] ?? OmPricing::TAXA_SERVICO);
    $tipAmount = (float)$order['tip_amount'];
    $couponDiscount = (float)$order['coupon_discount'];
    $loyaltyDiscount = (float)$order['loyalty_discount'];
    $cashbackDiscount = (float)$order['cashback_discount'];

    // Revalidate coupon
    $couponId = (int)($order['coupon_id'] ?? 0);
    if ($couponId > 0 && $couponDiscount > 0) {
        $couponStmt = $db->prepare("SELECT * FROM om_market_coupons WHERE id = ?");
        $couponStmt->execute([$couponId]);
        $coupon = $couponStmt->fetch(PDO::FETCH_ASSOC);

        if ($coupon) {
            $minOrder = (float)($coupon['min_order_value'] ?? 0);
            if ($minOrder > 0 && $newSubtotal < $minOrder) {
                $couponDiscount = 0;
                $couponId = 0;
            } else {
                if ($coupon['discount_type'] === 'percentage') {
                    $pct = min(100, max(0, (float)$coupon['discount_value']));
                    $couponDiscount = round($newSubtotal * $pct / 100, 2);
                    if (!empty($coupon['max_discount']) && $couponDiscount > (float)$coupon['max_discount']) {
                        $couponDiscount = (float)$coupon['max_discount'];
                    }
                } elseif ($coupon['discount_type'] === 'fixed') {
                    $couponDiscount = min((float)$coupon['discount_value'], $newSubtotal);
                }
            }
        } else {
            $couponDiscount = 0;
            $couponId = 0;
        }
    }

    // Ensure discounts don't exceed subtotal
    $totalDiscounts = $couponDiscount + $loyaltyDiscount + $cashbackDiscount;
    if ($totalDiscounts > $newSubtotal) {
        $ratio = $newSubtotal / max(0.01, $totalDiscounts);
        $couponDiscount = round($couponDiscount * $ratio, 2);
        $loyaltyDiscount = round($loyaltyDiscount * $ratio, 2);
        $cashbackDiscount = round($cashbackDiscount * $ratio, 2);
    }

    $newTotal = max(0, round(
        $newSubtotal + $deliveryFee + $serviceFee + $tipAmount
        - $couponDiscount - $loyaltyDiscount - $cashbackDiscount
    , 2));

    $oldTotal = (float)$order['total'];
    $priceDiff = round($newTotal - $oldTotal, 2);

    // Update order
    $updateFields = [
        'subtotal' => $newSubtotal,
        'total' => $newTotal,
        'coupon_discount' => $couponDiscount,
        'loyalty_discount' => $loyaltyDiscount,
        'cashback_discount' => $cashbackDiscount,
        'date_modified' => date('Y-m-d H:i:s'),
    ];

    if ($note !== null) {
        $updateFields['notes'] = $note;
    }

    if ($couponId === 0 && (int)($order['coupon_id'] ?? 0) > 0) {
        $updateFields['coupon_id'] = 0;
    }

    $setClauses = [];
    $setValues = [];
    foreach ($updateFields as $col => $val) {
        $setClauses[] = "{$col} = ?";
        $setValues[] = $val;
    }
    $setValues[] = $orderId;

    $db->prepare("UPDATE om_market_orders SET " . implode(', ', $setClauses) . " WHERE order_id = ?")
       ->execute($setValues);

    $db->commit();

    // WebSocket broadcast
    try {
        wsBroadcastToCustomer($customerId, 'order_update', [
            'order_id' => $orderId,
            'status' => $order['status'],
            'action' => 'order_modified',
            'new_subtotal' => $newSubtotal,
            'new_total' => $newTotal,
            'price_diff' => $priceDiff,
        ]);
        wsBroadcastToOrder($orderId, 'order_update', [
            'order_id' => $orderId,
            'status' => $order['status'],
            'action' => 'order_modified',
            'new_subtotal' => $newSubtotal,
            'new_total' => $newTotal,
            'price_diff' => $priceDiff,
        ]);
    } catch (\Throwable $e) {}

    // Notify partner
    $orderNumber = $order['order_number'];
    try {
        $itemCount = count($finalItems);
        $diffText = $priceDiff > 0 ? "+R$ " . number_format($priceDiff, 2, ',', '.')
            : ($priceDiff < 0 ? "-R$ " . number_format(abs($priceDiff), 2, ',', '.') : "sem alteracao");
        notifyPartner($db, $partnerId,
            "Pedido #{$orderNumber} modificado",
            "Cliente alterou o pedido ({$itemCount} " . ($itemCount === 1 ? 'item' : 'itens') . "). Diferenca: {$diffText}. Novo total: R$ " . number_format($newTotal, 2, ',', '.'),
            '/painel/mercado/pedidos.php'
        );
    } catch (\Throwable $e) {
        error_log("[order-modify] notifyPartner erro: " . $e->getMessage());
    }

    error_log("[order-modify] Pedido #{$orderId} modificado pelo cliente #{$customerId} | Itens: " . count($finalItems) . " | Subtotal: {$newSubtotal} | Total: {$newTotal} | Diff: {$priceDiff}");

    response(true, [
        'order_id' => $orderId,
        'items' => $insertedItems,
        'subtotal' => $newSubtotal,
        'delivery_fee' => $deliveryFee,
        'service_fee' => $serviceFee,
        'tip_amount' => $tipAmount,
        'coupon_discount' => $couponDiscount,
        'loyalty_discount' => $loyaltyDiscount,
        'cashback_discount' => $cashbackDiscount,
        'total' => $newTotal,
        'old_total' => $oldTotal,
        'price_diff' => $priceDiff,
        'note' => $note ?? ($order['notes'] ?? ''),
        'time_remaining' => $timeRemaining,
    ], "Pedido modificado com sucesso!");

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("[order-modify] Erro: " . $e->getMessage());
    response(false, null, "Erro ao modificar pedido", 500);
}
