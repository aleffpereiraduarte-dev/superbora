<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * API: Hand-off Shopper → Motorista
 * POST /api/transporte/mercado/handoff.php
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Body: { "order_id": 123, "motorista_id": 1, "codigo": "ABC123" }
 *
 * Confirma que o motorista recebeu os produtos do shopper
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

require_once dirname(__DIR__, 3) . '/database.php';

try {
    $pdo = getDB();

    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $order_id = intval($input['order_id'] ?? 0);
    $motorista_id = intval($input['motorista_id'] ?? 0);
    $codigo = strtoupper(trim($input['codigo'] ?? ''));

    if (!$order_id || !$motorista_id || !$codigo) {
        echo json_encode(['success' => false, 'error' => 'order_id, motorista_id e codigo obrigatórios']);
        exit;
    }

    // Verificar pedido
    $stmt = $pdo->prepare("
        SELECT * FROM om_market_orders
        WHERE order_id = ?
          AND delivery_driver_id = ?
          AND status = 'delivering'
    ");
    $stmt->execute([$order_id, $motorista_id]);
    $pedido = $stmt->fetch();

    if (!$pedido) {
        echo json_encode(['success' => false, 'error' => 'Pedido não encontrado ou não atribuído a você']);
        exit;
    }

    // Verificar código de hand-off
    if ($pedido['handoff_code'] !== $codigo) {
        echo json_encode([
            'success' => false,
            'error' => 'Código de hand-off incorreto',
            'dica' => 'Peça o código ao Shopper: ' . $pedido['shopper_name']
        ]);
        exit;
    }

    // Confirmar hand-off
    $stmt = $pdo->prepare("
        UPDATE om_market_orders SET
            handoff_scanned_at = NOW(),
            handoff_by_worker_id = ?,
            handoff_to_worker_id = ?,
            delivery_picked_at = NOW(),
            picked_up_at = NOW(),
            status = 'in_transit',
            updated_at = NOW()
        WHERE order_id = ?
    ");
    $stmt->execute([$pedido['shopper_id'], $motorista_id, $order_id]);

    // Registrar evento
    $stmt = $pdo->prepare("
        INSERT INTO om_market_order_events (order_id, event_type, description, worker_id, created_at)
        VALUES (?, 'handoff_completed', 'Motorista recebeu produtos do Shopper', ?, NOW())
    ");
    @$stmt->execute([$order_id, $motorista_id]);

    echo json_encode([
        'success' => true,
        'message' => 'Hand-off confirmado! Agora entregue ao cliente.',
        'pedido' => [
            'id' => $order_id,
            'status' => 'in_transit',
            'etapa_atual' => 3,

            // Próxima etapa
            'proxima_etapa' => [
                'titulo' => 'Entregar ao Cliente',
                'cliente' => $pedido['customer_name'],
                'telefone' => $pedido['customer_phone'],
                'endereco' => $pedido['delivery_address'] ?: $pedido['customer_address'],
                'numero' => $pedido['delivery_number'],
                'complemento' => $pedido['delivery_complement'],
                'lat' => floatval($pedido['delivery_lat'] ?: $pedido['customer_lat']),
                'lng' => floatval($pedido['delivery_lng'] ?: $pedido['customer_lng']),
                'codigo_entrega' => $pedido['delivery_code'],
                'instrucao' => 'Peça o código de entrega ao cliente para confirmar'
            ],

            'valor_motorista' => floatval($pedido['delivery_earning'] ?: $pedido['delivery_fee'] * 0.8)
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("[transporte/mercado/handoff] Erro: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro interno do servidor']);
}
