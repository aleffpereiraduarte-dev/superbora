<?php
/**
 * API: Account Health (Saúde da conta)
 */
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/AccountHealthHelper.php';
header('Content-Type: application/json');

if (!isset($_SESSION['worker_id'])) {
    echo json_encode(['success' => false, 'error' => 'Não autorizado']);
    exit;
}

$workerId = $_SESSION['worker_id'];
$action = $_GET['action'] ?? '';
$pdo = getDB();
$health = new AccountHealthHelper($pdo);

switch ($action) {
    case 'get-status':
        $status = $health->getAccountHealth($workerId);
        echo json_encode(['success' => true, 'health' => $status]);
        break;
        
    case 'update':
        $scores = $health->updateHealthScores($workerId);
        echo json_encode(['success' => true, 'scores' => $scores]);
        break;
        
    case 'check-risk':
        $risk = $health->checkDeactivationRisk($workerId);
        echo json_encode(['success' => true, 'risk' => $risk]);
        break;
        
    default:
        echo json_encode(['success' => false, 'error' => 'Ação inválida']);
}