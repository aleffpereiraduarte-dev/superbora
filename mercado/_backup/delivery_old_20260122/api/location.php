<?php
require_once '../config.php';
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
if (!isLoggedIn()) { echo json_encode(['success' => false]); exit; }
// Rate limiting - max 10 requests per minute
if (!checkRateLimit($_SESSION['delivery_id'], 10, 60)) {
    echo json_encode(['success' => false, 'error' => 'Rate limit exceeded']);
    exit;
}
$rawInput = file_get_contents('php://input');
if (empty($rawInput)) {
    echo json_encode(['success' => false, 'error' => 'Empty request']);
    exit;
}
$input = json_decode($rawInput, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}
if (!$input || !isset($input['lat']) || !isset($input['lng'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid input']);
    exit;
}
if (!is_numeric($input['lat']) || !is_numeric($input['lng']) || abs($input['lat']) > 90 || abs($input['lng']) > 180) {
    echo json_encode(['success' => false, 'error' => 'Invalid coordinates']);
    exit;
}
$heading = isset($input['heading']) && is_numeric($input['heading']) ? (float)$input['heading'] : null;
$speed = isset($input['speed']) && is_numeric($input['speed']) ? (float)$input['speed'] : null;
updateLocation($_SESSION['delivery_id'], (float)$input['lat'], (float)$input['lng'], $heading, $speed);
echo json_encode(['success' => true]);
