<?php
require_once dirname(__DIR__) . '/config/database.php';
/**
 * API para rastrear cliques nos banners
 * /mercado/api/banner-click.php?id=X
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$banner_id = (int)($_GET['id'] ?? 0);

if ($banner_id <= 0) {
    echo json_encode(['success' => false]);
    exit;
}

try {
    $pdo = getPDO();
    
    $stmt = $pdo->prepare("UPDATE om_market_banners SET clicks = clicks + 1 WHERE banner_id = ?");
    $stmt->execute([$banner_id]);
    
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false]);
}
