<?php
require_once '../config.php';
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!isLoggedIn()) { 
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']); 
    exit; 
}

$input = json_decode(file_get_contents('php://input'), true);
if ($input === null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

$order_id = intval($input['order_id'] ?? 0);

if (!$order_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'order_id obrigatório']);
    exit;
}

try {
    $result = acceptDelivery($order_id, $_SESSION['delivery_id']);
    echo json_encode($result);
} catch (Exception $e) {
    error_log('Accept delivery error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}
