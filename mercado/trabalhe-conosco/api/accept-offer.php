<?php
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');
if (!isLoggedIn()) { echo json_encode(['success' => false]); exit; }
$data = json_decode(file_get_contents('php://input'), true);
echo json_encode(acceptOffer(getWorkerId(), $data['order_id'] ?? 0));