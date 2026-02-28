<?php
/**
 * Order Edit API - allows customers to modify orders while still in 'pending' or 'aceito' status
 *
 * GET  ?order_id=X  - Get current order items for editing
 * PUT  body: { "order_id": X, "items": [{ "product_id": Y, "quantity": Z }] } - Update order items
 */

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../helpers/rate-limit.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

// Editable statuses
const EDITABLE_STATUSES = ['pendente', 'confirmado', 'aceito'];

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    // Authenticate customer
    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Autenticacao necessaria", 401);
    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== 'customer') response(false, null, "Token invalido", 401);
    $customerId = (int)$payload['uid'];

    // Rate limiting: 20 order edits per 15 minutes per customer
    if (!checkRateLimit("order_edit_c{$customerId}", 20, 15)) {
        response(false, null, "Muitas edicoes. Tente novamente em 15 minutos.", 429);
    }

    $method = $_SERVER["REQUEST_METHOD"];

    // ═══════════════════════════════════════════════════════════════
    // GET - Return current order items for editing
    // ═══════════════════════════════════════════════════════════════
    if ($method === 'GET') {
        $orderId = (int)($_GET['order_id'] ?? 0);
        if (!$orderId) response(false, null, "order_id obrigatorio", 400);

        // Fetch order
        $stmt = $db->prepare("
            SELECT o.order_id, o.order_number, o.partner_id, o.status, o.subtotal,
                   o.delivery_fee, o.service_fee, o.total, o.tip_amount, o.coupon_id,
                   o.coupon_discount, o.is_pickup, o.delivery_type, o.modification_count,
                   o.partner_name,
                   p.trade_name, p.logo as partner_logo, p.delivery_fee as partner_delivery_fee,
                   p.free_delivery_above, p.min_order_value, p.name as p_name
            FROM om_market_orders o
            LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
            WHERE o.order_id = ? AND o.customer_id = ?
        ");
        $stmt->execute([$orderId, $customerId]);
        $order = $stmt->fetch();

        if (!$order) response(false, null, "Pedido nao encontrado", 404);

        if (!in_array($order['status'], EDITABLE_STATUSES)) {
            response(false, null, "Pedido nao pode ser editado (status: {$order['status']})", 400);
        }

        // Only BoraUm delivery orders can be edited
        $isPickupGet = (bool)($order['is_pickup'] ?? false);
        $deliveryTypeGet = $order['delivery_type'] ?? '';

        if ($isPickupGet || $deliveryTypeGet === 'retirada') {
            response(false, null, "Não disponível para pedidos de retirada", 400);
        }
        if ($deliveryTypeGet === 'proprio') {
            response(false, null, "Disponível apenas para entregas BoraUm", 400);
        }
        if ($deliveryTypeGet !== 'boraum') {
            response(false, null, "Disponível apenas para entregas BoraUm", 400);
        }

        // Fetch order items with current product data
        $stmtItems = $db->prepare("
            SELECT oi.product_id, oi.product_name, oi.quantity, oi.price, oi.total,
                   oi.product_image, oi.notes,
                   prod.name as current_name, prod.price as current_price,
                   prod.special_price, prod.quantity as estoque, prod.image as current_image,
                   prod.status as product_status
            FROM om_market_order_items oi
            LEFT JOIN om_market_products prod ON oi.product_id = prod.product_id
            WHERE oi.order_id = ?
        ");
        $stmtItems->execute([$orderId]);
        $rawItems = $stmtItems->fetchAll();

        $items = [];
        foreach ($rawItems as $item) {
            $currentPrice = null;
            if ($item['special_price'] && (float)$item['special_price'] > 0 && (float)$item['special_price'] < (float)$item['current_price']) {
                $currentPrice = (float)$item['special_price'];
            } elseif ($item['current_price']) {
                $currentPrice = (float)$item['current_price'];
            }

            $items[] = [
                "product_id" => (int)$item['product_id'],
                "name" => $item['current_name'] ?? $item['product_name'],
                "quantity" => (int)$item['quantity'],
                "price" => (float)$item['price'],
                "current_price" => $currentPrice,
                "total" => (float)$item['total'],
                "image" => $item['current_image'] ?? $item['product_image'],
                "notes" => $item['notes'] ?? '',
                "estoque" => (int)($item['estoque'] ?? 0),
                "available" => (bool)($item['product_status'] ?? false) && (int)($item['estoque'] ?? 0) > 0,
            ];
        }

        response(true, [
            "order" => [
                "id" => (int)$order['order_id'],
                "order_number" => $order['order_number'],
                "partner_id" => (int)$order['partner_id'],
                "partner_name" => $order['trade_name'] ?: $order['partner_name'] ?: $order['p_name'] ?: '',
                "partner_logo" => $order['partner_logo'],
                "status" => $order['status'],
                "subtotal" => (float)$order['subtotal'],
                "delivery_fee" => (float)$order['delivery_fee'],
                "service_fee" => (float)($order['service_fee'] ?? 0),
                "tip" => (float)($order['tip_amount'] ?? 0),
                "coupon_discount" => (float)($order['coupon_discount'] ?? 0),
                "coupon_id" => (int)($order['coupon_id'] ?? 0),
                "total" => (float)$order['total'],
                "is_pickup" => (bool)($order['is_pickup'] ?? false),
                "min_order_value" => (float)($order['min_order_value'] ?? 0),
                "modification_count" => (int)($order['modification_count'] ?? 0),
            ],
            "items" => $items,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    // PUT - Update order items
    // ═══════════════════════════════════════════════════════════════
    elseif ($method === 'PUT') {
        $input = getInput();
        $orderId = (int)($input['order_id'] ?? 0);
        $newItems = $input['items'] ?? [];

        if (!$orderId) response(false, null, "order_id obrigatorio", 400);
        if (empty($newItems)) response(false, null, "Lista de itens vazia", 400);

        $db->beginTransaction();

        try {
            // Fetch order with row lock
            $stmt = $db->prepare("
                SELECT o.order_id, o.order_number, o.partner_id, o.status, o.subtotal,
                       o.delivery_fee, o.service_fee, o.total, o.tip_amount, o.coupon_id,
                       o.coupon_discount, o.is_pickup, o.delivery_type, o.modification_count,
                       o.partner_name, o.date_added,
                       p.delivery_fee as partner_delivery_fee, p.free_delivery_above,
                       p.min_order_value, p.partner_id as p_id
                FROM om_market_orders o
                LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
                WHERE o.order_id = ? AND o.customer_id = ? FOR UPDATE OF o
            ");
            $stmt->execute([$orderId, $customerId]);
            $order = $stmt->fetch();

            if (!$order) {
                $db->rollBack();
                response(false, null, "Pedido nao encontrado", 404);
            }

            if (!in_array($order['status'], EDITABLE_STATUSES)) {
                $db->rollBack();
                response(false, null, "Pedido nao pode ser editado (status: {$order['status']})", 400);
            }

            // Only BoraUm delivery orders can be edited
            $isPickupOrder = (bool)($order['is_pickup'] ?? false);
            $deliveryTypePut = $order['delivery_type'] ?? '';

            if ($isPickupOrder || $deliveryTypePut === 'retirada') {
                $db->rollBack();
                response(false, null, "Não disponível para pedidos de retirada", 400);
            }
            if ($deliveryTypePut === 'proprio') {
                $db->rollBack();
                response(false, null, "Disponível apenas para entregas BoraUm", 400);
            }
            if ($deliveryTypePut !== 'boraum') {
                $db->rollBack();
                response(false, null, "Disponível apenas para entregas BoraUm", 400);
            }

            // 30-minute time window from order creation
            $createdAt = new DateTime($order['date_added']);
            $limitTime = (clone $createdAt)->modify('+30 minutes');
            if (new DateTime() > $limitTime) {
                $db->rollBack();
                response(false, null, "Tempo para edicao expirou", 400);
            }

            $partnerId = (int)$order['partner_id'];

            // Validate all products exist and belong to the same partner
            $productIds = array_map(fn($i) => (int)$i['product_id'], $newItems);
            $ph = implode(',', array_fill(0, count($productIds), '?'));
            $stmtProd = $db->prepare("
                SELECT product_id, name, price, special_price, quantity as estoque, image, partner_id
                FROM om_market_products
                WHERE product_id IN ($ph)
                FOR UPDATE
            ");
            $stmtProd->execute($productIds);
            $productsMap = [];
            foreach ($stmtProd->fetchAll() as $p) {
                $productsMap[$p['product_id']] = $p;
            }

            // Restore stock from old items first
            $stmtOldItems = $db->prepare("SELECT product_id, quantity FROM om_market_order_items WHERE order_id = ?");
            $stmtOldItems->execute([$orderId]);
            $oldItems = $stmtOldItems->fetchAll();

            foreach ($oldItems as $oi) {
                $db->prepare("UPDATE om_market_products SET quantity = quantity + ? WHERE product_id = ?")
                    ->execute([$oi['quantity'], $oi['product_id']]);
            }

            // Validate new items and calculate totals
            $subtotal = 0;
            $orderItems = [];
            $totalItemsCount = 0;

            foreach ($newItems as $item) {
                $pid = (int)$item['product_id'];
                $qty = max(1, (int)($item['quantity'] ?? 1));
                $itemNotes = strip_tags(trim(substr($item['notes'] ?? '', 0, 500)));
                $prod = $productsMap[$pid] ?? null;

                if (!$prod) {
                    $db->rollBack();
                    response(false, null, "Produto ID $pid nao encontrado", 400);
                }

                // Verify same partner
                if ((int)$prod['partner_id'] !== $partnerId) {
                    $db->rollBack();
                    response(false, null, "Produto '{$prod['name']}' nao pertence a esta loja", 400);
                }

                // Check stock (stock was already restored above)
                if ($qty > (int)$prod['estoque']) {
                    $db->rollBack();
                    response(false, null, "'{$prod['name']}' sem estoque suficiente (disponivel: {$prod['estoque']})", 400);
                }

                $preco = ($prod['special_price'] && (float)$prod['special_price'] > 0 && (float)$prod['special_price'] < (float)$prod['price'])
                    ? (float)$prod['special_price'] : (float)$prod['price'];
                $itemTotal = $preco * $qty;
                $subtotal += $itemTotal;
                $totalItemsCount += $qty;

                $orderItems[] = [
                    'product_id' => $pid,
                    'name' => $prod['name'],
                    'quantity' => $qty,
                    'price' => $preco,
                    'total' => $itemTotal,
                    'image' => $prod['image'],
                    'notes' => $itemNotes,
                ];
            }

            // Recalculate delivery fee
            $isPickup = (bool)($order['is_pickup'] ?? false);
            $deliveryFee = $isPickup ? 0 : (float)($order['partner_delivery_fee'] ?? $order['delivery_fee'] ?? 5.99);
            $freeAbove = (float)($order['free_delivery_above'] ?? 99);
            if (!$isPickup && $subtotal >= $freeAbove) $deliveryFee = 0;

            // Recalculate coupon discount if applicable
            $couponId = (int)($order['coupon_id'] ?? 0);
            $couponDiscount = 0;
            if ($couponId) {
                $stmtC = $db->prepare("SELECT id, discount_type, discount_value, max_discount, min_order_value FROM om_market_coupons WHERE id = ? AND status = 'active'");
                $stmtC->execute([$couponId]);
                $coupon = $stmtC->fetch();
                if ($coupon) {
                    switch ($coupon['discount_type']) {
                        case 'percentage':
                            $couponDiscount = round($subtotal * (float)$coupon['discount_value'] / 100, 2);
                            if ($coupon['max_discount'] && $couponDiscount > (float)$coupon['max_discount']) {
                                $couponDiscount = (float)$coupon['max_discount'];
                            }
                            break;
                        case 'fixed':
                            $couponDiscount = min((float)$coupon['discount_value'], $subtotal);
                            break;
                        case 'free_delivery':
                            $deliveryFee = 0;
                            break;
                    }
                }
            }

            // Check minimum order
            $minOrder = (float)($order['min_order_value'] ?? 0);
            if ($subtotal < $minOrder) {
                $db->rollBack();
                response(false, null, "Pedido minimo: R$ " . number_format($minOrder, 2, ',', '.'), 400);
            }

            $serviceFee = (float)($order['service_fee'] ?? 2.49);
            $tip = (float)($order['tip_amount'] ?? 0);
            $total = $subtotal - $couponDiscount + $deliveryFee + $serviceFee + $tip;
            if ($total < 0) $total = 0;

            // Delete old order items
            $db->prepare("DELETE FROM om_market_order_items WHERE order_id = ?")->execute([$orderId]);

            // Insert new order items
            $stmtInsert = $db->prepare("
                INSERT INTO om_market_order_items (order_id, product_id, product_name, quantity, price, total, product_image, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($orderItems as $oi) {
                $stmtInsert->execute([
                    $orderId, $oi['product_id'], $oi['name'], $oi['quantity'],
                    $oi['price'], $oi['total'], $oi['image'], $oi['notes'] ?: null
                ]);
                // Deduct stock for new items (guarded decrement - race-safe)
                $stmtDeduct = $db->prepare("UPDATE om_market_products SET quantity = quantity - ? WHERE product_id = ? AND quantity >= ?");
                $stmtDeduct->execute([$oi['quantity'], $oi['product_id'], $oi['quantity']]);
                if ($stmtDeduct->rowCount() === 0) {
                    throw new Exception("Estoque insuficiente para '{$oi['name']}' durante edicao do pedido");
                }
            }

            // Update order totals
            $db->prepare("
                UPDATE om_market_orders
                SET subtotal = ?,
                    delivery_fee = ?,
                    coupon_discount = ?,
                    total = ?,
                    items_count = ?,
                    modified_at = NOW(),
                    modification_count = COALESCE(modification_count, 0) + 1,
                    date_modified = NOW()
                WHERE order_id = ?
            ")->execute([$subtotal, $deliveryFee, $couponDiscount, $total, $totalItemsCount, $orderId]);

            // Log event
            $modCount = (int)($order['modification_count'] ?? 0) + 1;
            $orderNumber = $order['order_number'];
            try {
                $db->prepare("
                    INSERT INTO om_market_order_events (order_id, event_type, message, created_by, created_at)
                    VALUES (?, 'order_modified', ?, ?, NOW())
                ")->execute([
                    $orderId,
                    "Pedido modificado pelo cliente (alteracao #{$modCount}). Novo subtotal: R$ " . number_format($subtotal, 2, ',', '.') . ", Total: R$ " . number_format($total, 2, ',', '.'),
                    "customer:$customerId"
                ]);
            } catch (Exception $e) {
                // om_market_order_events table might not exist, non-critical
                error_log("[orders/edit] Could not log event: " . $e->getMessage());
            }

            $db->commit();

            error_log("[orders/edit] Pedido #{$orderId} modificado pelo cliente #{$customerId}. Alteracao #{$modCount}");

            // Build response items
            $responseItems = [];
            foreach ($orderItems as $oi) {
                $responseItems[] = [
                    "product_id" => $oi['product_id'],
                    "name" => $oi['name'],
                    "quantity" => $oi['quantity'],
                    "price" => round($oi['price'], 2),
                    "total" => round($oi['total'], 2),
                    "image" => $oi['image'],
                ];
            }

            response(true, [
                "order_id" => $orderId,
                "order_number" => $orderNumber,
                "subtotal" => round($subtotal, 2),
                "delivery_fee" => round($deliveryFee, 2),
                "service_fee" => round($serviceFee, 2),
                "tip" => round($tip, 2),
                "coupon_discount" => round($couponDiscount, 2),
                "total" => round($total, 2),
                "items_count" => $totalItemsCount,
                "items" => $responseItems,
                "modification_count" => $modCount,
            ], "Pedido atualizado com sucesso!");

        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    } else {
        response(false, null, "Metodo nao permitido", 405);
    }

} catch (Exception $e) {
    error_log("[API Orders Edit] Erro: " . $e->getMessage());
    response(false, null, "Erro ao editar pedido", 500);
}
