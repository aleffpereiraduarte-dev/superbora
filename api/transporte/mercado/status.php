<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * API: Status do Pedido Mercado (Visão Motorista)
 * GET /api/transporte/mercado/status.php?order_id=X&motorista_id=Y
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Retorna status detalhado do pedido do Mercado para o motorista
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

require_once dirname(__DIR__, 3) . '/database.php';

try {
    $pdo = getDB();

    $order_id = intval($_GET['order_id'] ?? 0);
    $motorista_id = intval($_GET['motorista_id'] ?? 0);

    if (!$order_id) {
        echo json_encode(['success' => false, 'error' => 'order_id obrigatório']);
        exit;
    }

    // Buscar pedido
    $stmt = $pdo->prepare("SELECT * FROM om_market_orders WHERE order_id = ?");
    $stmt->execute([$order_id]);
    $pedido = $stmt->fetch();

    if (!$pedido) {
        echo json_encode(['success' => false, 'error' => 'Pedido não encontrado']);
        exit;
    }

    // Verificar se é o motorista atribuído (se informado)
    if ($motorista_id && $pedido['delivery_driver_id'] && $pedido['delivery_driver_id'] != $motorista_id) {
        echo json_encode(['success' => false, 'error' => 'Pedido não atribuído a você']);
        exit;
    }

    // Determinar etapa atual
    $etapa_atual = 1;
    $etapas = [];

    // Etapa 1: Ir ao Mercado
    $etapa1_concluida = !empty($pedido['handoff_scanned_at']);
    $etapas[] = [
        'numero' => 1,
        'titulo' => 'Ir ao Mercado',
        'descricao' => 'Vá até o mercado buscar os produtos',
        'local' => [
            'nome' => $pedido['partner_name'] ?: 'Mercado',
            'endereco' => $pedido['shipping_address'],
            'lat' => floatval($pedido['shipping_lat']),
            'lng' => floatval($pedido['shipping_lng'])
        ],
        'concluido' => $etapa1_concluida
    ];

    // Etapa 2: Hand-off com Shopper
    $etapa2_concluida = !empty($pedido['handoff_scanned_at']);
    $etapas[] = [
        'numero' => 2,
        'titulo' => 'Hand-off com Shopper',
        'descricao' => 'Confirme o código com o Shopper',
        'shopper' => [
            'nome' => $pedido['shopper_name'],
            'telefone' => $pedido['shopper_phone']
        ],
        'codigo' => $pedido['handoff_code'],
        'concluido' => $etapa2_concluida,
        'concluido_em' => $pedido['handoff_scanned_at']
    ];

    // Etapa 3: Entregar ao Cliente
    $etapa3_concluida = $pedido['status'] === 'delivered';
    $etapas[] = [
        'numero' => 3,
        'titulo' => 'Entregar ao Cliente',
        'descricao' => 'Leve os produtos ao cliente',
        'cliente' => [
            'nome' => $pedido['customer_name'],
            'telefone' => $pedido['customer_phone'],
            'endereco' => $pedido['delivery_address'] ?: $pedido['customer_address'],
            'numero' => $pedido['delivery_number'],
            'complemento' => $pedido['delivery_complement'],
            'lat' => floatval($pedido['delivery_lat'] ?: $pedido['customer_lat']),
            'lng' => floatval($pedido['delivery_lng'] ?: $pedido['customer_lng'])
        ],
        'codigo_entrega' => $pedido['delivery_code'],
        'concluido' => $etapa3_concluida,
        'concluido_em' => $pedido['delivered_at']
    ];

    // Determinar etapa atual
    if ($etapa3_concluida) {
        $etapa_atual = 3;
    } elseif ($etapa2_concluida) {
        $etapa_atual = 3;
    } elseif ($pedido['delivery_driver_id']) {
        $etapa_atual = 2;
    }

    // Status legível
    $status_map = [
        'purchased' => 'Aguardando motorista',
        'ready_for_delivery' => 'Pronto para entrega',
        'shopper_completed' => 'Pronto para entrega',
        'delivering' => 'Motorista a caminho do mercado',
        'in_transit' => 'Motorista a caminho do cliente',
        'delivered' => 'Entregue'
    ];

    echo json_encode([
        'success' => true,
        'tipo' => 'MERCADO',
        'pedido' => [
            'id' => $pedido['order_id'],
            'numero' => $pedido['order_number'],
            'status' => $pedido['status'],
            'status_texto' => $status_map[$pedido['status']] ?? $pedido['status'],

            'etapa_atual' => $etapa_atual,
            'etapas' => $etapas,

            'mercado' => $pedido['partner_name'] ?: 'Mercado OneMundo',
            'itens' => intval($pedido['items_count']),

            'valores' => [
                'total_pedido' => floatval($pedido['total']),
                'taxa_entrega' => floatval($pedido['delivery_fee']),
                'seu_ganho' => floatval($pedido['delivery_earning'] ?: $pedido['delivery_fee'] * 0.8)
            ],

            'codigos' => [
                'handoff' => $pedido['handoff_code'],
                'entrega' => $pedido['delivery_code']
            ],

            'timestamps' => [
                'pedido_criado' => $pedido['created_at'],
                'aceito_em' => $pedido['delivery_accepted_at'],
                'handoff_em' => $pedido['handoff_scanned_at'],
                'entregue_em' => $pedido['delivered_at']
            ]
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("[transporte/mercado/status] Erro: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro interno do servidor']);
}
