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
$orderId = $data['order_id'] ?? 0;

if (!$orderId) {
    echo json_encode(['success' => false, 'error' => 'Pedido não informado']);
    exit;
}

try {
    $pdo = getDB();
    
    // Verificar permissão
    $stmt = $pdo->prepare("SELECT * FROM om_market_orders WHERE order_id = ? AND shopper_id = ?");
    $stmt->execute([$orderId, $workerId]);
    $order = $stmt->fetch();
    
    if (!$order) {
        echo json_encode(['success' => false, 'error' => 'Pedido não encontrado']);
        exit;
    }
    
    // Gerar código único para handoff
    $handoffCode = strtoupper(substr(md5($orderId . time() . $workerId), 0, 8));
    
    // Salvar código
    $stmt = $pdo->prepare("UPDATE om_market_orders SET handoff_code = ?, handoff_generated_at = NOW() WHERE order_id = ?");
    $stmt->execute([$handoffCode, $orderId]);
    
    // Dados para o QR Code
    $qrData = json_encode([
        'type' => 'onemundo_handoff',
        'order_id' => $orderId,
        'code' => $handoffCode,
        'shopper_id' => $workerId,
        'timestamp' => time()
    ]);
    
    // URL do QR Code usando API do Google Charts
    $qrUrl = 'https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl=' . urlencode($qrData) . '&choe=UTF-8';
    
    echo json_encode([
        'success' => true,
        'handoff_code' => $handoffCode,
        'qr_data' => $qrData,
        'qr_url' => $qrUrl
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}