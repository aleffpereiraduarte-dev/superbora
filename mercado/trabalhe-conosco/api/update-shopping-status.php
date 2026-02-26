<?php
require_once dirname(__DIR__, 2) . '/config/database.php';
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['worker_id'])) {
    exit(json_encode(['success' => false]));
}

$data = json_decode(file_get_contents('php://input'), true);
$shopping_id = intval($data['shopping_id'] ?? 0);
$status = $data['status'] ?? '';

$valid = ['aceito', 'indo_loja', 'na_loja', 'comprando', 'checkout', 'finalizado'];
if (!in_array($status, $valid)) {
    exit(json_encode(['success' => false, 'error' => 'Status inválido']));
}

$pdo = getPDO();
$worker_id = intval($_SESSION['worker_id']);

// Build query based on status
$params = [$status, $shopping_id, $worker_id];

if ($status === 'indo_loja') {
    $sql = "UPDATE om_worker_shopping SET status = ?, started_at = NOW() WHERE shopping_id = ? AND worker_id = ?";
} elseif ($status === 'na_loja') {
    $sql = "UPDATE om_worker_shopping SET status = ?, arrived_store_at = NOW() WHERE shopping_id = ? AND worker_id = ?";
} elseif ($status === 'finalizado') {
    $sql = "UPDATE om_worker_shopping SET status = ?, finished_at = NOW() WHERE shopping_id = ? AND worker_id = ?";
} else {
    $sql = "UPDATE om_worker_shopping SET status = ? WHERE shopping_id = ? AND worker_id = ?";
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

echo json_encode(['success' => true]);
