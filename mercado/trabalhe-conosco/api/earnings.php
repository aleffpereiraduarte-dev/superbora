<?php
/**
 * API: Earnings (Peak Pay, Tips, Fast Pay, Daily Goals)
 */
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/EarningsHelper.php';
header('Content-Type: application/json');

if (!isset($_SESSION['worker_id'])) {
    echo json_encode(['success' => false, 'error' => 'Não autorizado']);
    exit;
}

$workerId = $_SESSION['worker_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$pdo = getDB();
$earnings = new EarningsHelper($pdo);

switch ($action) {
    case 'get-peak-pay':
        $regionId = $_GET['region_id'] ?? null;
        $peakPay = $earnings->getActivePeakPay($regionId);
        echo json_encode(['success' => true, 'peak_pay' => $peakPay]);
        break;
        
    case 'get-daily-goals':
        $goals = $earnings->getWorkerDailyProgress($workerId);
        echo json_encode(['success' => true, 'goals' => $goals]);
        break;
        
    case 'claim-daily-goal':
        $goalId = $_POST['goal_id'] ?? 0;
        $bonus = $earnings->payDailyGoalBonus($workerId, $goalId);
        echo json_encode(['success' => (bool)$bonus, 'bonus' => $bonus]);
        break;
        
    case 'can-fast-pay':
        $result = $earnings->canFastPay($workerId);
        echo json_encode(['success' => true, 'data' => $result]);
        break;
        
    case 'request-fast-pay':
        $amount = $_POST['amount'] ?? null;
        $pixKey = $_POST['pix_key'] ?? null;
        $result = $earnings->requestFastPay($workerId, $amount, $pixKey);
        echo json_encode($result);
        break;
        
    case 'get-history':
        $days = $_GET['days'] ?? 30;
        $history = $earnings->getEarningsHistory($workerId, $days);
        echo json_encode(['success' => true, 'history' => $history]);
        break;
        
    case 'get-summary':
        $period = $_GET['period'] ?? 'week';
        $summary = $earnings->getEarningsSummary($workerId, $period);
        echo json_encode(['success' => true, 'summary' => $summary]);
        break;
        
    default:
        echo json_encode(['success' => false, 'error' => 'Ação inválida']);
}