<?php
/**
 * PATCH: Adicionar ações 'list' e 'cancel' na API orders.php
 * 
 * Cole este código NO FINAL do arquivo api/orders.php (antes do último ?>)
 */

// ═══════════════════════════════════════════════════════════════════════════════
// AÇÃO: LIST - Listar pedidos do cliente
// ═══════════════════════════════════════════════════════════════════════════════

if ($_SERVER["REQUEST_METHOD"] === "GET" && isset($_GET["action"]) && $_GET["action"] === "list") {
    $customer_id = (int)($_GET["customer_id"] ?? 0);
    $status = $_GET["status"] ?? "";
    $limit = min(50, max(1, (int)($_GET["limit"] ?? 20)));
    $page = max(1, (int)($_GET["page"] ?? 1));
    $offset = ($page - 1) * $limit;
    
    if (!$customer_id) {
        echo json_encode(["success" => false, "error" => "customer_id obrigatório"]);
        exit;
    }
    
    $where = "customer_id = ?";
    $params = [$customer_id];
    
    if ($status && in_array($status, ['pending', 'confirmed', 'shopping', 'ready', 'delivering', 'delivered', 'cancelled'])) {
        $where .= " AND status = ?";
        $params[] = $status;
    }
    
    // Total
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM om_market_orders WHERE $where");
    $stmt->execute($params);
    $total = $stmt->fetchColumn();
    
    // Pedidos
    $params[] = $limit;
    $params[] = $offset;
    $stmt = $pdo->prepare("
        SELECT order_id, status, total, delivery_code, created_at, delivered_at,
               shopper_name, customer_name, partner_id
        FROM om_market_orders 
        WHERE $where
        ORDER BY order_id DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        "success" => true,
        "orders" => $orders,
        "pagination" => [
            "page" => $page,
            "limit" => $limit,
            "total" => (int)$total,
            "pages" => ceil($total / $limit)
        ]
    ]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════════
// AÇÃO: CANCEL - Cancelar pedido
// ═══════════════════════════════════════════════════════════════════════════════

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $input = json_decode(file_get_contents("php://input"), true) ?: $_POST;
    
    if (isset($input["action"]) && $input["action"] === "cancel") {
        $order_id = (int)($input["order_id"] ?? 0);
        $customer_id = (int)($input["customer_id"] ?? 0);
        $reason = trim($input["reason"] ?? "");
        
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
        
        // Verificar se pode cancelar
        $cancelable = ['pending', 'confirmed'];
        if (!in_array($order['status'], $cancelable)) {
            echo json_encode(["success" => false, "error" => "Pedido não pode ser cancelado neste status"]);
            exit;
        }
        
        // Cancelar
        $stmt = $pdo->prepare("UPDATE om_market_orders SET status = 'cancelled', cancel_reason = ? WHERE order_id = ?");
        $stmt->execute([$reason, $order_id]);
        
        // Histórico
        try {
            $stmt = $pdo->prepare("INSERT INTO om_market_order_history (order_id, status, comment, created_at) VALUES (?, 'cancelled', ?, NOW())");
            $stmt->execute([$order_id, "Cancelado pelo cliente: $reason"]);
        } catch (Exception $e) {}
        
        echo json_encode(["success" => true, "message" => "Pedido cancelado"]);
        exit;
    }
}
