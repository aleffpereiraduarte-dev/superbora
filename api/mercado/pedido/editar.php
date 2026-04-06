<?php
/**
 * POST /api/mercado/pedido/editar.php
 * Customer edits an existing order (change item quantities, remove items, update instructions).
 * Only allowed when status is 'pendente' or 'confirmado' (before store starts preparing).
 *
 * Body: {
 *   "order_id": 123,
 *   "items": [
 *     { "product_id": 10, "quantity": 2 },
 *     { "product_id": 11, "quantity": 1 }
 *   ],
 *   "instructions": "Sem cebola, por favor"
 * }
 *
 * Items not included in the array are removed from the order.
 * Recalculates subtotal, service fee, delivery fee, discounts, and total.
 */
require_once __DIR__ . "/../config/database.php";
setCorsHeaders();
require_once dirname(__DIR__, 2) . "/rate-limit/RateLimiter.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmPricing.php";
require_once __DIR__ . "/../helpers/notify.php";
require_once __DIR__ . '/../helpers/ws-customer-broadcast.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, "Metodo nao permitido", 405);
}

// CSRF protection
$ct = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
if (stripos($ct, 'application/json') === false) {
    response(false, null, "Content-Type deve ser application/json", 400);
}

if (!RateLimiter::check(10, 60)) {
    exit;
}

try {
    $input = getInput();
    $db = getDB();
    $customer_id = requireCustomerAuth();

    $order_id = (int)($input['order_id'] ?? 0);
    $items = $input['items'] ?? null;
    $instructions = isset($input['instructions']) ? trim(substr((string)$input['instructions'], 0, 1000)) : null;

    if (!$order_id) {
        response(false, null, "order_id obrigatorio", 400);
    }

    if (!is_array($items) || empty($items)) {
        response(false, null, "items obrigatorio (array de {product_id, quantity})", 400);
    }

    // Validate items structure
    $parsedItems = [];
    foreach ($items as $item) {
        $pid = (int)($item['product_id'] ?? 0);
        $qty = (int)($item['quantity'] ?? 0);
        if ($pid <= 0) {
            response(false, null, "product_id invalido em items", 400);
        }
        if ($qty <= 0) {
            response(false, null, "quantity deve ser maior que zero para product_id={$pid}", 400);
        }
        if ($qty > 99) {
            response(false, null, "quantity maximo de 99 por item", 400);
        }
        $parsedItems[$pid] = $qty; // Deduplicate by product_id
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
    $orderStmt->execute([$order_id]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order || (int)$order['customer_id'] !== $customer_id) {
        $db->rollBack();
        response(false, null, "Pedido nao encontrado", 404);
    }

    // Allow editing in early statuses + aceito/preparando within time limit
    $editableStatuses = ['pendente', 'confirmado', 'aceito', 'preparando'];
    if (!in_array($order['status'], $editableStatuses)) {
        $db->rollBack();
        response(false, null, "Pedido nao pode ser editado (status atual: {$order['status']}). Edicao so e permitida antes da entrega.", 409);
    }

    // For aceito/preparando: enforce 10-minute window from order creation
    if (in_array($order['status'], ['aceito', 'preparando'])) {
        $createdAt = strtotime($order['date_added']);
        $timeLimitSeconds = 10 * 60; // 10 minutes
        $elapsed = time() - $createdAt;
        if ($elapsed > $timeLimitSeconds) {
            $db->rollBack();
            response(false, null, "Tempo para edicao expirou. Limite de 10 minutos apos o pedido.", 409);
        }
    }

    $partner_id = (int)$order['partner_id'];

    // Fetch current order items for stock restoration
    $currentItemsStmt = $db->prepare("
        SELECT item_id, product_id, quantity, price
        FROM om_market_order_items
        WHERE order_id = ?
    ");
    $currentItemsStmt->execute([$order_id]);
    $currentItems = $currentItemsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Build map of current items: product_id => { qty, price }
    $currentMap = [];
    foreach ($currentItems as $ci) {
        $currentMap[(int)$ci['product_id']] = [
            'item_id' => (int)$ci['item_id'],
            'quantity' => (int)$ci['quantity'],
            'price' => (float)$ci['price'],
        ];
    }

    // Validate all new products belong to the same partner and have stock
    $productIds = array_keys($parsedItems);
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $prodStmt = $db->prepare("
        SELECT product_id, name, price, special_price, quantity AS stock, partner_id, status
        FROM om_market_products
        WHERE product_id IN ($placeholders) FOR UPDATE
    ");
    $prodStmt->execute($productIds);
    $products = $prodStmt->fetchAll(PDO::FETCH_ASSOC);

    // Index by product_id
    $productMap = [];
    foreach ($products as $p) {
        $productMap[(int)$p['product_id']] = $p;
    }

    // Validate each requested item
    $stockErrors = [];
    foreach ($parsedItems as $pid => $qty) {
        if (!isset($productMap[$pid])) {
            $db->rollBack();
            response(false, null, "Produto #{$pid} nao encontrado", 404);
        }
        $prod = $productMap[$pid];
        if ((int)$prod['partner_id'] !== $partner_id) {
            $db->rollBack();
            response(false, null, "Produto #{$pid} nao pertence a esta loja", 400);
        }

        // Calculate available stock: current stock + quantity already reserved in the order
        $alreadyReserved = isset($currentMap[$pid]) ? $currentMap[$pid]['quantity'] : 0;
        $availableStock = (int)$prod['stock'] + $alreadyReserved;
        if ($qty > $availableStock) {
            $stockErrors[] = "'{$prod['name']}' - estoque insuficiente (disponivel: {$availableStock})";
        }
    }

    if (!empty($stockErrors)) {
        $db->rollBack();
        response(false, ['erros_estoque' => $stockErrors], "Estoque insuficiente: " . $stockErrors[0], 400);
    }

    // ─── Restore stock for all current items ─────────────────────────
    foreach ($currentItems as $ci) {
        $db->prepare("UPDATE om_market_products SET quantity = quantity + ? WHERE product_id = ?")
           ->execute([(int)$ci['quantity'], (int)$ci['product_id']]);
    }

    // ─── Delete all current order items ──────────────────────────────
    $db->prepare("DELETE FROM om_market_order_items WHERE order_id = ?")
       ->execute([$order_id]);

    // ─── Insert new items and deduct stock ───────────────────────────
    $newSubtotal = 0;
    $insertedItems = [];
    $insertStmt = $db->prepare("
        INSERT INTO om_market_order_items (order_id, product_id, name, quantity, price, total)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stockDeductStmt = $db->prepare("
        UPDATE om_market_products SET quantity = quantity - ? WHERE product_id = ? AND quantity >= ?
    ");

    foreach ($parsedItems as $pid => $qty) {
        $prod = $productMap[$pid];
        $price = ($prod['special_price'] && (float)$prod['special_price'] > 0 && (float)$prod['special_price'] < (float)$prod['price'])
            ? (float)$prod['special_price'] : (float)$prod['price'];
        if ($price <= 0) {
            $db->rollBack();
            response(false, null, "Preco invalido para {$prod['name']}", 400);
        }
        $itemTotal = round($price * $qty, 2);
        $newSubtotal += $itemTotal;

        $insertStmt->execute([$order_id, $pid, $prod['name'], $qty, $price, $itemTotal]);

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

    // ─── Recalculate totals ──────────────────────────────────────────
    $deliveryFee = (float)$order['delivery_fee'];
    $serviceFee = (float)($order['service_fee'] ?? OmPricing::TAXA_SERVICO);
    $tipAmount = (float)$order['tip_amount'];
    $couponDiscount = (float)$order['coupon_discount'];
    $loyaltyDiscount = (float)$order['loyalty_discount'];
    $cashbackDiscount = (float)$order['cashback_discount'];

    // Revalidate coupon: if coupon has min_order_value and new subtotal is below, remove discount
    $couponId = (int)($order['coupon_id'] ?? 0);
    if ($couponId > 0 && $couponDiscount > 0) {
        $couponStmt = $db->prepare("SELECT * FROM om_market_coupons WHERE id = ?");
        $couponStmt->execute([$couponId]);
        $coupon = $couponStmt->fetch(PDO::FETCH_ASSOC);

        if ($coupon) {
            $minOrder = (float)($coupon['min_order_value'] ?? 0);
            if ($minOrder > 0 && $newSubtotal < $minOrder) {
                // Coupon no longer valid — remove discount
                $couponDiscount = 0;
                $couponId = 0;
            } else {
                // Recalculate percentage-based discounts
                if ($coupon['discount_type'] === 'percentage') {
                    $pct = min(100, max(0, (float)$coupon['discount_value']));
                    $couponDiscount = round($newSubtotal * $pct / 100, 2);
                    if (!empty($coupon['max_discount']) && $couponDiscount > (float)$coupon['max_discount']) {
                        $couponDiscount = (float)$coupon['max_discount'];
                    }
                } elseif ($coupon['discount_type'] === 'fixed') {
                    $couponDiscount = min((float)$coupon['discount_value'], $newSubtotal);
                }
                // free_delivery stays as is
            }
        } else {
            $couponDiscount = 0;
            $couponId = 0;
        }
    }

    // Ensure discounts don't exceed subtotal
    $totalDiscounts = $couponDiscount + $loyaltyDiscount + $cashbackDiscount;
    if ($totalDiscounts > $newSubtotal) {
        // Proportionally reduce discounts
        $ratio = $newSubtotal / max(0.01, $totalDiscounts);
        $couponDiscount = round($couponDiscount * $ratio, 2);
        $loyaltyDiscount = round($loyaltyDiscount * $ratio, 2);
        $cashbackDiscount = round($cashbackDiscount * $ratio, 2);
    }

    $newTotal = max(0, round(
        $newSubtotal
        + $deliveryFee
        + $serviceFee
        + $tipAmount
        - $couponDiscount
        - $loyaltyDiscount
        - $cashbackDiscount
    , 2));

    // ─── Update order ────────────────────────────────────────────────
    $updateFields = [
        'subtotal' => $newSubtotal,
        'total' => $newTotal,
        'coupon_discount' => $couponDiscount,
        'loyalty_discount' => $loyaltyDiscount,
        'cashback_discount' => $cashbackDiscount,
        'date_modified' => date('Y-m-d H:i:s'),
    ];

    // Update instructions if provided
    if ($instructions !== null) {
        $updateFields['notes'] = $instructions;
    }

    // Remove coupon if no longer valid
    if ($couponId === 0 && (int)($order['coupon_id'] ?? 0) > 0) {
        $updateFields['coupon_id'] = 0;
    }

    $setClauses = [];
    $setValues = [];
    foreach ($updateFields as $col => $val) {
        $setClauses[] = "{$col} = ?";
        $setValues[] = $val;
    }
    $setValues[] = $order_id;

    $db->prepare("UPDATE om_market_orders SET " . implode(', ', $setClauses) . " WHERE order_id = ?")
       ->execute($setValues);

    $db->commit();

    // ─── WebSocket broadcast to customer + order channel ─────────────
    try {
        wsBroadcastToCustomer($customer_id, 'order_update', [
            'order_id' => $order_id,
            'status' => $order['status'],
            'action' => 'order_edited',
            'new_subtotal' => $newSubtotal,
            'new_total' => $newTotal,
        ]);
        wsBroadcastToOrder($order_id, 'order_update', [
            'order_id' => $order_id,
            'status' => $order['status'],
            'action' => 'order_edited',
            'new_subtotal' => $newSubtotal,
            'new_total' => $newTotal,
        ]);
    } catch (\Throwable $e) {}

    // ─── Notify partner ──────────────────────────────────────────────
    $orderNumber = $order['order_number'];
    try {
        $itemCount = count($parsedItems);
        notifyPartner($db, $partner_id,
            "Pedido #{$orderNumber} editado",
            "Cliente alterou o pedido ({$itemCount} " . ($itemCount === 1 ? 'item' : 'itens') . "). Novo total: R$ " . number_format($newTotal, 2, ',', '.'),
            '/painel/mercado/pedidos.php'
        );
    } catch (\Throwable $e) {
        error_log("[editar] notifyPartner erro: " . $e->getMessage());
    }

    error_log("[editar] Pedido #{$order_id} editado pelo cliente #{$customer_id} | Itens: " . count($parsedItems) . " | Subtotal: {$newSubtotal} -> Total: {$newTotal}");

    response(true, [
        'order_id' => $order_id,
        'items' => $insertedItems,
        'subtotal' => $newSubtotal,
        'delivery_fee' => $deliveryFee,
        'service_fee' => $serviceFee,
        'tip_amount' => $tipAmount,
        'coupon_discount' => $couponDiscount,
        'loyalty_discount' => $loyaltyDiscount,
        'cashback_discount' => $cashbackDiscount,
        'total' => $newTotal,
        'instructions' => $instructions ?? ($order['notes'] ?? ''),
    ], "Pedido atualizado com sucesso!");

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("[editar] Erro: " . $e->getMessage());
    response(false, null, "Erro ao editar pedido", 500);
}
