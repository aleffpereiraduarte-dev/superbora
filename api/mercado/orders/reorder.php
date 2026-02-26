<?php
/**
 * POST /api/mercado/orders/reorder.php
 * Recria carrinho com itens de um pedido anterior
 * Body: { "order_id": 123 }
 */

require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    response(false, null, "Metodo nao permitido", 405);
}

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Autenticacao necessaria", 401);
    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== 'customer') response(false, null, "Token invalido", 401);
    $customerId = (int)$payload['uid'];

    $input = getInput();
    $orderId = (int)($input['order_id'] ?? 0);
    if (!$orderId) response(false, null, "order_id obrigatorio", 400);

    // Buscar pedido original
    $stmt = $db->prepare("
        SELECT o.order_id, o.partner_id, o.partner_name,
               p.name AS current_partner_name, p.status AS partner_status,
               p.trade_name
        FROM om_market_orders o
        LEFT JOIN om_market_partners p ON p.partner_id = o.partner_id
        WHERE o.order_id = ? AND o.customer_id = ?
    ");
    $stmt->execute([$orderId, $customerId]);
    $order = $stmt->fetch();

    if (!$order) response(false, null, "Pedido nao encontrado", 404);

    if (!$order['partner_status']) {
        response(false, null, "Estabelecimento nao disponivel no momento", 400);
    }

    // Buscar itens do pedido original
    $stmt = $db->prepare("
        SELECT oi.product_id, oi.product_name, oi.quantity, oi.price, oi.product_image
        FROM om_market_order_items oi
        WHERE oi.order_id = ?
    ");
    $stmt->execute([$orderId]);
    $orderItems = $stmt->fetchAll();

    if (empty($orderItems)) response(false, null, "Pedido sem itens", 400);

    // Verificar disponibilidade atual de cada produto
    $productIds = array_column($orderItems, 'product_id');
    $ph = implode(',', array_fill(0, count($productIds), '?'));
    $stmt = $db->prepare("
        SELECT product_id, name, price, special_price, quantity AS estoque, image, status
        FROM om_market_products
        WHERE product_id IN ($ph)
    ");
    $stmt->execute($productIds);
    $currentProducts = [];
    foreach ($stmt->fetchAll() as $p) {
        $currentProducts[$p['product_id']] = $p;
    }

    $items = [];
    $unavailable = [];

    foreach ($orderItems as $oi) {
        $pid = (int)$oi['product_id'];
        $current = $currentProducts[$pid] ?? null;

        if (!$current || !$current['status'] || $current['estoque'] <= 0) {
            $unavailable[] = [
                'product_id' => $pid,
                'name' => $oi['product_name'],
                'reason' => !$current ? 'Produto removido' : 'Sem estoque'
            ];
            continue;
        }

        $preco = ($current['special_price'] && (float)$current['special_price'] > 0 && (float)$current['special_price'] < (float)$current['price'])
            ? (float)$current['special_price'] : (float)$current['price'];

        $qty = min((int)$oi['quantity'], (int)$current['estoque']);

        $items[] = [
            'id' => $pid,
            'nome' => $current['name'],
            'preco' => (float)$current['price'],
            'preco_promo' => (float)($current['special_price'] ?? 0),
            'imagem' => $current['image'],
            'quantity' => $qty
        ];
    }

    $partnerDisplayName = $order['trade_name'] ?: $order['current_partner_name'] ?: $order['partner_name'];

    response(true, [
        'items' => $items,
        'unavailable' => $unavailable,
        'partner_id' => (int)$order['partner_id'],
        'partner_name' => $partnerDisplayName
    ], count($unavailable) > 0 ? count($unavailable) . ' item(ns) indisponiveis' : 'Itens carregados');

} catch (Exception $e) {
    error_log("[orders/reorder] Erro: " . $e->getMessage());
    response(false, null, "Erro ao recarregar pedido", 500);
}
