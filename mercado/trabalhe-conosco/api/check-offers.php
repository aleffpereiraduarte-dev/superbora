<?php
/**
 * API - Check Offers (polling)
 */
require_once '../config.php';
header('Content-Type: application/json');

$shopper_id = intval($_GET['shopper_id'] ?? $_SESSION['shopper_id'] ?? 0);
if (!$shopper_id) {
    jsonResponse(['success' => false]);
}

$pdo = getPDO();

$stmt = $pdo->prepare("SELECT partner_id, is_online FROM om_market_shoppers WHERE shopper_id = ?");
$stmt->execute([$shopper_id]);
$shopper = $stmt->fetch();

if (!$shopper || !$shopper['is_online']) {
    jsonResponse(['success' => true, 'reload' => false, 'count' => 0]);
}

$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM om_shopper_offers so
    JOIN om_market_orders o ON so.order_id = o.order_id
    WHERE so.status = 'pending' AND so.expires_at > NOW()
      AND o.partner_id = ? AND o.shopper_id IS NULL
");
$stmt->execute([$shopper['partner_id']]);
$count = $stmt->fetchColumn();

$last = $_SESSION['offer_count'] ?? 0;
$_SESSION['offer_count'] = $count;

// Atualizar last_seen
$pdo->prepare("UPDATE om_market_shoppers SET last_seen = NOW() WHERE shopper_id = ?")->execute([$shopper_id]);

jsonResponse([
    'success' => true,
    'reload' => $count > 0 && $count != $last,
    'count' => $count
]);
