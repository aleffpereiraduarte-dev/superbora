<?php
/**
 * API: Banner Status - Retorna status do pedido ativo para o banner
 * GET: /api/banner-status.php?customer_id=X
 */
require_once __DIR__ . '/../config/database.php';

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }

try {
    $pdo = getPDO();
} catch (PDOException $e) {
    echo json_encode(["success" => false, "error" => "DB Error"]);
    exit;
}

$customer_id = (int)($_GET["customer_id"] ?? 0);

if (!$customer_id) {
    echo json_encode(["success" => false, "error" => "customer_id obrigatório"]);
    exit;
}

// Buscar pedido ativo
$stmt = $pdo->prepare("
    SELECT 
        o.order_id, o.status, o.delivery_code, o.total, o.customer_name,
        o.stop_order, o.estimated_delivery_time, o.route_id,
        o.shopper_id, o.delivery_id, o.chat_enabled, o.chat_expires_at,
        s.name as shopper_name, s.phone as shopper_phone,
        d.name as delivery_name, d.phone as delivery_phone, d.vehicle_type,
        r.total_stops, r.completed_stops, r.status as route_status,
        (SELECT COUNT(*) FROM om_market_chat WHERE order_id = o.order_id AND sender_type != 'customer' AND is_read = 0) as unread_messages
    FROM om_market_orders o
    LEFT JOIN om_market_shoppers s ON o.shopper_id = s.shopper_id
    LEFT JOIN om_market_deliveries d ON o.delivery_id = d.delivery_id
    LEFT JOIN om_delivery_routes r ON o.route_id = r.route_id
    WHERE o.customer_id = ?
    AND (
        o.status NOT IN ('cancelled', 'delivered')
        OR (o.status = 'delivered' AND o.chat_expires_at > NOW())
    )
    ORDER BY o.order_id DESC
    LIMIT 1
");
$stmt->execute([$customer_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    echo json_encode(["success" => true, "has_active" => false]);
    exit;
}

// Calcular posição na fila e ETA
$position = null;
$eta_minutes = null;

if ($order['status'] === 'delivering' && $order['stop_order']) {
    $position = max(1, $order['stop_order'] - ($order['completed_stops'] ?? 0));
    $eta_minutes = $position * 8;
}

// Tempo restante do chat
$chat_remaining = null;
if ($order['status'] === 'delivered' && $order['chat_expires_at']) {
    $expires = strtotime($order['chat_expires_at']);
    $chat_remaining = max(0, ceil(($expires - time()) / 60));
}

// Status config
$status_config = [
    'pending' => ['icon' => '⏳', 'label' => 'Aguardando', 'color' => '#f59e0b', 'progress' => 10],
    'confirmed' => ['icon' => '✅', 'label' => 'Confirmado', 'color' => '#3b82f6', 'progress' => 25],
    'shopping' => ['icon' => '🛒', 'label' => 'Comprando', 'color' => '#8b5cf6', 'progress' => 50],
    'preparing' => ['icon' => '📦', 'label' => 'Preparando', 'color' => '#8b5cf6', 'progress' => 50],
    'ready' => ['icon' => '📦', 'label' => 'Pronto', 'color' => '#06b6d4', 'progress' => 70],
    'delivering' => ['icon' => '🚚', 'label' => 'A caminho', 'color' => '#10b981', 'progress' => 85],
    'delivered' => ['icon' => '🎉', 'label' => 'Entregue', 'color' => '#22c55e', 'progress' => 100],
];

$config = $status_config[$order['status']] ?? $status_config['pending'];

echo json_encode([
    "success" => true,
    "has_active" => true,
    "order" => [
        "order_id" => (int)$order['order_id'],
        "status" => $order['status'],
        "status_label" => $config['label'],
        "status_icon" => $config['icon'],
        "status_color" => $config['color'],
        "progress" => $config['progress'],
        "total" => (float)$order['total'],
        "delivery_code" => $order['delivery_code'],
        "show_code" => in_array($order['status'], ['ready', 'delivering']),
    ],
    "shopper" => $order['shopper_id'] ? [
        "name" => $order['shopper_name'],
        "phone" => $order['shopper_phone']
    ] : null,
    "delivery" => $order['delivery_id'] ? [
        "name" => $order['delivery_name'],
        "phone" => $order['delivery_phone'],
        "vehicle" => $order['vehicle_type'],
        "position" => $position,
        "eta_minutes" => $eta_minutes
    ] : null,
    "chat" => [
        "enabled" => (bool)$order['chat_enabled'],
        "unread" => (int)$order['unread_messages'],
        "remaining_minutes" => $chat_remaining
    ]
]);
