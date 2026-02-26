<?php
/**
 * API - Finish Shopping
 */
require_once '../config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['worker_id'])) {
    jsonResponse(['success' => false, 'error' => 'Não autenticado'], 401);
}

$data = json_decode(file_get_contents('php://input'), true);
$order_id = intval($data['order_id'] ?? 0);
$shopper_id = $_SESSION['shopper_id'] ?? null;

if (!$order_id) {
    jsonResponse(['success' => false, 'error' => 'Dados inválidos']);
}

$pdo = getPDO();

try {
    $pdo->beginTransaction();
    
    // Verificar pedido
    $stmt = $pdo->prepare("
        SELECT o.*, s.can_deliver
        FROM om_market_orders o
        JOIN om_market_shoppers s ON o.shopper_id = s.shopper_id
        WHERE o.order_id = ? AND o.shopper_id = ? AND o.status = 'shopping'
    ");
    $stmt->execute([$order_id, $shopper_id]);
    $order = $stmt->fetch();
    
    if (!$order) {
        $pdo->rollBack();
        jsonResponse(['success' => false, 'error' => 'Pedido não encontrado']);
    }
    
    // Verificar itens escaneados
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM om_market_order_items WHERE order_id = ? AND scanned = 1");
    $stmt->execute([$order_id]);
    if ($stmt->fetchColumn() == 0) {
        $pdo->rollBack();
        jsonResponse(['success' => false, 'error' => 'Escaneie pelo menos um item']);
    }
    
    $can_deliver = $order['can_deliver'];
    
    if ($can_deliver) {
        // Vai entregar
        $pdo->prepare("UPDATE om_market_orders SET status = 'delivering', shopping_finished_at = NOW(), delivery_started_at = NOW() WHERE order_id = ?")
            ->execute([$order_id]);
        $pdo->prepare("INSERT INTO om_order_timeline (order_id, status, title, description, created_at) VALUES (?, 'delivering', 'Saiu para entrega', 'Pedido a caminho', NOW())")
            ->execute([$order_id]);
    } else {
        // Aguardando entregador
        $pdo->prepare("UPDATE om_market_orders SET status = 'ready', shopping_finished_at = NOW(), matching_status = 'searching_delivery' WHERE order_id = ?")
            ->execute([$order_id]);
        $pdo->prepare("INSERT INTO om_order_timeline (order_id, status, title, description, created_at) VALUES (?, 'ready', 'Compras finalizadas', 'Aguardando entregador', NOW())")
            ->execute([$order_id]);
    }
    
    $pdo->commit();
    
    jsonResponse([
        'success' => true,
        'can_deliver' => $can_deliver == 1,
        'message' => $can_deliver ? 'Vá entregar!' : 'Aguardando entregador'
    ]);
    
} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Erro finish: " . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Erro ao processar']);
}
