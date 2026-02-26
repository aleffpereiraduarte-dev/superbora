<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * API: Processar Pagamentos após Entrega
 * POST /api/financeiro/processar-entrega.php
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Body: { "order_id": 123, "order_type": "mercado" }
 *
 * Processa todas as comissões e pagamentos após entrega confirmada:
 * - Registra ganho do Shopper (usando cálculo unificado)
 * - Registra ganho do Motorista (usando cálculo unificado)
 * - Registra repasse ao Mercado (custo dos produtos)
 * - Registra lucro da plataforma (markup + comissão entrega)
 *
 * IMPORTANTE: Usa OmComissao para garantir consistência com simular-ganho.php
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

require_once dirname(__DIR__, 2) . '/database.php';
require_once dirname(__DIR__, 2) . '/includes/classes/OmComissao.php';
require_once dirname(__DIR__, 2) . '/includes/classes/OmAudit.php';

try {
    $pdo = getDB();
    om_comissao()->setDb($pdo);
    om_audit()->setDb($pdo);

    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $order_id = intval($input['order_id'] ?? 0);
    $order_type = $input['order_type'] ?? 'mercado';

    if (!$order_id) {
        echo json_encode(['success' => false, 'error' => 'order_id obrigatório']);
        exit;
    }

    // Verificar se já foi processado
    $stmt = $pdo->prepare("SELECT * FROM om_pedido_financeiro WHERE order_id = ? AND order_type = ?");
    $stmt->execute([$order_id, $order_type]);
    $ja_processado = $stmt->fetch();

    if ($ja_processado && $ja_processado['status'] === 'processado') {
        echo json_encode([
            'success' => true,
            'message' => 'Pedido já foi processado anteriormente',
            'dados' => $ja_processado
        ]);
        exit;
    }

    // Buscar pedido com contagem de itens + dados da entrega
    $stmt = $pdo->prepare("
        SELECT o.*,
            (SELECT COUNT(*) FROM om_market_order_items WHERE order_id = o.order_id) as items_count,
            e.valor_frete AS valor_frete_real,
            e.motorista_id AS entrega_motorista_id
        FROM om_market_orders o
        LEFT JOIN om_entregas e ON e.referencia_id = o.order_id AND e.origem_sistema = 'mercado'
        WHERE o.order_id = ?
    ");
    $stmt->execute([$order_id]);
    $pedido = $stmt->fetch();

    if (!$pedido) {
        echo json_encode(['success' => false, 'error' => 'Pedido não encontrado']);
        exit;
    }

    if ($pedido['status'] !== 'delivered') {
        echo json_encode(['success' => false, 'error' => 'Pedido ainda não foi entregue. Status: ' . $pedido['status']]);
        exit;
    }

    // Calcular distribuição usando classe unificada
    $distribuicao = om_comissao()->calcularDistribuicao($pedido);

    // Iniciar transação
    $pdo->beginTransaction();

    try {
        // ═══════════════════════════════════════════════════════════════════
        // REGISTRAR GANHO DO SHOPPER
        // ═══════════════════════════════════════════════════════════════════

        $shopper_id = intval($pedido['shopper_id']);
        $valor_shopper = $distribuicao['shopper']['valor'];

        if ($shopper_id && $valor_shopper > 0) {
            $calcShopper = $distribuicao['shopper']['calculo'];

            $stmt = $pdo->prepare("
                INSERT INTO om_shopper_ganhos
                (shopper_id, order_id, order_type, valor_bruto, percentual_comissao, valor_comissao, valor_liquido, status, processed_at)
                VALUES (?, ?, ?, ?, ?, 0, ?, 'processado', NOW())
                ON DUPLICATE KEY UPDATE status = 'processado', processed_at = NOW(), valor_liquido = VALUES(valor_liquido)
            ");
            $stmt->execute([
                $shopper_id, $order_id, $order_type,
                $calcShopper['valor_bruto'],
                $calcShopper['percentual'],
                $valor_shopper
            ]);

            // Atualizar saldo
            $stmt = $pdo->prepare("
                INSERT INTO om_shopper_saldo (shopper_id, saldo_disponivel, total_ganhos)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    saldo_disponivel = saldo_disponivel + ?,
                    total_ganhos = total_ganhos + ?
            ");
            $stmt->execute([$shopper_id, $valor_shopper, $valor_shopper, $valor_shopper, $valor_shopper]);

            // Wallet
            $stmt = $pdo->prepare("SELECT saldo_disponivel FROM om_shopper_saldo WHERE shopper_id = ?");
            $stmt->execute([$shopper_id]);
            $saldo = floatval($stmt->fetchColumn() ?: 0);

            $stmt = $pdo->prepare("
                INSERT INTO om_shopper_wallet (shopper_id, tipo, valor, saldo_anterior, saldo_posterior, referencia_tipo, referencia_id, descricao, status, created_at)
                VALUES (?, 'ganho', ?, ?, ?, 'om_market_orders', ?, ?, 'concluido', NOW())
            ");
            $stmt->execute([$shopper_id, $valor_shopper, $saldo - $valor_shopper, $saldo, $order_id, "Compras Mercado #$order_id"]);
        }

        // ═══════════════════════════════════════════════════════════════════
        // REGISTRAR GANHO DO MOTORISTA
        // ═══════════════════════════════════════════════════════════════════

        $motorista_id = intval($pedido['driver_id'] ?? $pedido['delivery_driver_id'] ?? $pedido['entrega_motorista_id'] ?? 0);
        $valor_motorista = $distribuicao['motorista']['valor_total']; // inclui gorjeta
        $gorjeta = $distribuicao['motorista']['gorjeta'];
        $valor_entrega = $distribuicao['motorista']['valor'];

        if ($motorista_id && $valor_motorista > 0) {
            $calcMotorista = $distribuicao['motorista']['calculo'];

            // Registro interno de ganhos (historico SuperBora)
            $stmt = $pdo->prepare("
                INSERT INTO om_motorista_ganhos
                (motorista_id, order_id, order_type, valor_bruto, percentual_comissao, valor_comissao, valor_liquido, status, processed_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'processado', NOW())
                ON DUPLICATE KEY UPDATE status = 'processado', processed_at = NOW(), valor_liquido = VALUES(valor_liquido)
            ");
            $stmt->execute([
                $motorista_id, $order_id, $order_type,
                $calcMotorista['custo_real'],
                $calcMotorista['percentual'],
                $calcMotorista['comissao_plataforma'],
                $valor_motorista
            ]);

            // ═══════════════════════════════════════════════════════════════
            // CREDITAR NA WALLET DO BORAUM (tabelas reais do motorista)
            // ═══════════════════════════════════════════════════════════════

            // 1. Buscar saldo atual do motorista no BoraUm
            $stmt = $pdo->prepare("SELECT saldo FROM boraum_motorista_wallet WHERE motorista_id = ?");
            $stmt->execute([$motorista_id]);
            $walletRow = $stmt->fetch();
            $saldoAntes = $walletRow ? floatval($walletRow['saldo']) : 0;

            if (!$walletRow) {
                // Criar wallet se nao existe
                $pdo->prepare("INSERT INTO boraum_motorista_wallet (motorista_id, saldo, total_ganhos) VALUES (?, 0, 0)")->execute([$motorista_id]);
            }

            // 2. Creditar valor da entrega
            $saldoAposEntrega = $saldoAntes + $valor_entrega;
            $pdo->prepare("
                UPDATE boraum_motorista_wallet
                SET saldo = saldo + ?, total_ganhos = total_ganhos + ?
                WHERE motorista_id = ?
            ")->execute([$valor_entrega, $valor_entrega, $motorista_id]);

            $descEntrega = $calcMotorista['entrega_gratis']
                ? "Entrega SuperBora #$order_id (frete gratis)"
                : "Entrega SuperBora #$order_id";

            $pdo->prepare("
                INSERT INTO boraum_motorista_transacoes (motorista_id, tipo, valor, descricao, referencia_id, saldo_antes, saldo_depois, created_at)
                VALUES (?, 'ganho', ?, ?, ?, ?, ?, NOW())
            ")->execute([$motorista_id, $valor_entrega, $descEntrega, $order_id, $saldoAntes, $saldoAposEntrega]);

            // 3. Creditar gorjeta separada (se houver)
            if ($gorjeta > 0) {
                $saldoAposGorjeta = $saldoAposEntrega + $gorjeta;
                $pdo->prepare("
                    UPDATE boraum_motorista_wallet
                    SET saldo = saldo + ?, total_ganhos = total_ganhos + ?
                    WHERE motorista_id = ?
                ")->execute([$gorjeta, $gorjeta, $motorista_id]);

                $pdo->prepare("
                    INSERT INTO boraum_motorista_transacoes (motorista_id, tipo, valor, descricao, referencia_id, saldo_antes, saldo_depois, created_at)
                    VALUES (?, 'gorjeta', ?, ?, ?, ?, ?, NOW())
                ")->execute([$motorista_id, $gorjeta, "Gorjeta SuperBora #$order_id", $order_id, $saldoAposEntrega, $saldoAposGorjeta]);
            }
        }

        // ═══════════════════════════════════════════════════════════════════
        // REGISTRAR REPASSE AO MERCADO
        // ═══════════════════════════════════════════════════════════════════

        $mercado_id = intval($pedido['partner_id'] ?? $pedido['market_id'] ?? 1);
        $valor_mercado = $distribuicao['mercado_recebe'];

        $stmt = $pdo->prepare("
            INSERT INTO om_mercado_repasses (mercado_id, order_id, valor_produtos, valor_taxa_plataforma, valor_liquido, status)
            VALUES (?, ?, ?, 0, ?, 'pendente')
            ON DUPLICATE KEY UPDATE valor_produtos = VALUES(valor_produtos), valor_liquido = VALUES(valor_liquido)
        ");
        $stmt->execute([$mercado_id, $order_id, $valor_mercado, $valor_mercado]);

        // Creditar na wallet do estabelecimento
        if ($valor_mercado > 0) {
            // Atualizar saldo no parceiro
            $pdo->prepare("
                UPDATE om_market_partners
                SET saldo_disponivel = COALESCE(saldo_disponivel, 0) + ?
                WHERE partner_id = ?
            ")->execute([$valor_mercado, $mercado_id]);

            // Buscar saldo atualizado
            $stmt = $pdo->prepare("SELECT COALESCE(saldo_disponivel, 0) FROM om_market_partners WHERE partner_id = ?");
            $stmt->execute([$mercado_id]);
            $saldoMercado = floatval($stmt->fetchColumn() ?: 0);

            $pdo->prepare("
                INSERT INTO om_market_wallet (partner_id, tipo, valor, saldo_anterior, saldo_posterior, referencia_tipo, referencia_id, descricao, status, created_at)
                VALUES (?, 'venda', ?, ?, ?, 'om_market_orders', ?, ?, 'liberado', NOW())
            ")->execute([$mercado_id, $valor_mercado, $saldoMercado - $valor_mercado, $saldoMercado, $order_id, "Venda pedido #$order_id"]);
        }

        // ═══════════════════════════════════════════════════════════════════
        // REGISTRAR RESUMO FINANCEIRO
        // ═══════════════════════════════════════════════════════════════════

        $stmt = $pdo->prepare("
            INSERT INTO om_pedido_financeiro
            (order_id, order_type, valor_produtos, valor_entrega, valor_servico, valor_total,
             valor_mercado, valor_shopper, valor_motorista, valor_plataforma, status, processed_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'processado', NOW())
            ON DUPLICATE KEY UPDATE status = 'processado', processed_at = NOW()
        ");
        $stmt->execute([
            $order_id, $order_type,
            $distribuicao['cliente_pagou']['produtos'],
            $distribuicao['cliente_pagou']['entrega'],
            $distribuicao['cliente_pagou']['servico'],
            $distribuicao['cliente_pagou']['total'],
            $valor_mercado,
            $valor_shopper,
            $valor_motorista,
            $distribuicao['plataforma']['total']
        ]);

        $pdo->commit();

        // Log de auditoria
        om_audit()->log('pay', 'order', $order_id, null, [
            'shopper_valor' => $valor_shopper,
            'motorista_valor' => $valor_motorista,
            'mercado_valor' => $valor_mercado
        ], "Pagamentos processados para pedido #$order_id");

        echo json_encode([
            'success' => true,
            'message' => 'Pagamentos processados com sucesso!',
            'pedido' => ['id' => $order_id, 'tipo' => $order_type],
            'resumo_financeiro' => [
                'cliente_pagou' => $distribuicao['cliente_pagou'],
                'distribuicao' => [
                    'mercado' => [
                        'id' => $mercado_id,
                        'recebe' => $valor_mercado,
                        'descricao' => 'Custo dos produtos'
                    ],
                    'shopper' => [
                        'id' => $shopper_id,
                        'recebe' => $valor_shopper,
                        'calculo' => $distribuicao['shopper']['calculo']['descricao'],
                        'descricao' => 'Compras no mercado'
                    ],
                    'motorista' => [
                        'id' => $motorista_id,
                        'recebe' => $valor_motorista,
                        'calculo' => $distribuicao['motorista']['calculo']['descricao'],
                        'descricao' => 'Entrega ao cliente'
                    ],
                    'plataforma' => [
                        'lucro_produtos' => $distribuicao['plataforma']['lucro_produtos'],
                        'comissao_entrega' => $distribuicao['plataforma']['comissao_entrega'],
                        'total' => $distribuicao['plataforma']['total'],
                        'descricao' => 'Markup produtos + comissão entrega'
                    ]
                ]
            ]
        ], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    error_log("[processar-entrega] Erro: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro ao processar pagamentos']);
}
