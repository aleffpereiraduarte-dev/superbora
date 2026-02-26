<?php
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');
if (!isLoggedIn()) { echo json_encode(['success' => false]); exit; }
$data = json_decode(file_get_contents('php://input'), true);
getDB()->prepare("UPDATE om_shopper_offers SET status = 'rejected' WHERE shopper_id = ? AND order_id = ?")->execute([getWorkerId(), $data['order_id'] ?? 0]);
echo json_encode(['success' => true]);