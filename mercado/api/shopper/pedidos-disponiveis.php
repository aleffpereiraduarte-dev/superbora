<?php
/**
 * GET /mercado/api/shopper/pedidos-disponiveis.php
 */
require_once __DIR__ . "/../config.php";

try {
    $db = getDB();
    
    $shopper_id = $_GET["shopper_id"] ?? 0;
    $lat = $_GET["lat"] ?? 0;
    $lng = $_GET["lng"] ?? 0;
    
    $sql = "SELECT o.*, p.name as parceiro_nome, p.address as parceiro_endereco, p.logo,
            (SELECT COUNT(*) FROM om_market_order_items WHERE order_id = o.order_id) as total_itens
            FROM om_market_orders o
            INNER JOIN om_market_partners p ON o.partner_id = p.partner_id
            WHERE o.status = \"pendente\" AND o.shopper_id IS NULL
            ORDER BY o.date_added DESC
            LIMIT 20";
    
    $pedidos = $db->query($sql)->fetchAll();
    
    response(true, [
        "total" => count($pedidos),
        "pedidos" => array_map(function($p) {
            return [
                "order_id" => $p["order_id"],
                "parceiro" => [
                    "nome" => $p["parceiro_nome"],
                    "endereco" => $p["parceiro_endereco"],
                    "logo" => $p["logo"]
                ],
                "total_itens" => (int)$p["total_itens"],
                "valor_total" => floatval($p["total"]),
                "endereco_entrega" => $p["delivery_address"],
                "criado_em" => $p["date_added"]
            ];
        }, $pedidos)
    ]);
    
} catch (Exception $e) {
    response(false, null, $e->getMessage(), 500);
}
