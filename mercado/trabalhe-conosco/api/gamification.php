<?php
/**
 * API: Gamificação (Tiers, Challenges, Points, Rewards)
 */
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/GamificationHelper.php';
header('Content-Type: application/json');

if (!isset($_SESSION['worker_id'])) {
    echo json_encode(['success' => false, 'error' => 'Não autorizado']);
    exit;
}

$workerId = $_SESSION['worker_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$pdo = getDB();
$gamification = new GamificationHelper($pdo);

switch ($action) {
    case 'get-tier':
        $tier = $gamification->getWorkerTier($workerId);
        $benefits = $gamification->getTierBenefits($tier['tier_id']);
        echo json_encode(['success' => true, 'tier' => $tier, 'benefits' => $benefits]);
        break;
        
    case 'get-challenges':
        $challenges = $gamification->getAvailableChallenges($workerId);
        echo json_encode(['success' => true, 'challenges' => $challenges]);
        break;
        
    case 'join-challenge':
        $challengeId = $_POST['challenge_id'] ?? 0;
        $result = $gamification->joinChallenge($workerId, $challengeId);
        echo json_encode(['success' => $result]);
        break;
        
    case 'claim-reward':
        $challengeId = $_POST['challenge_id'] ?? 0;
        $result = $gamification->claimChallengeReward($workerId, $challengeId);
        echo json_encode(['success' => (bool)$result, 'reward' => $result]);
        break;
        
    case 'get-points':
        $balance = $gamification->getPointsBalance($workerId);
        $history = $gamification->getPointsHistory($workerId, 20);
        echo json_encode(['success' => true, 'balance' => $balance, 'history' => $history]);
        break;
        
    case 'get-rewards-store':
        $rewards = $gamification->getRewardsStore();
        $balance = $gamification->getPointsBalance($workerId);
        echo json_encode(['success' => true, 'rewards' => $rewards, 'balance' => $balance]);
        break;
        
    case 'redeem-reward':
        $rewardId = $_POST['reward_id'] ?? 0;
        $result = $gamification->redeemReward($workerId, $rewardId);
        echo json_encode($result);
        break;
        
    case 'get-referral-code':
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT referral_code FROM om_market_workers WHERE worker_id = ?");
        $stmt->execute([$workerId]);
        $code = $stmt->fetchColumn();
        
        if (!$code) {
            $code = $gamification->generateReferralCode($workerId);
        }
        echo json_encode(['success' => true, 'code' => $code]);
        break;
        
    default:
        echo json_encode(['success' => false, 'error' => 'Ação inválida']);
}