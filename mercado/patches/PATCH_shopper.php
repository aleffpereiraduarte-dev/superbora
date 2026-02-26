<?php
/**
 * PATCH: Adicionar ações 'finish' e 'substitute' na API shopper.php
 * 
 * Cole este código NO FINAL do arquivo api/shopper.php (antes do último ?>)
 */

// ═══════════════════════════════════════════════════════════════════════════════
// AÇÃO: FINISH - Shopper finaliza compras
// ═══════════════════════════════════════════════════════════════════════════════

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $input = json_decode(file_get_contents("php://input"), true) ?: $_POST;
    $action = $input["action"] ?? "";
    
    if ($action === "finish") {
        $shopper_id = (int)($input["shopper_id"] ?? 0);
        $order_id = (int)($input["order_id"] ?? 0);
        $total_real = (float)($input["total_real"] ?? 0);
        
        if (!$shopper_id || !$order_id) {
            echo json_encode(["success" => false, "error" => "shopper_id e order_id obrigatórios"]);
            exit;
        }
        
        // Verificar pedido
        $stmt = $pdo->prepare("SELECT * FROM om_market_orders WHERE order_id = ? AND shopper_id = ?");
        $stmt->execute([$order_id, $shopper_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            echo json_encode(["success" => false, "error" => "Pedido não encontrado"]);
            exit;
        }
        
        // Gerar código de entrega
        $delivery_code = strtoupper(substr(md5($order_id . time()), 0, 6));
        
        // Atualizar pedido
        $stmt = $pdo->prepare("
            UPDATE om_market_orders 
            SET status = 'ready', 
                delivery_code = ?,
                total_real = ?,
                shopping_finished_at = NOW()
            WHERE order_id = ?
        ");
        $stmt->execute([$delivery_code, $total_real ?: $order['total'], $order_id]);
        
        // Liberar shopper
        $pdo->prepare("UPDATE om_market_shoppers SET is_busy = 0, current_order_id = NULL WHERE shopper_id = ?")->execute([$shopper_id]);
        
        // Histórico
        try {
            $pdo->prepare("INSERT INTO om_market_order_history (order_id, status, comment, created_at) VALUES (?, 'ready', 'Compras finalizadas pelo shopper', NOW())")->execute([$order_id]);
        } catch (Exception $e) {}
        
        echo json_encode([
            "success" => true, 
            "message" => "Compras finalizadas!",
            "delivery_code" => $delivery_code
        ]);
        exit;
    }
    
    // ═══════════════════════════════════════════════════════════════════════════════
    // AÇÃO: SUBSTITUTE - Substituir produto
    // ═══════════════════════════════════════════════════════════════════════════════
    
    if ($action === "substitute") {
        $order_id = (int)($input["order_id"] ?? 0);
        $item_id = (int)($input["item_id"] ?? 0);
        $original_product_id = (int)($input["original_product_id"] ?? 0);
        $substitute_product_id = (int)($input["substitute_product_id"] ?? 0);
        $substitute_name = trim($input["substitute_name"] ?? "");
        $substitute_price = (float)($input["substitute_price"] ?? 0);
        $reason = trim($input["reason"] ?? "");
        
        if (!$order_id || (!$item_id && !$original_product_id)) {
            echo json_encode(["success" => false, "error" => "Dados incompletos"]);
            exit;
        }
        
        // Buscar item
        $where = $item_id ? "item_id = ?" : "order_id = ? AND product_id = ?";
        $params = $item_id ? [$item_id] : [$order_id, $original_product_id];
        
        $stmt = $pdo->prepare("SELECT * FROM om_market_order_items WHERE $where");
        $stmt->execute($params);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$item) {
            echo json_encode(["success" => false, "error" => "Item não encontrado"]);
            exit;
        }
        
        // Atualizar item
        $stmt = $pdo->prepare("
            UPDATE om_market_order_items 
            SET substituted = 1,
                substitute_product_id = ?,
                substitute_name = ?,
                substitute_price = ?,
                substitute_reason = ?,
                substitute_status = 'pending'
            WHERE item_id = ?
        ");
        $stmt->execute([$substitute_product_id, $substitute_name, $substitute_price, $reason, $item['item_id']]);
        
        // Notificar cliente via chat
        try {
            $pdo->prepare("
                INSERT INTO om_market_chat (order_id, sender_type, sender_name, message, message_type, date_added)
                VALUES (?, 'system', 'Sistema', ?, 'substitution', NOW())
            ")->execute([$order_id, "Substituição sugerida: " . ($substitute_name ?: "Produto alternativo")]);
        } catch (Exception $e) {}
        
        echo json_encode(["success" => true, "message" => "Substituição enviada para aprovação"]);
        exit;
    }
}
