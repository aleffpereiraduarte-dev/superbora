<?php
/**
 * PATCH: Adicionar ações 'verify' e 'confirm' na API delivery_handoff.php
 * 
 * Cole este código NO FINAL do arquivo api/delivery_handoff.php (antes do último ?>)
 */

// ═══════════════════════════════════════════════════════════════════════════════
// AÇÃO: VERIFY - Verificar código de entrega
// ═══════════════════════════════════════════════════════════════════════════════

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $input = json_decode(file_get_contents("php://input"), true) ?: $_POST;
    $action = $input["action"] ?? "";
    
    if ($action === "verify") {
        $order_id = (int)($input["order_id"] ?? 0);
        $delivery_code = trim($input["delivery_code"] ?? "");
        
        if (!$order_id || !$delivery_code) {
            echo json_encode(["success" => false, "error" => "order_id e delivery_code obrigatórios"]);
            exit;
        }
        
        // Verificar código
        $stmt = $pdo->prepare("SELECT * FROM om_market_orders WHERE order_id = ? AND delivery_code = ?");
        $stmt->execute([$order_id, $delivery_code]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            echo json_encode(["success" => false, "valid" => false, "error" => "Código inválido"]);
            exit;
        }
        
        echo json_encode([
            "success" => true,
            "valid" => true,
            "order" => [
                "order_id" => (int)$order['order_id'],
                "customer_name" => $order['customer_name'],
                "total" => (float)$order['total'],
                "status" => $order['status']
            ]
        ]);
        exit;
    }
    
    // ═══════════════════════════════════════════════════════════════════════════════
    // AÇÃO: CONFIRM - Confirmar entrega
    // ═══════════════════════════════════════════════════════════════════════════════
    
    if ($action === "confirm") {
        $order_id = (int)($input["order_id"] ?? 0);
        $delivery_id = (int)($input["delivery_id"] ?? 0);
        $delivery_code = trim($input["delivery_code"] ?? "");
        $photo_url = trim($input["photo_url"] ?? "");
        $signature = trim($input["signature"] ?? "");
        
        if (!$order_id) {
            echo json_encode(["success" => false, "error" => "order_id obrigatório"]);
            exit;
        }
        
        // Verificar pedido
        $stmt = $pdo->prepare("SELECT * FROM om_market_orders WHERE order_id = ?");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            echo json_encode(["success" => false, "error" => "Pedido não encontrado"]);
            exit;
        }
        
        // Verificar código se fornecido
        if ($delivery_code && $order['delivery_code'] !== $delivery_code) {
            echo json_encode(["success" => false, "error" => "Código de entrega inválido"]);
            exit;
        }
        
        // Marcar como entregue
        $stmt = $pdo->prepare("
            UPDATE om_market_orders 
            SET status = 'delivered', 
                delivered_at = NOW(),
                delivery_photo = ?,
                chat_expires_at = DATE_ADD(NOW(), INTERVAL 60 MINUTE)
            WHERE order_id = ?
        ");
        $stmt->execute([$photo_url, $order_id]);
        
        // Liberar delivery
        if ($delivery_id) {
            $pdo->prepare("UPDATE om_market_deliveries SET is_busy = 0 WHERE delivery_id = ?")->execute([$delivery_id]);
        }
        
        // Histórico
        try {
            $pdo->prepare("INSERT INTO om_market_order_history (order_id, status, comment, created_at) VALUES (?, 'delivered', 'Entrega confirmada', NOW())")->execute([$order_id]);
        } catch (Exception $e) {}
        
        echo json_encode(["success" => true, "message" => "Entrega confirmada!"]);
        exit;
    }
}
