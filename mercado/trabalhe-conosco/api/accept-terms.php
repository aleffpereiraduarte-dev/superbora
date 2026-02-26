<?php
require_once dirname(__DIR__, 2) . '/config/database.php';
/**
 * API: Aceitar Termos
 * POST /api/accept-terms.php
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
    $type = $input['type'] ?? null; // terms, privacy, contract
    $acceptedAt = $input['accepted_at'] ?? date('c');
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    
    if (!$workerId || !$type) {
        throw new Exception('worker_id e type são obrigatórios');
    }
    
    $validTypes = ['terms', 'privacy', 'contract'];
    if (!in_array($type, $validTypes)) {
        throw new Exception('Tipo inválido');
    }
    
    $conn = getMySQLi();
    $conn->set_charset('utf8mb4');
    
    if ($conn->connect_error) {
        throw new Exception('Erro de conexão');
    }
    
    // Registrar aceitação
    $stmt = $conn->prepare("
        INSERT INTO om_terms_acceptance 
        (worker_id, term_type, term_version, accepted_at, ip_address, user_agent)
        VALUES (?, ?, '2.1', ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
        term_version = '2.1', 
        accepted_at = VALUES(accepted_at),
        ip_address = VALUES(ip_address),
        user_agent = VALUES(user_agent)
    ");
    $stmt->bind_param("issss", $workerId, $type, $acceptedAt, $ipAddress, $userAgent);
    $stmt->execute();
    
    // Atualizar worker se aceitou todos os termos
    $stmt = $conn->prepare("
        UPDATE om_workers 
        SET terms_accepted = 1, terms_accepted_at = NOW()
        WHERE worker_id = ?
        AND (SELECT COUNT(DISTINCT term_type) FROM om_terms_acceptance WHERE worker_id = ?) = 3
    ");
    $stmt->bind_param("ii", $workerId, $workerId);
    $stmt->execute();
    
    $conn->close();
    
    echo json_encode([
        'success' => true,
        'message' => 'Termos aceitos com sucesso'
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
