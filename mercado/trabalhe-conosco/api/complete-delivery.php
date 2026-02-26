<?php
require_once dirname(__DIR__, 2) . '/config/database.php';
/**
 * API: Completar Entrega
 * POST /api/complete-delivery.php
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

session_start();

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $orderId = $input['order_id'] ?? null;
    $workerId = $input['worker_id'] ?? $_SESSION['worker_id'] ?? null;
    $codeVerified = $input['code_verified'] ?? false;
    $photoUrl = $input['photo_url'] ?? null;
    $signature = $input['signature'] ?? null;
    $notes = $input['notes'] ?? null;
    
    if (!$orderId || !$workerId) {
        throw new Exception('order_id e worker_id são obrigatórios');
    }
    
    $conn = getMySQLi();
    $conn->set_charset('utf8mb4');
    
    if ($conn->connect_error) {
        throw new Exception('Erro de conexão');
    }
    
    // Buscar pedido
    $stmt = $conn->prepare("SELECT * FROM om_worker_deliveries WHERE order_id = ? AND worker_id = ?");
    $stmt->bind_param("si", $orderId, $workerId);
    $stmt->execute();
    $delivery = $stmt->get_result()->fetch_assoc();
    
    if (!$delivery) {
        throw new Exception('Entrega não encontrada');
    }
    
    if ($delivery['status'] === 'delivered') {
        throw new Exception('Entrega já foi finalizada');
    }
    
    // Calcular ganhos
    $earnings = $delivery['earnings'] ?? 0;
    $tip = $delivery['tip'] ?? 0;
    $totalEarnings = $earnings + $tip;
    
    // Atualizar entrega
    $stmt = $conn->prepare("
        UPDATE om_worker_deliveries 
        SET status = 'delivered',
            delivered_at = NOW(),
            code_verified = ?,
            delivery_photo = ?,
            delivery_signature = ?,
            delivery_notes = ?
        WHERE order_id = ? AND worker_id = ?
    ");
    $stmt->bind_param("issssi", $codeVerified, $photoUrl, $signature, $notes, $orderId, $workerId);
    $stmt->execute();
    
    // Atualizar saldo do worker
    $stmt = $conn->prepare("
        UPDATE om_workers 
        SET balance = balance + ?,
            total_deliveries = total_deliveries + 1,
            today_deliveries = today_deliveries + 1,
            today_earnings = today_earnings + ?
        WHERE worker_id = ?
    ");
    $stmt->bind_param("ddi", $totalEarnings, $totalEarnings, $workerId);
    $stmt->execute();
    
    // Atualizar pedido principal
    $stmt = $conn->prepare("UPDATE om_orders SET status = 'delivered', delivered_at = NOW() WHERE order_id = ?");
    $stmt->bind_param("s", $orderId);
    $stmt->execute();
    
    $conn->close();
    
    echo json_encode([
        'success' => true,
        'message' => 'Entrega concluída com sucesso',
        'data' => [
            'order_id' => $orderId,
            'earnings' => $earnings,
            'tip' => $tip,
            'total' => $totalEarnings
        ]
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
