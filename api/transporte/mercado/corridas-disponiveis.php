<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * API: Corridas do Mercado Disponíveis para Motorista
 * GET /api/transporte/mercado/corridas-disponiveis.php?motorista_id=X
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Retorna pedidos do Mercado prontos para entrega (shopper finalizou compra)
 * Mostra informações de hand-off e detalhes do pedido
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

require_once dirname(__DIR__, 3) . '/database.php';

try {
    $pdo = getDB();

    $motorista_id = intval($_GET['motorista_id'] ?? 0);
    $lat = floatval($_GET['lat'] ?? 0);
    $lng = floatval($_GET['lng'] ?? 0);

    if (!$motorista_id) {
        echo json_encode(['success' => false, 'error' => 'motorista_id obrigatório']);
        exit;
    }

    // Verificar motorista aprovado
    $stmt = $pdo->prepare("SELECT * FROM boraum_motoristas WHERE id = ? AND status = 'aprovado'");
    $stmt->execute([$motorista_id]);
    $motorista = $stmt->fetch();

    if (!$motorista) {
        echo json_encode(['success' => false, 'error' => 'Motorista não encontrado ou não aprovado']);
        exit;
    }

    // Buscar pedidos do Mercado prontos para entrega
    // Status: purchased (shopper finalizou) ou ready_for_delivery
    $stmt = $pdo->prepare("
        SELECT
            o.order_id,
            o.order_number,
            o.customer_name,
            o.customer_phone,
            o.total,
            o.delivery_fee,
            o.status,
            o.items_count,
            o.partner_name as mercado_nome,
            o.partner_id as mercado_id,

            -- Endereço do Mercado (origem - buscar com shopper)
            o.shipping_address as mercado_endereco,
            o.shipping_lat as mercado_lat,
            o.shipping_lng as mercado_lng,
            o.shipping_city as mercado_cidade,

            -- Endereço do Cliente (destino - entregar)
            o.delivery_address as cliente_endereco,
            o.delivery_lat as cliente_lat,
            o.delivery_lng as cliente_lng,
            o.delivery_city as cliente_cidade,
            o.delivery_number as cliente_numero,
            o.delivery_complement as cliente_complemento,

            -- Shopper (quem fez a compra)
            o.shopper_id,
            o.shopper_name,
            o.shopper_phone,

            -- Hand-off
            o.handoff_code,
            o.handoff_generated_at,
            o.shopper_completed_at,

            -- Tempo estimado
            o.distancia_km,
            o.tempo_estimado_min,
            o.delivery_earning as valor_motorista,

            -- Datas
            o.created_at,
            o.purchased_at

        FROM om_market_orders o
        WHERE o.status IN ('purchased', 'ready_for_delivery', 'shopper_completed')
          AND o.delivery_driver_id IS NULL
          AND o.shopper_id IS NOT NULL
        ORDER BY o.purchased_at ASC
        LIMIT 20
    ");
    $stmt->execute();
    $pedidos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Formatar resposta
    $corridas = [];
    foreach ($pedidos as $p) {
        // Calcular distância se tiver localização do motorista
        $distancia_motorista = null;
        if ($lat && $lng && $p['mercado_lat'] && $p['mercado_lng']) {
            $distancia_motorista = calcularDistancia($lat, $lng, $p['mercado_lat'], $p['mercado_lng']);
        }

        $corridas[] = [
            'id' => $p['order_id'],
            'tipo' => 'MERCADO',
            'order_number' => $p['order_number'],

            // Info do pedido
            'mercado' => [
                'nome' => $p['mercado_nome'] ?: 'Mercado OneMundo',
                'endereco' => $p['mercado_endereco'],
                'cidade' => $p['mercado_cidade'],
                'lat' => floatval($p['mercado_lat']),
                'lng' => floatval($p['mercado_lng'])
            ],

            // Destino (cliente)
            'cliente' => [
                'nome' => $p['customer_name'],
                'telefone' => $p['customer_phone'],
                'endereco' => $p['cliente_endereco'],
                'numero' => $p['cliente_numero'],
                'complemento' => $p['cliente_complemento'],
                'cidade' => $p['cliente_cidade'],
                'lat' => floatval($p['cliente_lat']),
                'lng' => floatval($p['cliente_lng'])
            ],

            // Hand-off com Shopper
            'handoff' => [
                'shopper_nome' => $p['shopper_name'],
                'shopper_telefone' => $p['shopper_phone'],
                'codigo' => $p['handoff_code'],
                'instrucao' => 'Encontre o Shopper e confirme o código de hand-off para retirar os produtos'
            ],

            // Valores
            'valor_entrega' => floatval($p['delivery_fee']),
            'valor_motorista' => floatval($p['valor_motorista'] ?: $p['delivery_fee'] * 0.8),
            'valor_pedido' => floatval($p['total']),
            'itens' => intval($p['items_count']),

            // Distâncias
            'distancia_km' => floatval($p['distancia_km']),
            'tempo_estimado_min' => intval($p['tempo_estimado_min']),
            'distancia_ate_mercado_km' => $distancia_motorista ? round($distancia_motorista, 1) : null,

            // Timestamps
            'pedido_em' => $p['created_at'],
            'compra_finalizada_em' => $p['purchased_at'] ?: $p['shopper_completed_at']
        ];
    }

    echo json_encode([
        'success' => true,
        'tipo' => 'MERCADO',
        'corridas' => $corridas,
        'total' => count($corridas),
        'instrucoes' => [
            '1' => 'Vá até o Mercado indicado',
            '2' => 'Encontre o Shopper e confirme o código de hand-off',
            '3' => 'Retire os produtos e confira os itens',
            '4' => 'Entregue no endereço do cliente',
            '5' => 'Confirme a entrega com o código do cliente'
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("[transporte/mercado/corridas-disponiveis] Erro: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro interno do servidor']);
}

// Função para calcular distância
function calcularDistancia($lat1, $lng1, $lat2, $lng2) {
    $R = 6371; // km
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng/2) * sin($dLng/2);
    return $R * 2 * atan2(sqrt($a), sqrt(1-$a));
}
