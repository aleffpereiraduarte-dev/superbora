<?php
require_once dirname(__DIR__, 2) . '/config/database.php';
/**
 * API: Registrar Push Token
 * POST /api/register-push-token.php
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
    
    $token = $input['token'] ?? null;
    $workerId = $input['worker_id'] ?? $_SESSION['worker_id'] ?? null;
    $platform = $input['platform'] ?? 'android'; // android, ios, web
    
    if (!$token || !$workerId) {
        throw new Exception('Token e worker_id são obrigatórios');
    }
    
    $conn = getMySQLi();
    $conn->set_charset('utf8mb4');
    
    if ($conn->connect_error) {
        throw new Exception('Erro de conexão');
    }
    
    // Verificar se token já existe
    $stmt = $conn->prepare("SELECT id FROM om_push_tokens WHERE token = ?");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        // Atualizar token existente
        $stmt = $conn->prepare("UPDATE om_push_tokens SET worker_id = ?, platform = ?, updated_at = NOW() WHERE token = ?");
        $stmt->bind_param("iss", $workerId, $platform, $token);
    } else {
        // Inserir novo token
        $stmt = $conn->prepare("INSERT INTO om_push_tokens (worker_id, token, platform, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->bind_param("iss", $workerId, $token, $platform);
    }
    
    $stmt->execute();
    $conn->close();
    
    echo json_encode([
        'success' => true,
        'message' => 'Token registrado com sucesso'
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
