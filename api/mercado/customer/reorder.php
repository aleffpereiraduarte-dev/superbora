<?php
/**
 * POST /api/mercado/customer/reorder.php
 * Repedir um pedido anterior — adiciona todos os itens ao carrinho de uma vez
 *
 * Body: { order_id: int }
 * Returns: items added, unavailable items, cart total
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $customerId = requireCustomerAuth();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        response(false, null, "Metodo nao permitido", 405);
    }

    $input = getInput();
    $orderId = (int)($input['order_id'] ?? 0);

    if (!$orderId) {
        response(false, null, "order_id obrigatorio", 400);
    }

    // Verify order belongs to this customer
    $stmt = $db->prepare("
        SELECT order_id, mercado_id, customer_id, status
        FROM om_market_orders
        WHERE order_id = ? AND customer_id = ?
    ");
    $stmt->execute([$orderId, $customerId]);
    $order = $stmt->fetch();

    if (!$order) {
        response(false, null, "Pedido nao encontrado", 404);
    }

    // Get original order items
    $stmt = $db->prepare("
        SELECT oi.product_id, oi.quantity, oi.price, oi.options,
               p.name, p.price as current_price, p.price_promo, p.status as product_status,
               p.stock, p.image
        FROM om_market_order_items oi
        LEFT JOIN om_market_products p ON p.product_id = oi.product_id
        WHERE oi.order_id = ?
    ");
    $stmt->execute([$orderId]);
    $orderItems = $stmt->fetchAll();

    if (empty($orderItems)) {
        response(false, null, "Pedido sem itens", 400);
    }

    // Check store is still active
    $stmt = $db->prepare("SELECT mercado_id, nome, status FROM om_mercados WHERE mercado_id = ?");
    $stmt->execute([$order['mercado_id']]);
    $store = $stmt->fetch();

    if (!$store || $store['status'] != 1) {
        response(false, null, "Loja nao esta mais disponivel", 400);
    }

    // Get or create cart session
    $sessionId = $_COOKIE['session_id'] ?? bin2hex(random_bytes(16));

    // Clear existing cart for this store (to avoid mixing)
    $stmt = $db->prepare("
        DELETE FROM om_market_cart
        WHERE customer_id = ? AND mercado_id = ?
    ");
    $stmt->execute([$customerId, $order['mercado_id']]);

    $added = [];
    $unavailable = [];
    $cartTotal = 0;

    foreach ($orderItems as $item) {
        $productId = (int)$item['product_id'];

        // Check if product still exists and is available
        if (!$item['name'] || $item['product_status'] != 1) {
            $unavailable[] = [
                'product_id' => $productId,
                'name' => $item['name'] ?? 'Produto removido',
                'reason' => 'indisponivel',
            ];
            continue;
        }

        // Check stock
        if ($item['stock'] !== null && $item['stock'] < $item['quantity']) {
            if ($item['stock'] <= 0) {
                $unavailable[] = [
                    'product_id' => $productId,
                    'name' => $item['name'],
                    'reason' => 'sem_estoque',
                ];
                continue;
            }
            // Reduce quantity to available stock
            $item['quantity'] = $item['stock'];
        }

        // Use current price (not old price)
        $currentPrice = $item['price_promo'] > 0 ? $item['price_promo'] : $item['current_price'];
        $priceChanged = abs($currentPrice - $item['price']) > 0.01;

        // Add to cart
        $stmt = $db->prepare("
            INSERT INTO om_market_cart (customer_id, session_id, mercado_id, product_id, quantity, options, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $customerId,
            $sessionId,
            $order['mercado_id'],
            $productId,
            (int)$item['quantity'],
            $item['options'],
        ]);

        $lineTotal = $currentPrice * $item['quantity'];
        $cartTotal += $lineTotal;

        $addedItem = [
            'product_id' => $productId,
            'name' => $item['name'],
            'quantity' => (int)$item['quantity'],
            'price' => round($currentPrice, 2),
            'image' => $item['image'],
            'line_total' => round($lineTotal, 2),
        ];

        if ($priceChanged) {
            $addedItem['old_price'] = round($item['price'], 2);
            $addedItem['price_changed'] = true;
        }

        $added[] = $addedItem;
    }

    if (empty($added)) {
        response(false, null, "Nenhum item disponivel para reordenar", 400);
    }

    response(true, [
        'store' => [
            'mercado_id' => (int)$order['mercado_id'],
            'nome' => $store['nome'],
        ],
        'items_added' => $added,
        'items_unavailable' => $unavailable,
        'cart_total' => round($cartTotal, 2),
        'total_items' => count($added),
        'has_price_changes' => count(array_filter($added, fn($i) => !empty($i['price_changed']))) > 0,
        'has_unavailable' => count($unavailable) > 0,
    ], "Itens adicionados ao carrinho");

} catch (Exception $e) {
    error_log("[customer/reorder] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
