<?php
/**
 * 🔄 Voltar pedido para "delivering"
 */

session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'manager'])) {
    http_response_code(403);
    die('Acesso negado');
}

$oc_root = dirname(__DIR__);
require_once($oc_root . '/config.php');

$pdo = new PDO(
    "mysql:host=" . DB_HOSTNAME . ";dbname=" . DB_DATABASE . ";charset=utf8mb4",
    DB_USERNAME, DB_PASSWORD,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$order_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$order_id || $order_id <= 0) {
    http_response_code(400);
    die('ID do pedido inválido');
}

// Voltar para delivering
$stmt = $pdo->prepare("
    UPDATE om_market_orders SET 
        status = 'delivering',
        delivered_at = NULL,
        chat_expired = 0,
        chat_expires_at = NULL,
        shipping_lat = ?,
        shipping_lng = ?
    WHERE order_id = ?
");

if (!$stmt->execute([$order_id]) || $stmt->rowCount() === 0) {
    http_response_code(404);
    die('Pedido não encontrado ou erro na atualização');
}

// Atualizar GPS do delivery
$delivery_stmt = $pdo->prepare("SELECT delivery_id FROM om_market_orders WHERE order_id = ?");
$delivery_stmt->execute([$order_id]);
$delivery_id = $delivery_stmt->fetchColumn();

if ($delivery_id) {
    $pdo->prepare("UPDATE om_market_deliveries SET lat = -23.5415, lng = -46.6333, last_location_at = NOW() WHERE delivery_id = ?") ->execute([$delivery_id]);
}

echo "✅ Pedido #" . htmlspecialchars($order_id, ENT_QUOTES, 'UTF-8') . " voltou para 'delivering'<br><br>";
echo "<a href='tracking.php?id=" . urlencode($order_id) . "'>🗺️ Ver Tracking com Mapa</a>";
