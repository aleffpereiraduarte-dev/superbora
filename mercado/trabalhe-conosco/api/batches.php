<?php
/**
 * API: Batches (Lotes de pedidos)
 */
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/BatchingHelper.php';
header('Content-Type: application/json');

if (!isset($_SESSION['worker_id'])) {
    echo json_encode(['success' => false, 'error' => 'Não autorizado']);
    exit;
}

$workerId = $_SESSION['worker_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$pdo = getDB();
$batching = new BatchingHelper($pdo);

switch ($action) {
    case 'get-available':
        $lat = $_GET['lat'] ?? null;
        $lng = $_GET['lng'] ?? null;
        $batches = $batching->getAvailableBatches($workerId, $lat, $lng);
        echo json_encode(['success' => true, 'batches' => $batches]);
        break;
        
    case 'accept':
        $batchId = $_POST['batch_id'] ?? 0;
        $result = $batching->acceptBatch($workerId, $batchId);
        echo json_encode($result);
        break;
        
    case 'queue':
        $batchId = $_POST['batch_id'] ?? 0;
        $result = $batching->queueNextBatch($workerId, $batchId);
        echo json_encode($result);
        break;
        
    case 'start-queued':
        $result = $batching->startQueuedBatch($workerId);
        echo json_encode(['success' => (bool)$result, 'batch' => $result]);
        break;
        
    default:
        echo json_encode(['success' => false, 'error' => 'Ação inválida']);
}