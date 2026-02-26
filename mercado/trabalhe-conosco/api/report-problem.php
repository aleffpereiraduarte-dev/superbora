<?php
require_once dirname(__DIR__, 2) . '/config/database.php';
/**
 * API: Reportar Problema
 * POST /api/report-problem.php
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
    
    $workerId = $input['worker_id'] ?? $_SESSION['worker_id'] ?? null;
    $orderId = $input['order_id'] ?? null;
    $problemType = $input['type'] ?? null;
    $description = $input['description'] ?? null;
    $photoUrl = $input['photo_url'] ?? null;
    $lat = $input['lat'] ?? null;
    $lng = $input['lng'] ?? null;
    
    if (!$workerId || !$problemType) {
        throw new Exception('worker_id e type são obrigatórios');
    }
    
    // Tipos válidos de problema
    $validTypes = [
        'product_missing',      // Produto em falta
        'product_damaged',      // Produto danificado
        'wrong_address',        // Endereço errado
        'customer_absent',      // Cliente ausente
        'access_denied',        // Acesso negado
        'vehicle_problem',      // Problema com veículo
        'accident',             // Acidente
        'unsafe_location',      // Local inseguro
        'app_bug',              // Problema no app
        'payment_issue',        // Problema com pagamento
        'other'                 // Outro
    ];
    
    if (!in_array($problemType, $validTypes)) {
        throw new Exception('Tipo de problema inválido');
    }
    
    $conn = getMySQLi();
    $conn->set_charset('utf8mb4');
    
    if ($conn->connect_error) {
        throw new Exception('Erro de conexão');
    }
    
    // Inserir problema
    $stmt = $conn->prepare("
        INSERT INTO om_worker_problems 
        (worker_id, order_id, problem_type, description, photo_url, lat, lng, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'open', NOW())
    ");
    $stmt->bind_param("issssdd", $workerId, $orderId, $problemType, $description, $photoUrl, $lat, $lng);
    $stmt->execute();
    $problemId = $conn->insert_id;
    
    // Se for problema grave, notificar suporte
    $urgentTypes = ['accident', 'unsafe_location', 'vehicle_problem'];
    $isUrgent = in_array($problemType, $urgentTypes);
    
    if ($isUrgent) {
        // Aqui seria enviada notificação para o suporte
        // sendSupportNotification($problemId, $problemType, $workerId);
    }
    
    // Se tiver pedido associado, atualizar status
    if ($orderId) {
        $stmt = $conn->prepare("
            UPDATE om_worker_deliveries 
            SET has_problem = 1, problem_id = ?
            WHERE order_id = ? AND worker_id = ?
        ");
        $stmt->bind_param("isi", $problemId, $orderId, $workerId);
        $stmt->execute();
    }
    
    $conn->close();
    
    echo json_encode([
        'success' => true,
        'message' => 'Problema reportado com sucesso',
        'data' => [
            'problem_id' => $problemId,
            'is_urgent' => $isUrgent,
            'support_notified' => $isUrgent
        ]
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
