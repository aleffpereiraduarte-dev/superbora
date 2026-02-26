<?php
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');
if (!isLoggedIn()) { echo json_encode(['success' => false]); exit; }
$data = json_decode(file_get_contents('php://input'), true);
$stmt = getDB()->prepare("INSERT INTO om_market_worker_locations (worker_id, latitude, longitude, updated_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE latitude = VALUES(latitude), longitude = VALUES(longitude), updated_at = NOW()");
$stmt->execute([getWorkerId(), $data['lat'] ?? 0, $data['lng'] ?? 0]);
echo json_encode(['success' => true]);