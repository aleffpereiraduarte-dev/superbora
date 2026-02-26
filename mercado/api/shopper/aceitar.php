<?php
/**
 * POST /mercado/api/shopper/aceitar.php
 */
require_once __DIR__ . "/../config.php";

try {
    $input = getInput();
    $db = getDB();

    $shopper_id = intval($input["shopper_id"] ?? 0);
    $order_id = intval($input["order_id"] ?? 0);

    if (!$shopper_id || !$order_id) {
        response(false, null, "shopper_id e order_id obrigatórios", 400);
    }

    // Verificar pedido
    $stmt = $db->prepare("SELECT * FROM om_market_orders WHERE order_id = ?");
    $stmt->execute([$order_id]);
    $pedido = $stmt->fetch();

    if (!$pedido) response(false, null, "Pedido não encontrado", 404);
    if ($pedido["status"] !== "pendente") response(false, null, "Pedido já foi aceito", 409);
    if ($pedido["shopper_id"]) response(false, null, "Pedido já tem shopper", 409);

    // Aceitar
    $stmt = $db->prepare("UPDATE om_market_orders SET shopper_id = ?, status = 'aceito', accepted_at = NOW() WHERE order_id = ?");
    $stmt->execute([$shopper_id, $order_id]);

    $stmt = $db->prepare("UPDATE om_market_shoppers SET disponivel = 0, pedido_atual_id = ? WHERE shopper_id = ?");
    $stmt->execute([$order_id, $shopper_id]);

    // Buscar itens
    $stmt = $db->prepare("SELECT i.*, p.name, p.image FROM om_market_order_items i
                         INNER JOIN om_market_products_base p ON i.product_id = p.product_id
                         WHERE i.order_id = ?");
    $stmt->execute([$order_id]);
    $itens = $stmt->fetchAll();

    response(true, [
        "order_id" => $order_id,
        "status" => "aceito",
        "itens" => $itens,
        "codigo_entrega" => $pedido["codigo_entrega"] ?? null,
        "endereco_entrega" => $pedido["delivery_address"] ?? null
    ], "Pedido aceito!");

} catch (Exception $e) {
    response(false, null, $e->getMessage(), 500);
}
