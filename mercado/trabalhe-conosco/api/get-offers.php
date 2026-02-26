<?php
require_once dirname(__DIR__, 2) . '/config/database.php';
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['worker_id'])) {
    exit(json_encode(['success' => false]));
}

$worker_id = intval($_SESSION['worker_id']);
$pdo = getPDO();

// Buscar worker
$stmt = $pdo->prepare("SELECT * FROM om_workers WHERE worker_id = ?");
$stmt->execute([$worker_id]);
$worker = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$worker || !$worker['is_online']) {
    exit(json_encode(['success' => true, 'offers' => []]));
}

// Buscar ofertas disponíveis
$stmt = $pdo->prepare("SELECT * FROM om_worker_offers
        WHERE status = 'offered'
        AND offered_to_worker_id = ?
        AND expires_at > NOW()
        ORDER BY priority DESC, created_at ASC
        LIMIT 5");
$stmt->execute([$worker_id]);

$offers = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    // Filtrar por tipo
    if ($worker['is_shopper'] && $worker['is_delivery']) {
        // Full service - aceita tudo
    } elseif ($worker['is_shopper'] && $row['offer_type'] !== 'shopper') {
        continue;
    } elseif ($worker['is_delivery'] && $row['offer_type'] !== 'delivery') {
        continue;
    }
    $offers[] = $row;
}

echo json_encode(['success' => true, 'offers' => $offers]);
