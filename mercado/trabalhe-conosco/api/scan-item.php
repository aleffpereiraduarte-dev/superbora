<?php
/**
 * API - Scan Item
 */
require_once '../config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['worker_id'])) {
    jsonResponse(['success' => false, 'error' => 'Não autenticado'], 401);
}

$data = json_decode(file_get_contents('php://input'), true);
$order_id = intval($data['order_id'] ?? 0);
$item_id = intval($data['item_id'] ?? 0);
$shopper_id = $_SESSION['shopper_id'] ?? null;

if (!$order_id || !$item_id) {
    jsonResponse(['success' => false, 'error' => 'Dados inválidos']);
}

$pdo = getPDO();

// Verificar pedido
$stmt = $pdo->prepare("SELECT order_id FROM om_market_orders WHERE order_id = ? AND shopper_id = ?");
$stmt->execute([$order_id, $shopper_id]);
if (!$stmt->fetch()) {
    jsonResponse(['success' => false, 'error' => 'Pedido não encontrado']);
}

// Toggle scanned
$stmt = $pdo->prepare("SELECT scanned FROM om_market_order_items WHERE item_id = ? AND order_id = ?");
$stmt->execute([$item_id, $order_id]);
$item = $stmt->fetch();

if (!$item) {
    jsonResponse(['success' => false, 'error' => 'Item não encontrado']);
}

$new_status = $item['scanned'] ? 0 : 1;

$pdo->prepare("UPDATE om_market_order_items SET scanned = ?, scanned_at = NOW() WHERE item_id = ?")
    ->execute([$new_status, $item_id]);

// Contar progresso
$stmt = $pdo->prepare("SELECT COUNT(*) as total, SUM(scanned) as scanned FROM om_market_order_items WHERE order_id = ?");
$stmt->execute([$order_id]);
$progress = $stmt->fetch();

jsonResponse([
    'success' => true,
    'scanned' => (int)$progress['scanned'],
    'total' => (int)$progress['total']
]);
