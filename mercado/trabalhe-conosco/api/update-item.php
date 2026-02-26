<?php
session_start();
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['worker_id'])) {
    echo json_encode(['success' => false, 'error' => 'Não autorizado']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$workerId = $_SESSION['worker_id'];
$itemId = $data['item_id'] ?? 0;
$status = $data['status'] ?? 'picked';
$quantity = $data['quantity'] ?? null;

if (!$itemId) {
    echo json_encode(['success' => false, 'error' => 'Item não informado']);
    exit;
}

try {
    $pdo = getDB();
    
    // Verificar permissão
    $stmt = $pdo->prepare("
        SELECT oi.*, o.shopper_id, o.order_id
        FROM om_market_order_items oi
        JOIN om_market_orders o ON oi.order_id = o.order_id
        WHERE oi.item_id = ? AND o.shopper_id = ?
    ");
    $stmt->execute([$itemId, $workerId]);
    $item = $stmt->fetch();
    
    if (!$item) {
        echo json_encode(['success' => false, 'error' => 'Item não encontrado']);
        exit;
    }
    
    // Atualizar
    $sql = "UPDATE om_market_order_items SET status = ?, picked_at = NOW()";
    $params = [$status];
    
    if ($quantity !== null) {
        $sql .= ", picked_quantity = ?";
        $params[] = $quantity;
    }
    
    $sql .= " WHERE item_id = ?";
    $params[] = $itemId;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    // Progresso
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total,
               SUM(CASE WHEN status IN ('picked','replaced') THEN 1 ELSE 0 END) as done
        FROM om_market_order_items WHERE order_id = ?
    ");
    $stmt->execute([$item['order_id']]);
    $progress = $stmt->fetch();
    
    echo json_encode([
        'success' => true,
        'progress' => [
            'done' => (int)$progress['done'],
            'total' => (int)$progress['total']
        ]
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}