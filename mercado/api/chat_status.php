<?php
/**
 * ╔══════════════════════════════════════════════════════════════════════════════╗
 * ║  API VERIFICAR CHAT - STATUS E TEMPO RESTANTE                                ║
 * ╚══════════════════════════════════════════════════════════════════════════════╝
 * 
 * GET /mercado/api/chat_status.php?order_id=123
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once dirname(__DIR__) . '/config/database.php';

try {
    $pdo = getPDO();
} catch (PDOException $e) {
    echo json_encode(array('success' => false, 'error' => 'Erro de conexão'));
    exit;
}

$order_id = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;

if (!$order_id) {
    echo json_encode(array('success' => false, 'error' => 'order_id é obrigatório'));
    exit;
}

// Buscar pedido
$stmt = $pdo->prepare("
    SELECT order_id, order_number, status, chat_enabled, chat_expired,
           chat_expires_at, delivered_at, shopper_name, delivery_name
    FROM om_market_orders
    WHERE order_id = ?
");
$stmt->execute(array($order_id));
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    echo json_encode(array('success' => false, 'error' => 'Pedido não encontrado'));
    exit;
}

// Calcular tempo restante
$time_remaining = null;
$time_remaining_text = null;
$is_active = false;

if ($order['chat_enabled'] && !$order['chat_expired']) {
    $is_active = true;
    
    if ($order['chat_expires_at']) {
        $expires = strtotime($order['chat_expires_at']);
        $now = time();
        $remaining_seconds = $expires - $now;
        
        if ($remaining_seconds > 0) {
            $time_remaining = $remaining_seconds;
            $minutes = floor($remaining_seconds / 60);
            $seconds = $remaining_seconds % 60;
            
            if ($minutes > 0) {
                $time_remaining_text = $minutes . ' min';
            } else {
                $time_remaining_text = $seconds . ' seg';
            }
        } else {
            // Expirou mas cron ainda não rodou
            $is_active = false;
            $time_remaining_text = 'Expirado';
        }
    }
}

// Contar mensagens não lidas
$stmt = $pdo->prepare("
    SELECT COUNT(*) as unread FROM om_market_chat
    WHERE order_id = ? AND is_read = 0
");
$stmt->execute(array($order_id));
$unread = $stmt->fetch(PDO::FETCH_ASSOC);

echo json_encode(array(
    'success' => true,
    'chat' => array(
        'is_active' => $is_active,
        'is_expired' => (bool)$order['chat_expired'],
        'expires_at' => $order['chat_expires_at'],
        'time_remaining' => $time_remaining,
        'time_remaining_text' => $time_remaining_text,
        'unread_count' => (int)$unread['unread']
    ),
    'order' => array(
        'order_id' => $order['order_id'],
        'order_number' => $order['order_number'],
        'status' => $order['status'],
        'shopper_name' => $order['shopper_name'],
        'delivery_name' => $order['delivery_name']
    )
));
