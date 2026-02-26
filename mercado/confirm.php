<?php
require_once '../config.php';
header('Content-Type: application/json');
if (!isLoggedIn()) { echo json_encode(['success' => false]); exit; }
$input = json_decode(file_get_contents('php://input'), true);
echo json_encode(confirmDelivery($input['order_id'] ?? 0, $_SESSION['delivery_id'], $input['code'] ?? ''));
