<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * API: Aceitar Corrida do Mercado
 * POST /api/transporte/mercado/aceitar.php
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Body: { "order_id": 123, "motorista_id": 1 }
 *
 * Motorista aceita entrega do Mercado
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

    if (!$order_id || !$motorista_id) {
        echo json_encode(['success' => false, 'error' => 'order_id e motorista_id obrigatórios']);
        exit;
    }

    // Verificar motorista
    $stmt = $pdo->prepare("SELECT * FROM boraum_motoristas WHERE id = ? AND status = 'aprovado'");
    $stmt->execute([$motorista_id]);
    $motorista = $stmt->fetch();

    if (!$motorista) {
        echo json_encode(['success' => false, 'error' => 'Motorista não aprovado']);
        exit;
    }

    // Verificar pedido disponível
    $stmt = $pdo->prepare("
        SELECT * FROM om_market_orders
        WHERE order_id = ?
          AND status IN ('purchased', 'ready_for_delivery', 'shopper_completed')
          AND delivery_driver_id IS NULL
    ");
    $stmt->execute([$order_id]);
    $pedido = $stmt->fetch();

    if (!$pedido) {
        echo json_encode(['success' => false, 'error' => 'Pedido não disponível ou já aceito']);
        exit;
    }

    // Gerar código de hand-off se não existir
    $handoff_code = $pedido['handoff_code'];
    if (empty($handoff_code)) {
        $handoff_code = strtoupper(substr(md5($order_id . time()), 0, 6));
    }

    // Gerar código de entrega para o cliente
    $delivery_code = strtoupper(substr(md5($order_id . 'delivery' . time()), 0, 4));

    // Aceitar pedido
    $stmt = $pdo->prepare("
        UPDATE om_market_orders SET
            delivery_driver_id = ?,
            driver_id = ?,
            status = 'delivering',
            delivery_accepted_at = NOW(),
            delivery_dispatched_at = NOW(),
            handoff_code = ?,
            delivery_code = ?,
            updated_at = NOW()
        WHERE order_id = ? AND delivery_driver_id IS NULL
    ");
    $stmt->execute([$motorista_id, $motorista_id, $handoff_code, $delivery_code, $order_id]);

    if ($stmt->rowCount() == 0) {
        echo json_encode(['success' => false, 'error' => 'Pedido já foi aceito por outro motorista']);
        exit;
    }

    // Buscar dados atualizados
    $stmt = $pdo->prepare("SELECT * FROM om_market_orders WHERE order_id = ?");
    $stmt->execute([$order_id]);
    $pedido = $stmt->fetch();

    echo json_encode([
        'success' => true,
        'message' => 'Entrega aceita! Vá até o Mercado buscar os produtos.',
        'pedido' => [
            'id' => $pedido['order_id'],
            'tipo' => 'MERCADO',
            'status' => 'delivering',

            // Etapa 1: Buscar no Mercado
            'etapa_atual' => 1,
            'etapas' => [
                [
                    'numero' => 1,
                    'titulo' => 'Ir ao Mercado',
                    'descricao' => 'Vá até o mercado para buscar os produtos',
                    'local' => $pedido['partner_name'] ?: 'Mercado',
                    'endereco' => $pedido['shipping_address'],
                    'concluido' => false
                ],
                [
                    'numero' => 2,
                    'titulo' => 'Hand-off com Shopper',
                    'descricao' => 'Encontre o Shopper e confirme o código',
                    'shopper' => $pedido['shopper_name'],
                    'telefone' => $pedido['shopper_phone'],
                    'codigo_handoff' => $handoff_code,
                    'concluido' => false
                ],
                [
                    'numero' => 3,
                    'titulo' => 'Entregar ao Cliente',
                    'descricao' => 'Leve os produtos até o cliente',
                    'cliente' => $pedido['customer_name'],
                    'endereco' => $pedido['delivery_address'] ?: $pedido['customer_address'],
                    'codigo_entrega' => $delivery_code,
                    'concluido' => false
                ]
            ],

            // Shopper
            'shopper' => [
                'nome' => $pedido['shopper_name'],
                'telefone' => $pedido['shopper_phone']
            ],

            // Cliente
            'cliente' => [
                'nome' => $pedido['customer_name'],
                'telefone' => $pedido['customer_phone'],
                'endereco' => $pedido['delivery_address'] ?: $pedido['customer_address']
            ],

            // Códigos importantes
            'codigos' => [
                'handoff' => $handoff_code,
                'entrega' => $delivery_code
            ],

            // Valores
            'valor_entrega' => floatval($pedido['delivery_fee']),
            'valor_motorista' => floatval($pedido['delivery_earning'] ?: $pedido['delivery_fee'] * 0.8)
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("[transporte/mercado/aceitar] Erro: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro interno do servidor']);
}
