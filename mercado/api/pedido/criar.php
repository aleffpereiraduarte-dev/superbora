<?php
/**
 * POST /mercado/api/pedido/criar.php
 */
require_once __DIR__ . "/../config.php";

try {
    $input = getInput();
    $db = getDB();
    
    $customer_id = $input["customer_id"] ?? 0;
    $session_id = $input["session_id"] ?? session_id();
    $endereco = $input["endereco"] ?? "";
    $latitude = $input["latitude"] ?? null;
    $longitude = $input["longitude"] ?? null;
    $forma_pagamento = $input["forma_pagamento"] ?? "pix";
    $observacoes = $input["observacoes"] ?? "";
    
    // Buscar carrinho
    $itens = $db->query("SELECT c.*, p.name FROM om_market_cart c
                         INNER JOIN om_market_products p ON c.product_id = p.product_id
                         WHERE c.customer_id = $customer_id OR c.session_id = \"$session_id\"")->fetchAll();
    
    if (empty($itens)) response(false, null, "Carrinho vazio", 400);
    
    $partner_id = $itens[0]["partner_id"];
    $parceiro = $db->query("SELECT * FROM om_market_partners WHERE partner_id = $partner_id")->fetch();
    
    $subtotal = array_sum(array_map(fn($i) => $i["price"] * $i["quantity"], $itens));
    $taxa_entrega = floatval($parceiro["delivery_fee"] ?? 0);
    $total = $subtotal + $taxa_entrega;
    
    $codigo_entrega = strtoupper(substr(md5(uniqid()), 0, 6));
    
    $sql = "INSERT INTO om_market_orders (partner_id, customer_id, status, subtotal, delivery_fee, total, delivery_address, latitude_entrega, longitude_entrega, notes, codigo_entrega, forma_pagamento, timer_inicio, timer_fim, date_added)
            VALUES (?, ?, \"pendente\", ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 60 MINUTE), NOW())";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$partner_id, $customer_id, $subtotal, $taxa_entrega, $total, $endereco, $latitude, $longitude, $observacoes, $codigo_entrega, $forma_pagamento]);
    
    $order_id = $db->lastInsertId();
    
    foreach ($itens as $item) {
        $db->exec("INSERT INTO om_market_order_items (order_id, product_id, quantity, price, subtotal) VALUES ($order_id, {$item["product_id"]}, {$item["quantity"]}, {$item["price"]}, " . ($item["price"] * $item["quantity"]) . ")");
    }
    
    $db->exec("DELETE FROM om_market_cart WHERE customer_id = $customer_id OR session_id = \"$session_id\"");
    
    response(true, [
        "order_id" => $order_id,
        "codigo_entrega" => $codigo_entrega,
        "status" => "pendente",
        "total" => round($total, 2),
        "tempo_estimado" => 60
    ], "Pedido criado!");
    
} catch (Exception $e) {
    response(false, null, $e->getMessage(), 500);
}
