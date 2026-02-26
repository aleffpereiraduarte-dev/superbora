<?php
session_start();
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['worker_id'])) {
    echo json_encode(['success' => false, 'error' => 'Não autorizado']);
    exit;
}

$workerId = $_SESSION['worker_id'];
$orderId = $_GET['order_id'] ?? 0;
$lastId = $_GET['last_id'] ?? 0;

if (!$orderId) {
    echo json_encode(['success' => false, 'error' => 'Pedido não informado']);
    exit;
}

try {
    $pdo = getDB();
    
    // Verificar permissão
    $stmt = $pdo->prepare("SELECT order_id FROM om_market_orders WHERE order_id = ? AND shopper_id = ?");
    $stmt->execute([$orderId, $workerId]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Não autorizado']);
        exit;
    }
    
    // Buscar mensagens
    $sql = "SELECT * FROM om_order_chat WHERE order_id = ?";
    $params = [$orderId];
    
    if ($lastId > 0) {
        $sql .= " AND chat_id > ?";
        $params[] = $lastId;
    }
    
    $sql .= " ORDER BY created_at ASC LIMIT 100";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $messages = $stmt->fetchAll();
    
    // Formatar
    $formatted = [];
    foreach ($messages as $msg) {
        $formatted[] = [
            'id' => $msg['chat_id'],
            'sender_type' => $msg['sender_type'],
            'sender_id' => $msg['sender_id'],
            'message' => $msg['message'],
            'image_url' => $msg['image_url'] ?? null,
            'time' => date('H:i', strtotime($msg['created_at'])),
            'is_mine' => ($msg['sender_type'] === 'shopper' && $msg['sender_id'] == $workerId)
        ];
    }
    
    echo json_encode([
        'success' => true,
        'messages' => $formatted,
        'count' => count($formatted)
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}