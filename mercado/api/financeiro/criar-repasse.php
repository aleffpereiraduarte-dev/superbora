<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * API: Criar Repasses para um Pedido
 * Chamado quando o pedido é confirmado como entregue
 *
 * POST /api/financeiro/criar-repasse.php
 * { "order_id": 123 }
 * ══════════════════════════════════════════════════════════════════════════════
 */

require_once dirname(__DIR__) . '/config.php';

try {
    $db = getDB();
    $input = getInput();

    $orderId = intval($input['order_id'] ?? 0);

    if (!$orderId) {
        response(false, null, 'order_id obrigatório', 400);
    }

    // Buscar pedido
    $stmt = $db->prepare("
        SELECT o.*, p.name as partner_name, p.commission_rate as partner_commission
        FROM om_market_orders o
        LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
        WHERE o.order_id = ?
    ");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();

    if (!$order) {
        response(false, null, 'Pedido não encontrado', 404);
    }

    // Verificar se já tem repasse
    $stmt = $db->prepare("SELECT id FROM om_repasses WHERE order_id = ? LIMIT 1");
    $stmt->execute([$orderId]);
    if ($stmt->fetch()) {
        response(true, ['message' => 'Repasses já criados para este pedido']);
    }

    // Buscar config de hold (padrão 2 horas)
    $holdHours = 2;
    $stmt = $db->prepare("SELECT valor FROM om_repasses_config WHERE chave = 'hold_horas' LIMIT 1");
    $stmt->execute();
    $config = $stmt->fetch();
    if ($config) $holdHours = (int)$config['valor'];

    // Buscar comissões
    $comissaoPlataforma = 0.10; // 10% padrão
    $comissaoShopper = 0; // Valor fixo já definido em shopper_earning

    $stmt = $db->prepare("SELECT tipo, percentual, valor_fixo FROM om_comissoes_config WHERE tipo IN ('plataforma', 'shopper', 'motorista') AND ativo = 1");
    $stmt->execute();
    $comissoes = $stmt->fetchAll();
    foreach ($comissoes as $c) {
        if ($c['tipo'] === 'plataforma') $comissaoPlataforma = $c['percentual'] / 100;
    }

    $holdUntil = date('Y-m-d H:i:s', strtotime("+{$holdHours} hours"));

    $db->beginTransaction();

    // 1. REPASSE MERCADO
    // Mercado recebe: (total do pedido - taxa plataforma - shopper_earning - delivery_fee)
    $valorProdutos = (float)($order['total'] ?? 0);
    $taxaPlataforma = $valorProdutos * $comissaoPlataforma;
    $shopperEarning = (float)($order['shopper_earning'] ?? 0);
    $deliveryFee = (float)($order['delivery_fee'] ?? 0);
    $valorMercado = max(0, $valorProdutos - $taxaPlataforma);

    if ($order['partner_id'] && $valorMercado > 0) {
        $stmt = $db->prepare("
            INSERT INTO om_repasses (order_id, tipo, destinatario_id, valor_bruto, taxa_plataforma, valor_liquido, status, hold_until, created_at)
            VALUES (?, 'mercado', ?, ?, ?, ?, 'hold', ?, NOW())
        ");
        $stmt->execute([
            $orderId,
            $order['partner_id'],
            $valorProdutos,
            $taxaPlataforma,
            $valorMercado,
            $holdUntil
        ]);

        // Atualizar saldo pendente do mercado
        $stmt = $db->prepare("
            INSERT INTO om_mercado_saldo (partner_id, saldo_pendente, updated_at)
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                saldo_pendente = saldo_pendente + VALUES(saldo_pendente),
                updated_at = NOW()
        ");
        $stmt->execute([$order['partner_id'], $valorMercado]);
    }

    // 2. REPASSE SHOPPER
    if ($order['shopper_id'] && $shopperEarning > 0) {
        $stmt = $db->prepare("
            INSERT INTO om_repasses (order_id, tipo, destinatario_id, valor_bruto, taxa_plataforma, valor_liquido, status, hold_until, created_at)
            VALUES (?, 'shopper', ?, ?, 0, ?, 'hold', ?, NOW())
        ");
        $stmt->execute([
            $orderId,
            $order['shopper_id'],
            $shopperEarning,
            $shopperEarning,
            $holdUntil
        ]);

        // Atualizar saldo pendente do shopper
        $stmt = $db->prepare("
            INSERT INTO om_shopper_saldo (shopper_id, saldo_pendente, updated_at)
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                saldo_pendente = saldo_pendente + VALUES(saldo_pendente),
                updated_at = NOW()
        ");
        $stmt->execute([$order['shopper_id'], $shopperEarning]);
    }

    // 3. REPASSE MOTORISTA (se houver)
    if ($order['motorista_id'] && $deliveryFee > 0) {
        $stmt = $db->prepare("
            INSERT INTO om_repasses (order_id, tipo, destinatario_id, valor_bruto, taxa_plataforma, valor_liquido, status, hold_until, created_at)
            VALUES (?, 'motorista', ?, ?, 0, ?, 'hold', ?, NOW())
        ");
        $stmt->execute([
            $orderId,
            $order['motorista_id'],
            $deliveryFee,
            $deliveryFee,
            $holdUntil
        ]);

        // Atualizar saldo pendente do motorista
        $stmt = $db->prepare("
            INSERT INTO om_motorista_saldo (motorista_id, saldo_pendente, updated_at)
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                saldo_pendente = saldo_pendente + VALUES(saldo_pendente),
                updated_at = NOW()
        ");
        $stmt->execute([$order['motorista_id'], $deliveryFee]);
    }

    $db->commit();

    response(true, [
        'order_id' => $orderId,
        'repasses' => [
            'mercado' => $valorMercado,
            'shopper' => $shopperEarning,
            'motorista' => $deliveryFee
        ],
        'hold_until' => $holdUntil,
        'message' => 'Repasses criados com sucesso'
    ]);

} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    response(false, null, $e->getMessage(), 500);
}
