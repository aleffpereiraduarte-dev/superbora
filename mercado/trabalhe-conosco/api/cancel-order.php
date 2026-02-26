<?php
/**
 * API: Cancelamento de Pedido
 * POST /api/cancel-order.php
 */
require_once 'db.php';

$workerId = requireAuth();
$db = getDB();

if (!$db) { jsonError('Erro de conexão', 500); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonError('Método não permitido', 405); }

$input = getJsonInput();
$orderId = $input['order_id'] ?? null;
$reason = $input['reason'] ?? '';
$reasonCode = $input['reason_code'] ?? 'other';

if (!$orderId) { jsonError('ID do pedido é obrigatório'); }

$validReasons = [
    'customer_unreachable' => 'Cliente não atende',
    'wrong_address' => 'Endereço incorreto',
    'customer_absent' => 'Cliente ausente',
    'store_closed' => 'Loja fechada',
    'out_of_stock' => 'Produtos indisponíveis',
    'vehicle_problem' => 'Problema com veículo',
    'personal_emergency' => 'Emergência pessoal',
    'unsafe_location' => 'Local inseguro',
    'other' => 'Outro motivo'
];

if (!isset($validReasons[$reasonCode])) { jsonError('Motivo inválido'); }

try {
    $db->beginTransaction();
    
    // Verificar pedido
    $stmt = $db->prepare("SELECT id, status, customer_id FROM " . table('orders') . " WHERE id = ? AND worker_id = ?");
    $stmt->execute([$orderId, $workerId]);
    $order = $stmt->fetch();
    
    if (!$order) {
        $db->rollBack();
        jsonError('Pedido não encontrado', 404);
    }
    
    if (in_array($order['status'], ['completed', 'cancelled'])) {
        $db->rollBack();
        jsonError('Este pedido não pode ser cancelado');
    }
    
    // Cancelar pedido
    $stmt = $db->prepare("
        UPDATE " . table('orders') . "
        SET status = 'cancelled', cancelled_at = NOW(), cancelled_by = 'worker', cancel_reason = ?
        WHERE id = ?
    ");
    $stmt->execute([$reasonCode . ': ' . $reason, $orderId]);
    
    // Timeline
    $stmt = $db->prepare("
        INSERT INTO " . table('order_timeline') . " (order_id, status, notes, created_at)
        VALUES (?, 'cancelled', ?, NOW())
    ");
    $stmt->execute([$orderId, $validReasons[$reasonCode] . ($reason ? ": $reason" : '')]);
    
    // Registrar cancelamento do trabalhador
    $stmt = $db->prepare("
        INSERT INTO " . table('worker_cancellations') . " (worker_id, order_id, reason_code, reason_text, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$workerId, $orderId, $reasonCode, $reason]);
    
    // Verificar taxa de cancelamento
    $stmt = $db->prepare("
        SELECT 
            (SELECT COUNT(*) FROM " . table('worker_cancellations') . " WHERE worker_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)) as cancellations,
            (SELECT COUNT(*) FROM " . table('orders') . " WHERE worker_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)) as total
    ");
    $stmt->execute([$workerId, $workerId]);
    $stats = $stmt->fetch();
    
    $cancelRate = $stats['total'] > 0 ? ($stats['cancellations'] / $stats['total']) * 100 : 0;
    $warning = $cancelRate > 10;
    
    // Notificar cliente
    // Em produção: push notification, SMS, etc
    
    $db->commit();
    
    $response = ['cancelled' => true];
    if ($warning) {
        $response['warning'] = 'Atenção: sua taxa de cancelamento está em ' . round($cancelRate, 1) . '%. Taxas acima de 15% podem resultar em penalizações.';
    }
    
    jsonSuccess($response, 'Pedido cancelado');
    
} catch (Exception $e) {
    $db->rollBack();
    error_log("Cancel order error: " . $e->getMessage());
    jsonError('Erro ao cancelar pedido', 500);
}
