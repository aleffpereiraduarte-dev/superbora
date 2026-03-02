<?php
/**
 * POST /api/mercado/checkout/processar-rota.php
 * Creates a secondary order linked to an existing delivery route (DoubleDash).
 * Body: { primary_order_id, partner_id, items: [{ product_id, quantity }] }
 *
 * - delivery_fee = 0 (free for route add-ons)
 * - Inherits payment_method, address, customer_lat/lng from primary order
 * - Creates om_delivery_routes if primary doesn't have one yet
 */
require_once __DIR__ . "/../config/database.php";
setCorsHeaders();
require_once dirname(__DIR__, 2) . "/rate-limit/RateLimiter.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmPricing.php";
require_once __DIR__ . "/../helpers/notify.php";

if (!RateLimiter::check(10, 60)) {
    exit;
}

try {
    $input = getInput();
    $db = getDB();
    $customer_id = requireCustomerAuth();

    $primary_order_id = (int)($input['primary_order_id'] ?? 0);
    $partner_id = (int)($input['partner_id'] ?? 0);
    $items = $input['items'] ?? [];

    if (!$primary_order_id || !$partner_id || empty($items) || !is_array($items)) {
        response(false, null, "primary_order_id, partner_id e items obrigatorios", 400);
    }
    if (count($items) > 20) {
        response(false, null, "Maximo de 20 itens por pedido", 400);
    }

    $db->beginTransaction();

    // 1. Lock and validate primary order
    $stmtPrimary = $db->prepare("
        SELECT order_id, customer_id, partner_id, status, date_added,
               forma_pagamento, delivery_address, shipping_address,
               shipping_city, shipping_state, shipping_cep,
               shipping_lat, shipping_lng, delivery_type, is_pickup,
               route_id, route_stop_sequence, partner_name
        FROM om_market_orders
        WHERE order_id = ? FOR UPDATE
    ");
    $stmtPrimary->execute([$primary_order_id]);
    $primary = $stmtPrimary->fetch(PDO::FETCH_ASSOC);

    if (!$primary || (int)$primary['customer_id'] !== $customer_id) {
        $db->rollBack();
        response(false, null, "Pedido primario nao encontrado", 404);
    }

    // Check if primary is cancelled
    if (in_array($primary['status'], ['cancelado', 'cancelled', 'refunded'])) {
        $db->rollBack();
        response(false, null, "Pedido primario foi cancelado", 400);
    }

    $addableStatuses = ['pendente', 'confirmado', 'aceito', 'preparando', 'em_preparo', 'pronto'];
    if (!in_array($primary['status'], $addableStatuses)) {
        $db->rollBack();
        response(false, null, "Pedido ja saiu para entrega", 400);
    }

    // Block same partner as primary (use adicionar-item.php instead)
    if ($partner_id === (int)$primary['partner_id']) {
        $db->rollBack();
        response(false, null, "Para adicionar itens da mesma loja, use 'Adicionar itens'", 400);
    }

    // 30-minute window
    $createdAt = strtotime($primary['date_added']);
    if (time() > $createdAt + (30 * 60)) {
        $db->rollBack();
        response(false, null, "Tempo limite para adicionar pedidos excedido", 400);
    }

    // Block duplicate partner on same route
    $existingRouteId = (int)($primary['route_id'] ?? 0);
    if ($existingRouteId) {
        $stmtDup = $db->prepare("SELECT 1 FROM om_market_orders WHERE route_id = ? AND partner_id = ? AND status NOT IN ('cancelado','cancelled') LIMIT 1");
        $stmtDup->execute([$existingRouteId, $partner_id]);
        if ($stmtDup->fetch()) {
            $db->rollBack();
            response(false, null, "Voce ja tem um pedido desta loja nesta entrega", 400);
        }
    }

    // 2. Get partner info
    $stmtPartner = $db->prepare("SELECT partner_id, name, trade_name, latitude, longitude FROM om_market_partners WHERE partner_id = ? AND status = '1'");
    $stmtPartner->execute([$partner_id]);
    $parceiro = $stmtPartner->fetch(PDO::FETCH_ASSOC);
    if (!$parceiro) {
        $db->rollBack();
        response(false, null, "Loja nao encontrada", 404);
    }
    $partner_name = $parceiro['trade_name'] ?: $parceiro['name'];

    // 3. Get or create route
    $route_id = (int)($primary['route_id'] ?? 0);
    if (!$route_id) {
        // Create route for the primary order
        $stmtRoute = $db->prepare("
            INSERT INTO om_delivery_routes
                (customer_id, origin_partner_id, customer_lat, customer_lng, customer_address, total_delivery_fee, total_orders, status, created_at)
            VALUES (?, ?, ?, ?, ?, 0, 2, 'pending', NOW())
            RETURNING route_id
        ");
        $stmtRoute->execute([
            $customer_id,
            (int)$primary['partner_id'],
            $primary['shipping_lat'] ?: null,
            $primary['shipping_lng'] ?: null,
            $primary['delivery_address'] ?: $primary['shipping_address'],
        ]);
        $route_id = (int)$stmtRoute->fetchColumn();

        // Update primary order with route_id
        $db->prepare("UPDATE om_market_orders SET route_id = ?, route_stop_sequence = 1 WHERE order_id = ?")->execute([$route_id, $primary_order_id]);

        // Add primary as first stop
        $db->prepare("
            INSERT INTO om_delivery_route_stops
                (route_id, order_id, partner_id, stop_sequence, partner_lat, partner_lng, partner_name, stop_type, status)
            VALUES (?, ?, ?, 1, ?, ?, ?, 'pickup', 'pending')
        ")->execute([
            $route_id, $primary_order_id, (int)$primary['partner_id'],
            null, null, $primary['partner_name'] ?: '',
        ]);
    }

    // 4. Calculate next stop sequence
    $stmtSeq = $db->prepare("SELECT COALESCE(MAX(route_stop_sequence), 1) + 1 FROM om_market_orders WHERE route_id = ?");
    $stmtSeq->execute([$route_id]);
    $stop_sequence = (int)$stmtSeq->fetchColumn();

    // 5. Validate items and calculate subtotal
    $subtotal = 0;
    $validatedItems = [];
    foreach ($items as $item) {
        $pid = (int)($item['product_id'] ?? 0);
        $qty = max(1, (int)($item['quantity'] ?? 1));
        if (!$pid) continue;

        $stmtProd = $db->prepare("
            SELECT product_id, name, price, special_price, quantity AS stock, partner_id
            FROM om_market_products WHERE product_id = ? FOR UPDATE
        ");
        $stmtProd->execute([$pid]);
        $product = $stmtProd->fetch(PDO::FETCH_ASSOC);

        if (!$product || (int)$product['partner_id'] !== $partner_id) {
            $db->rollBack();
            response(false, null, "Produto {$pid} nao pertence a esta loja", 400);
        }
        if ($qty > (int)$product['stock']) {
            $db->rollBack();
            response(false, null, "'{$product['name']}' - estoque insuficiente (disponivel: {$product['stock']})", 400);
        }

        $price = ($product['special_price'] && (float)$product['special_price'] > 0 && (float)$product['special_price'] < (float)$product['price'])
            ? (float)$product['special_price'] : (float)$product['price'];
        $itemTotal = round($price * $qty, 2);
        $subtotal += $itemTotal;

        $validatedItems[] = [
            'product_id' => $pid,
            'name' => $product['name'],
            'quantity' => $qty,
            'price' => $price,
            'total' => $itemTotal,
        ];
    }

    if (empty($validatedItems)) {
        $db->rollBack();
        response(false, null, "Nenhum item valido", 400);
    }

    $subtotal = round($subtotal, 2);
    $service_fee = OmPricing::TAXA_SERVICO;
    $total = round($subtotal + $service_fee, 2);

    // 6. Create order number placeholder
    $order_number_temp = 'SB_TEMP_' . time();

    // 7. Get customer info
    $stmtCust = $db->prepare("SELECT name, email, phone FROM om_market_customers WHERE customer_id = ?");
    $stmtCust->execute([$customer_id]);
    $customer = $stmtCust->fetch(PDO::FETCH_ASSOC);

    // 8. INSERT order
    $stmtOrder = $db->prepare("
        INSERT INTO om_market_orders (
            order_number, partner_id, partner_name, customer_id,
            customer_name, customer_phone, customer_email,
            status, subtotal, delivery_fee, total, tip_amount,
            delivery_address, shipping_address, shipping_city, shipping_state, shipping_cep,
            forma_pagamento, delivery_type, is_pickup,
            service_fee, route_id, route_stop_sequence,
            shipping_lat, shipping_lng, date_added
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 'pendente', ?, 0, ?, 0, ?, ?, ?, ?, ?, ?, ?, 0::smallint, ?, ?, ?, ?, ?, NOW())
        RETURNING order_id
    ");
    $stmtOrder->execute([
        $order_number_temp, $partner_id, $partner_name, $customer_id,
        $customer['name'] ?? '', $customer['phone'] ?? '', $customer['email'] ?? '',
        $subtotal, $total,
        $primary['delivery_address'], $primary['shipping_address'],
        $primary['shipping_city'], $primary['shipping_state'], $primary['shipping_cep'],
        $primary['forma_pagamento'], $primary['delivery_type'], // inherit
        $service_fee, $route_id, $stop_sequence,
        $primary['shipping_lat'] ?: null, $primary['shipping_lng'] ?: null,
    ]);
    $order_id = (int)$stmtOrder->fetchColumn();

    // 9. Pretty order number
    $order_number = 'SB' . str_pad($order_id, 5, '0', STR_PAD_LEFT);
    $db->prepare("UPDATE om_market_orders SET order_number = ? WHERE order_id = ?")->execute([$order_number, $order_id]);

    // 10. INSERT items + decrement stock
    $stmtItem = $db->prepare("INSERT INTO om_market_order_items (order_id, product_id, name, quantity, price, total) VALUES (?, ?, ?, ?, ?, ?)");
    $stmtStock = $db->prepare("UPDATE om_market_products SET quantity = quantity - ? WHERE product_id = ? AND quantity >= ?");

    foreach ($validatedItems as $vi) {
        $stmtItem->execute([$order_id, $vi['product_id'], $vi['name'], $vi['quantity'], $vi['price'], $vi['total']]);
        $stmtStock->execute([$vi['quantity'], $vi['product_id'], $vi['quantity']]);
        if ($stmtStock->rowCount() === 0) {
            $db->rollBack();
            response(false, null, "'{$vi['name']}' - estoque insuficiente", 400);
        }
    }

    // 11. Add route stop
    $db->prepare("
        INSERT INTO om_delivery_route_stops
            (route_id, order_id, partner_id, stop_sequence, partner_lat, partner_lng, partner_name, stop_type, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'pickup', 'pending')
    ")->execute([
        $route_id, $order_id, $partner_id, $stop_sequence,
        $parceiro['latitude'] ?: null, $parceiro['longitude'] ?: null, $partner_name,
    ]);

    // 12. Update route total_orders
    $db->prepare("UPDATE om_delivery_routes SET total_orders = total_orders + 1 WHERE route_id = ?")->execute([$route_id]);

    $db->commit();

    // 13. Notify partner (outside transaction)
    try {
        notifyPartner($db, $partner_id,
            "Novo pedido na rota #{$order_number}",
            count($validatedItems) . " item(ns) - R$ " . number_format($total, 2, ',', '.') . " (Frete gratis - rota)",
            '/painel/mercado/pedidos.php'
        );
    } catch (Exception $e) {
        error_log("[processar-rota] notifyPartner erro: " . $e->getMessage());
    }

    // 14. Notify customer
    try {
        notifyCustomer($db, $customer_id,
            "Pedido adicionado a rota!",
            "Seu pedido de {$partner_name} foi adicionado a entrega. Frete gratis!",
            "/tracking/{$order_id}"
        );
    } catch (Exception $e) {
        error_log("[processar-rota] notifyCustomer erro: " . $e->getMessage());
    }

    response(true, [
        'order_id' => $order_id,
        'order_number' => $order_number,
        'route_id' => $route_id,
        'stop_sequence' => $stop_sequence,
        'partner_name' => $partner_name,
        'subtotal' => $subtotal,
        'service_fee' => $service_fee,
        'delivery_fee' => 0,
        'total' => $total,
        'items_count' => count($validatedItems),
    ]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("[processar-rota] Erro: " . $e->getMessage());
    response(false, null, "Erro ao criar pedido na rota", 500);
}
