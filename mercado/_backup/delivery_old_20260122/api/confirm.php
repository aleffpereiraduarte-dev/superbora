<?php
require_once '../config.php';
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}
if (!isLoggedIn()) { echo json_encode(['success' => false]); exit; }
if (!hash_equals($_SESSION['csrf_token'] ?? '', $input['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !is_array($input)) {
    echo json_encode(['success' => false, 'error' => 'Invalid input']);
    exit;
}
$order_id = filter_var($input['order_id'] ?? 0, FILTER_VALIDATE_INT);
$code = filter_var($input['code'] ?? '', FILTER_SANITIZE_STRING);
if ($order_id === false || empty($code)) {
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
    exit;
}
echo json_encode(confirmDelivery($order_id, $_SESSION['delivery_id'], $code));
