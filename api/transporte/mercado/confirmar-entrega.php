<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * API: Confirmar Entrega ao Cliente
 * POST /api/transporte/mercado/confirmar-entrega.php
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Body: { "order_id": 123, "motorista_id": 1, "codigo": "ABCD", "foto": "base64..." }
 *
 * Finaliza a entrega do pedido do Mercado
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
    $foto = $input['foto'] ?? null; // Base64 opcional
    $lat = floatval($input['lat'] ?? 0);
    $lng = floatval($input['lng'] ?? 0);

    if (!$order_id || !$motorista_id) {
        echo json_encode(['success' => false, 'error' => 'order_id e motorista_id obrigatórios']);
        exit;
    }

    // Verificar pedido
    $stmt = $pdo->prepare("
        SELECT * FROM om_market_orders
        WHERE order_id = ?
          AND delivery_driver_id = ?
          AND status = 'in_transit'
    ");
    $stmt->execute([$order_id, $motorista_id]);
    $pedido = $stmt->fetch();

    if (!$pedido) {
        echo json_encode(['success' => false, 'error' => 'Pedido não encontrado ou status incorreto']);
        exit;
    }

    // Verificar código de entrega (se fornecido)
    if ($codigo && $pedido['delivery_code'] && $pedido['delivery_code'] !== $codigo) {
        echo json_encode([
            'success' => false,
            'error' => 'Código de entrega incorreto',
            'dica' => 'Peça o código de 4 dígitos ao cliente'
        ]);
        exit;
    }

    // Salvar foto se fornecida
    $foto_path = null;
    if ($foto) {
        $foto_path = '/uploads/entregas/' . $order_id . '_' . time() . '.jpg';
        // Decodificar e salvar (simplificado)
        @file_put_contents(dirname(__DIR__, 3) . $foto_path, base64_decode($foto));
    }

    // Confirmar entrega
    $stmt = $pdo->prepare("
        UPDATE om_market_orders SET
            status = 'delivered',
            delivered_at = NOW(),
            delivery_confirmed_at = NOW(),
            delivery_code_verified = 1,
            delivery_code_verified_at = NOW(),
            delivery_photo = ?,
            delivery_photo_at = NOW(),
            delivery_photo_lat = ?,
            delivery_photo_lng = ?,
            actual_delivery = NOW(),
            completed_at = NOW(),
            updated_at = NOW()
        WHERE order_id = ?
    ");
    $stmt->execute([$foto_path, $lat, $lng, $order_id]);

    // Registrar evento
    $stmt = $pdo->prepare("
        INSERT INTO om_market_order_events (order_id, event_type, message, created_by, created_at)
        VALUES (?, 'delivered', 'Pedido entregue ao cliente', ?, NOW())
    ");
    @$stmt->execute([$order_id, 'motorista_' . $motorista_id]);

    // ═══════════════════════════════════════════════════════════════════
    // PROCESSAR PAGAMENTOS AUTOMATICAMENTE
    // ═══════════════════════════════════════════════════════════════════
    $financeiro = processarPagamentosEntrega($pdo, $order_id, $motorista_id, $pedido);

    echo json_encode([
        'success' => true,
        'message' => 'Entrega confirmada! Parabéns!',
        'pedido' => [
            'id' => $order_id,
            'status' => 'delivered',
            'entregue_em' => date('Y-m-d H:i:s')
        ],
        'ganho' => [
            'valor' => $financeiro['valor_motorista'],
            'valor_formatado' => 'R$ ' . number_format($financeiro['valor_motorista'], 2, ',', '.'),
            'tipo' => 'Entrega Mercado',
            'creditado' => true
        ],
        'saldo' => [
            'disponivel' => $financeiro['saldo_motorista'],
            'disponivel_formatado' => 'R$ ' . number_format($financeiro['saldo_motorista'], 2, ',', '.')
        ],
        'resumo' => [
            'cliente' => $pedido['customer_name'],
            'mercado' => $pedido['partner_name'],
            'valor_pedido' => floatval($pedido['total']),
            'seu_ganho' => $financeiro['valor_motorista']
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("[transporte/mercado/confirmar-entrega] Erro: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro interno do servidor']);
}

/**
 * Processa pagamentos após entrega confirmada
 *
 * MODELO INSTACART:
 * - Shopper: pago por COMPRAS (% do subtotal + por item)
 * - Motorista: pago por ENTREGA (% da taxa de entrega)
 */
function processarPagamentosEntrega($pdo, $order_id, $motorista_id, $pedido) {
    // Buscar configurações de comissão
    $stmt = $pdo->query("SELECT tipo, servico, percentual, valor_minimo, valor_fixo FROM om_comissoes_config WHERE ativo = 1");
    $configs = [];
    foreach ($stmt->fetchAll() as $c) {
        $configs[$c['tipo'] . '_' . $c['servico']] = $c;
    }

    // Calcular valores do pedido
    $valor_venda = floatval($pedido['subtotal'] ?: ($pedido['total'] - $pedido['delivery_fee']));
    $custo_produtos = floatval($pedido['custo_produtos'] ?: ($valor_venda * 0.70));
    $valor_entrega = floatval($pedido['delivery_fee'] ?: 0);
    $qtd_itens = intval($pedido['items_count'] ?: 1);

    // ═══════════════════════════════════════════════════════════════════
    // MOTORISTA: Pago por ENTREGA (80% da taxa de entrega)
    // ═══════════════════════════════════════════════════════════════════
    $config_mot = $configs['motorista_mercado'] ?? ['percentual' => 80, 'valor_minimo' => 5];
    $comissao_plataforma_entrega = $valor_entrega * 0.20; // 20% para plataforma
    $valor_motorista_bruto = $valor_entrega * ($config_mot['percentual'] / 100);
    $valor_motorista = max($valor_motorista_bruto, floatval($config_mot['valor_minimo']));

    // ═══════════════════════════════════════════════════════════════════
    // SHOPPER: Pago por COMPRAS (5% do subtotal + R$0.50/item)
    // ═══════════════════════════════════════════════════════════════════
    $shopper_id = intval($pedido['shopper_id']);
    $config_shop = $configs['shopper_mercado'] ?? ['percentual' => 5, 'valor_minimo' => 5, 'valor_fixo' => 0.50];

    // Base: 5% do valor dos produtos
    $valor_shopper_base = $valor_venda * ($config_shop['percentual'] / 100);
    // Bônus: R$0.50 por item
    $valor_shopper_itens = $qtd_itens * floatval($config_shop['valor_fixo']);
    // Total com mínimo garantido
    $valor_shopper = max($valor_shopper_base + $valor_shopper_itens, floatval($config_shop['valor_minimo']));

    // ═══════════════════════════════════════════════════════════════════
    // LUCRO PLATAFORMA
    // ═══════════════════════════════════════════════════════════════════
    // 1. Markup nos produtos (30%)
    $lucro_produtos = $valor_venda - $custo_produtos;
    // 2. Comissão na entrega (20%)
    // 3. Menos o que paga ao shopper
    $lucro_plataforma = $lucro_produtos + $comissao_plataforma_entrega - $valor_shopper;

    // ═══════════════════════════════════════════════════════════════════
    // REGISTRAR GANHO DO MOTORISTA (por ENTREGA)
    // ═══════════════════════════════════════════════════════════════════
    $stmt = $pdo->prepare("
        INSERT INTO om_motorista_ganhos (motorista_id, order_id, order_type, valor_bruto, percentual_comissao, valor_comissao, valor_liquido, status, processed_at)
        VALUES (?, ?, 'mercado', ?, 80, ?, ?, 'processado', NOW())
        ON CONFLICT (motorista_id, order_id) DO UPDATE SET valor_liquido = EXCLUDED.valor_liquido, status = 'processado'
    ");
    $stmt->execute([$motorista_id, $order_id, $valor_entrega, $comissao_plataforma_entrega, $valor_motorista]);

    // Atualizar saldo do motorista
    $stmt = $pdo->prepare("
        INSERT INTO om_motorista_saldo (motorista_id, saldo_disponivel, total_ganhos)
        VALUES (?, ?, ?)
        ON CONFLICT (motorista_id) DO UPDATE SET saldo_disponivel = om_motorista_saldo.saldo_disponivel + ?, total_ganhos = om_motorista_saldo.total_ganhos + ?
    ");
    $stmt->execute([$motorista_id, $valor_motorista, $valor_motorista, $valor_motorista, $valor_motorista]);

    // Buscar saldo atualizado
    $stmt = $pdo->prepare("SELECT saldo_disponivel FROM om_motorista_saldo WHERE motorista_id = ?");
    $stmt->execute([$motorista_id]);
    $saldo_motorista = floatval($stmt->fetchColumn() ?: 0);

    // Registrar na wallet
    $stmt = $pdo->prepare("
        INSERT INTO om_motorista_wallet (motorista_id, tipo, valor, saldo_anterior, saldo_posterior, referencia_tipo, referencia_id, descricao)
        VALUES (?, 'ganho', ?, ?, ?, 'om_market_orders', ?, ?)
    ");
    $stmt->execute([$motorista_id, $valor_motorista, $saldo_motorista - $valor_motorista, $saldo_motorista, $order_id, "Entrega #$order_id"]);

    // ═══════════════════════════════════════════════════════════════════
    // REGISTRAR GANHO DO SHOPPER (por COMPRAS - estilo Instacart)
    // ═══════════════════════════════════════════════════════════════════
    if ($shopper_id) {
        $stmt = $pdo->prepare("
            INSERT INTO om_shopper_ganhos (shopper_id, order_id, order_type, valor_bruto, percentual_comissao, valor_comissao, valor_liquido, status, processed_at)
            VALUES (?, ?, 'mercado', ?, 5, 0, ?, 'processado', NOW())
            ON CONFLICT (shopper_id, order_id) DO UPDATE SET valor_liquido = EXCLUDED.valor_liquido, status = 'processado'
        ");
        $stmt->execute([$shopper_id, $order_id, $valor_venda, $valor_shopper]);

        $stmt = $pdo->prepare("
            INSERT INTO om_shopper_saldo (shopper_id, saldo_disponivel, total_ganhos)
            VALUES (?, ?, ?)
            ON CONFLICT (shopper_id) DO UPDATE SET saldo_disponivel = om_shopper_saldo.saldo_disponivel + ?, total_ganhos = om_shopper_saldo.total_ganhos + ?
        ");
        $stmt->execute([$shopper_id, $valor_shopper, $valor_shopper, $valor_shopper, $valor_shopper]);

        $stmt = $pdo->prepare("SELECT saldo_disponivel FROM om_shopper_saldo WHERE shopper_id = ?");
        $stmt->execute([$shopper_id]);
        $saldo_shopper = floatval($stmt->fetchColumn() ?: 0);

        $stmt = $pdo->prepare("
            INSERT INTO om_shopper_wallet (shopper_id, tipo, valor, saldo_anterior, saldo_posterior, referencia_tipo, referencia_id, descricao)
            VALUES (?, 'ganho', ?, ?, ?, 'om_market_orders', ?, ?)
        ");
        $stmt->execute([$shopper_id, $valor_shopper, $saldo_shopper - $valor_shopper, $saldo_shopper, $order_id, "Compras #$order_id"]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // REGISTRAR REPASSE AO MERCADO
    // ═══════════════════════════════════════════════════════════════════
    $mercado_id = intval($pedido['partner_id'] ?: $pedido['market_id'] ?: 1);
    $stmt = $pdo->prepare("
        INSERT INTO om_mercado_repasses (mercado_id, order_id, valor_produtos, valor_liquido, status)
        VALUES (?, ?, ?, ?, 'pendente')
        ON CONFLICT (mercado_id, order_id) DO UPDATE SET valor_produtos = EXCLUDED.valor_produtos
    ");
    $stmt->execute([$mercado_id, $order_id, $custo_produtos, $custo_produtos]);

    // ═══════════════════════════════════════════════════════════════════
    // REGISTRAR RESUMO FINANCEIRO
    // ═══════════════════════════════════════════════════════════════════
    $stmt = $pdo->prepare("
        INSERT INTO om_pedido_financeiro (order_id, order_type, valor_produtos, valor_entrega, valor_total, valor_mercado, valor_shopper, valor_motorista, valor_plataforma, status, processed_at)
        VALUES (?, 'mercado', ?, ?, ?, ?, ?, ?, ?, 'processado', NOW())
        ON CONFLICT (order_id) DO UPDATE SET status = 'processado'
    ");
    $stmt->execute([$order_id, $valor_venda, $valor_entrega, $pedido['total'], $custo_produtos, $valor_shopper, $valor_motorista, $lucro_plataforma]);

    return [
        'valor_motorista' => $valor_motorista,
        'valor_shopper' => $valor_shopper,
        'saldo_motorista' => $saldo_motorista,
        'saldo_shopper' => $saldo_shopper ?? 0,
        'lucro_plataforma' => $lucro_plataforma
    ];
}
