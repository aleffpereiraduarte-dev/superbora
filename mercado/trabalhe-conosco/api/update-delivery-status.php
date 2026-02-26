<?php
require_once dirname(__DIR__, 2) . '/config/database.php';
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['worker_id'])) {
    exit(json_encode(['success' => false]));
}

$data = json_decode(file_get_contents('php://input'), true);
$delivery_id = intval($data['delivery_id'] ?? 0);
$status = $data['status'] ?? '';

$valid = ['aceito', 'coletando', 'coletado', 'em_transito', 'chegou', 'entregue'];
if (!in_array($status, $valid)) {
    exit(json_encode(['success' => false, 'error' => 'Status inválido']));
}

$pdo = getPDO();
$worker_id = intval($_SESSION['worker_id']);

// Build query based on status
$params = [$status, $delivery_id, $worker_id];

if ($status === 'coletando') {
    $sql = "UPDATE om_worker_deliveries SET status = ?, started_at = NOW() WHERE delivery_id = ? AND worker_id = ?";
} elseif ($status === 'coletado') {
    $sql = "UPDATE om_worker_deliveries SET status = ?, picked_up_at = NOW() WHERE delivery_id = ? AND worker_id = ?";
} elseif ($status === 'entregue') {
    $sql = "UPDATE om_worker_deliveries SET status = ?, delivered_at = NOW(), finished_at = NOW() WHERE delivery_id = ? AND worker_id = ?";
} else {
    $sql = "UPDATE om_worker_deliveries SET status = ? WHERE delivery_id = ? AND worker_id = ?";
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

echo json_encode(['success' => true]);
