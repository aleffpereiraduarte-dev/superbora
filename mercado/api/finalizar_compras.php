<?php
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }

require_once __DIR__ . '/../config/database.php';

$pdo = getPDO();

$input = json_decode(file_get_contents("php://input"), true) ?: $_POST;
$order_id = (int)($input["order_id"] ?? 0);
$shopper_id = (int)($input["shopper_id"] ?? 0);

if (!$order_id || !$shopper_id) { echo json_encode(["success" => false, "error" => "Dados inválidos"]); exit; }

try {
    $pdo->beginTransaction();
    
    $stmt = $pdo->prepare("SELECT * FROM om_market_orders WHERE order_id = ? AND shopper_id = ?");
    $stmt->execute([$order_id, $shopper_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) throw new Exception("Pedido não encontrado");
    if ($order["status"] !== "shopping") throw new Exception("Pedido não está em compras");
    
    $box_qr_code = "HO" . strtoupper(bin2hex(random_bytes(6)));
    
    $pdo->prepare("UPDATE om_market_orders SET status = 'purchased', purchased_at = NOW(), box_qr_code = ?, date_modified = NOW() WHERE order_id = ?")->execute([$box_qr_code, $order_id]);

    $shopper_first = explode(" ", $order["shopper_name"])[0];
    $pdo->prepare("INSERT INTO om_market_chat (order_id, sender_type, sender_id, sender_name, message, message_type, date_added) VALUES (?, 'system', 0, 'Sistema', ?, 'text', NOW())")->execute([$order_id, "✅ $shopper_first terminou de separar! Aguardando entregador..."]);

    // ========== INTEGRAÇÃO BORAUM ==========
    // Buscar dados do parceiro (loja) - usa lat/lng da própria tabela om_market_partners
    $stmt = $pdo->prepare("SELECT * FROM om_market_partners WHERE partner_id = ?");
    $stmt->execute([$order["partner_id"]]);
    $parceiro = $stmt->fetch(PDO::FETCH_ASSOC);

    // Buscar dados do shopper (localização atual)
    $stmt = $pdo->prepare("SELECT * FROM om_market_shoppers WHERE shopper_id = ?");
    $stmt->execute([$shopper_id]);
    $shopper = $stmt->fetch(PDO::FETCH_ASSOC);

    // Chamar API do BoraUm para solicitar entregador
    $boraum_data = [
        "pedido_id" => "MKT-" . $order_id,
        "loja_nome" => $parceiro["name"] ?? "Mercado",
        "loja_endereco" => $parceiro["address"] ?? $order["partner_address"] ?? "",
        "loja_lat" => floatval($shopper["latitude"] ?? $parceiro["lat"] ?? -23.2166),
        "loja_lng" => floatval($shopper["longitude"] ?? $parceiro["lng"] ?? -45.8811),
        "cliente_nome" => $order["customer_name"],
        "cliente_telefone" => $order["customer_phone"],
        "cliente_endereco" => $order["delivery_address"],
        "cliente_lat" => floatval($order["delivery_lat"] ?? -23.22),
        "cliente_lng" => floatval($order["delivery_lng"] ?? -45.89),
        "itens" => json_decode($order["items_json"] ?? "[]", true),
        "valor_pedido" => floatval($order["total"]),
        "taxa_entrega" => floatval($order["delivery_fee"] ?? 8),
        "forma_pagamento" => $order["payment_method"] ?? "cartao"
    ];

    $ch = curl_init("https://boraum.com.br/api_entrega.php?action=novo_pedido");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($boraum_data),
        CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
        CURLOPT_TIMEOUT => 10
    ]);
    $boraum_response = curl_exec($ch);
    $boraum_result = json_decode($boraum_response, true);
    curl_close($ch);

    $boraum_pedido_id = $boraum_result["pedido_id"] ?? null;
    if ($boraum_pedido_id) {
        $pdo->prepare("UPDATE om_market_orders SET boraum_pedido_id = ? WHERE order_id = ?")->execute([$boraum_pedido_id, $order_id]);
        $pdo->prepare("INSERT INTO om_market_chat (order_id, sender_type, sender_id, sender_name, message, message_type, date_added) VALUES (?, 'system', 0, 'Sistema', ?, 'text', NOW())")->execute([$order_id, "🚗 Buscando entregador BoraUm..."]);
    }
    // ========== FIM INTEGRAÇÃO BORAUM ==========

    $pdo->commit();
    echo json_encode(["success" => true, "status" => "purchased", "box_qr_code" => $box_qr_code, "boraum" => $boraum_result]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}