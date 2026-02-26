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
$replacementName = $data['replacement_name'] ?? '';
$replacementPrice = $data['replacement_price'] ?? 0;
$replacementEan = $data['replacement_ean'] ?? '';
$reason = $data['reason'] ?? 'Produto indisponível';

if (!$itemId || !$replacementName) {
    echo json_encode(['success' => false, 'error' => 'Dados incompletos']);
    exit;
}

try {
    $pdo = getDB();
    $pdo->beginTransaction();
    
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
        throw new Exception('Item não encontrado');
    }
    
    // Atualizar como substituído
    $stmt = $pdo->prepare("
        UPDATE om_market_order_items SET 
            status = 'replaced',
            replacement_name = ?,
            replacement_price = ?,
            replacement_ean = ?,
            replacement_reason = ?,
            picked_at = NOW()
        WHERE item_id = ?
    ");
    $stmt->execute([$replacementName, $replacementPrice, $replacementEan, $reason, $itemId]);
    
    // Mensagem no chat
    $msg = "📦 Substituição: \"{$item['product_name']}\" → \"{$replacementName}\"";
    $stmt = $pdo->prepare("INSERT INTO om_order_chat (order_id, sender_type, sender_id, message, created_at) VALUES (?, 'shopper', ?, ?, NOW())");
    $stmt->execute([$item['order_id'], $workerId, $msg]);
    
    $pdo->commit();
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}