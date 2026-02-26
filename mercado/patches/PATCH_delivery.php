<?php
/**
 * PATCH: Adicionar ações 'accept' e 'pickup' na API delivery.php
 * 
 * Cole este código NO FINAL do arquivo api/delivery.php (antes do último ?>)
 */

// ═══════════════════════════════════════════════════════════════════════════════
// AÇÃO: ACCEPT - Delivery aceita a entrega
// ═══════════════════════════════════════════════════════════════════════════════

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $input = json_decode(file_get_contents("php://input"), true) ?: $_POST;
    $action = $input["action"] ?? "";
    
    if ($action === "accept") {
        $delivery_id = (int)($input["delivery_id"] ?? 0);
        $order_id = (int)($input["order_id"] ?? 0);
        $offer_id = (int)($input["offer_id"] ?? 0);
        
        if (!$delivery_id || !$order_id) {
            echo json_encode(["success" => false, "error" => "delivery_id e order_id obrigatórios"]);
            exit;
        }
        
        // Verificar pedido
        $stmt = $pdo->prepare("SELECT * FROM om_market_orders WHERE order_id = ? AND status = 'ready'");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            echo json_encode(["success" => false, "error" => "Pedido não disponível"]);
            exit;
        }
        
        // Atribuir delivery
        $pdo->prepare("UPDATE om_market_orders SET delivery_id = ?, status = 'delivering' WHERE order_id = ?")->execute([$delivery_id, $order_id]);
        
        // Atualizar oferta se existir
        if ($offer_id) {
            $pdo->prepare("UPDATE om_delivery_offers SET status = 'accepted', accepted_at = NOW() WHERE offer_id = ?")->execute([$offer_id]);
        }
        
        // Histórico
        try {
            $pdo->prepare("INSERT INTO om_market_order_history (order_id, status, comment, created_at) VALUES (?, 'delivering', 'Entregador aceitou', NOW())")->execute([$order_id]);
        } catch (Exception $e) {}
        
        echo json_encode(["success" => true, "message" => "Entrega aceita!"]);
        exit;
    }
    
    // ═══════════════════════════════════════════════════════════════════════════════
    // AÇÃO: PICKUP - Delivery retirou o pedido
    // ═══════════════════════════════════════════════════════════════════════════════
    
    if ($action === "pickup") {
        $delivery_id = (int)($input["delivery_id"] ?? 0);
        $order_id = (int)($input["order_id"] ?? 0);
        $qr_code = trim($input["qr_code"] ?? "");
        
        if (!$delivery_id || !$order_id) {
            echo json_encode(["success" => false, "error" => "delivery_id e order_id obrigatórios"]);
            exit;
        }
        
        // Verificar pedido
        $stmt = $pdo->prepare("SELECT * FROM om_market_orders WHERE order_id = ? AND delivery_id = ?");
        $stmt->execute([$order_id, $delivery_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            echo json_encode(["success" => false, "error" => "Pedido não encontrado"]);
            exit;
        }
        
        // Verificar QR se fornecido
        if ($qr_code && $order['box_qr_code'] && $qr_code !== $order['box_qr_code']) {
            echo json_encode(["success" => false, "error" => "QR Code inválido"]);
            exit;
        }
        
        // Marcar como retirado
        $pdo->prepare("UPDATE om_market_orders SET picked_up_at = NOW() WHERE order_id = ?")->execute([$order_id]);
        
        // Histórico
        try {
            $pdo->prepare("INSERT INTO om_market_order_history (order_id, status, comment, created_at) VALUES (?, 'picked_up', 'Pedido retirado pelo entregador', NOW())")->execute([$order_id]);
        } catch (Exception $e) {}
        
        echo json_encode(["success" => true, "message" => "Pedido retirado!"]);
        exit;
    }
}
