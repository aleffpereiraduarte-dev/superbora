<?php
/**
 * GET /mercado/api/pedido/status.php?order_id=1
 */
require_once __DIR__ . "/../config.php";

try {
    $db = getDB();
    $order_id = $_GET["order_id"] ?? 0;
    
    $pedido = $db->query("SELECT o.*, p.name as parceiro_nome, s.name as shopper_nome, s.phone as shopper_telefone, s.latitude as shopper_lat, s.longitude as shopper_lng
                          FROM om_market_orders o
                          LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
                          LEFT JOIN om_market_shoppers s ON o.shopper_id = s.shopper_id
                          WHERE o.order_id = $order_id")->fetch();
    
    if (!$pedido) response(false, null, "Pedido não encontrado", 404);
    
    $itens = $db->query("SELECT i.*, p.name, p.image FROM om_market_order_items i
                         INNER JOIN om_market_products p ON i.product_id = p.product_id
                         WHERE i.order_id = $order_id")->fetchAll();
    
    $tempo_restante = null;
    if ($pedido["timer_fim"]) {
        $tempo_restante = max(0, round((strtotime($pedido["timer_fim"]) - time()) / 60));
    }
    
    response(true, [
        "order_id" => $pedido["order_id"],
        "status" => $pedido["status"],
        "codigo_entrega" => $pedido["codigo_entrega"],
        "total" => floatval($pedido["total"]),
        "tempo_restante_min" => $tempo_restante,
        "parceiro" => $pedido["parceiro_nome"],
        "shopper" => $pedido["shopper_id"] ? [
            "nome" => $pedido["shopper_nome"],
            "telefone" => $pedido["shopper_telefone"],
            "latitude" => $pedido["shopper_lat"],
            "longitude" => $pedido["shopper_lng"]
        ] : null,
        "endereco" => $pedido["delivery_address"],
        "itens" => $itens
    ]);
    
} catch (Exception $e) {
    response(false, null, $e->getMessage(), 500);
}
