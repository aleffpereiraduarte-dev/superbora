<?php
// api/status.php
require_once '../config.php';
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
if (!isLoggedIn()) { echo json_encode(['success' => false]); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}
$allowedStatuses = ['online', 'offline', 'busy'];
$availability = $input['availability'] ?? 'offline';
if (!in_array($availability, $allowedStatuses)) {
    echo json_encode(['success' => false, 'error' => 'Invalid status']);
    exit;
}
$result = updateAvailability($_SESSION['delivery_id'], $availability);
echo json_encode(['success' => $result]);
