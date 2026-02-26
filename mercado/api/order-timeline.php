<?php
/**
 * API: Order Timeline - Histórico completo do pedido
 * GET: /api/order-timeline.php?order_id=X
 */
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }

require_once __DIR__ . '/../config/database.php';

try {
    $pdo = getPDO();
} catch (PDOException $e) {
    echo json_encode(["success" => false]); exit;
}

$order_id = (int)($_GET["order_id"] ?? 0);

if (!$order_id) {
    echo json_encode(["success" => false, "error" => "order_id required"]); exit;
}

// Buscar pedido
$stmt = $pdo->prepare("SELECT * FROM om_market_orders WHERE order_id = ?");
$stmt->execute([$order_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    echo json_encode(["success" => false, "error" => "Pedido não encontrado"]); exit;
}

// Buscar histórico
$history = [];
try {
    $stmt = $pdo->prepare("
        SELECT status, comment, created_at 
        FROM om_market_order_history 
        WHERE order_id = ? 
        ORDER BY created_at ASC
    ");
    $stmt->execute([$order_id]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Timeline padrão
$timeline = [
    ['status' => 'created', 'label' => 'Pedido criado', 'time' => $order['created_at'] ?? $order['date_added'] ?? null, 'done' => true],
];

$status_labels = [
    'pending' => 'Aguardando confirmação',
    'confirmed' => 'Pedido confirmado',
    'shopping' => 'Shopper comprando',
    'preparing' => 'Preparando pedido',
    'ready' => 'Pronto para entrega',
    'delivering' => 'Saiu para entrega',
    'delivered' => 'Entregue com sucesso'
];

foreach ($history as $h) {
    $timeline[] = [
        'status' => $h['status'],
        'label' => $status_labels[$h['status']] ?? ucfirst($h['status']),
        'comment' => $h['comment'],
        'time' => $h['created_at'],
        'done' => true
    ];
}

// Adicionar steps futuros
$current_status = $order['status'];
$status_order = ['pending', 'confirmed', 'shopping', 'ready', 'delivering', 'delivered'];
$current_idx = array_search($current_status, $status_order);

if ($current_idx !== false) {
    foreach ($status_order as $idx => $status) {
        if ($idx > $current_idx) {
            $timeline[] = [
                'status' => $status,
                'label' => $status_labels[$status] ?? ucfirst($status),
                'time' => null,
                'done' => false
            ];
        }
    }
}

echo json_encode([
    "success" => true,
    "order_id" => (int)$order_id,
    "current_status" => $current_status,
    "timeline" => $timeline
]);
