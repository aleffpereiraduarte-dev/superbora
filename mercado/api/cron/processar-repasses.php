<?php
/**
 * CRON: Processar Repasses Automáticos
 * Executar a cada 5 minutos no crontab
 *
 * - Libera repasses após 2 horas da entrega confirmada
 * - Atualiza saldos de shoppers, motoristas e mercados
 * - Cria registros de wallet para auditoria
 */

// Evitar execução via web
if (php_sapi_name() !== 'cli' && !isset($_GET['cron_key'])) {
    http_response_code(403);
    die('Acesso negado');
}

require_once dirname(__DIR__) . '/config.php';

try {
    $db = getDB();
    $now = date('Y-m-d H:i:s');
    $processed = 0;
    $errors = [];

    // Buscar config de hold (padrão 2 horas)
    $holdHours = 2;
    $stmt = $db->prepare("SELECT valor FROM om_repasses_config WHERE chave = 'hold_horas' LIMIT 1");
    $stmt->execute();
    $config = $stmt->fetch();
    if ($config) $holdHours = (int)$config['valor'];

    // Buscar repasses em hold que já passaram o tempo
    $stmt = $db->prepare("
        SELECT r.*, o.order_id, o.order_number, o.partner_id, o.shopper_id, o.motorista_id,
               o.total, o.shopper_earning, o.delivery_fee, o.delivered_at
        FROM om_repasses r
        INNER JOIN om_market_orders o ON r.order_id = o.order_id
        WHERE r.status = 'hold'
        AND r.hold_until <= NOW()
        ORDER BY r.created_at ASC
        LIMIT 100
    ");
    $stmt->execute();
    $repasses = $stmt->fetchAll();

    foreach ($repasses as $repasse) {
        try {
            $db->beginTransaction();

            $tipo = $repasse['tipo']; // mercado, shopper, motorista
            $valor = (float)$repasse['valor_liquido'];
            $repasseId = $repasse['id'];

            if ($tipo === 'mercado' && $repasse['partner_id']) {
                // Atualizar saldo do mercado
                $stmt = $db->prepare("
                    INSERT INTO om_mercado_saldo (partner_id, saldo_disponivel, total_recebido, updated_at)
                    VALUES (?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                        saldo_disponivel = saldo_disponivel + VALUES(saldo_disponivel),
                        total_recebido = total_recebido + VALUES(total_recebido),
                        updated_at = NOW()
                ");
                $stmt->execute([$repasse['partner_id'], $valor, $valor]);

                // Registrar transação
                $stmt = $db->prepare("
                    INSERT INTO om_mercado_wallet (partner_id, order_id, tipo, valor, descricao, saldo_anterior, saldo_atual, status, created_at)
                    SELECT ?, ?, 'credito', ?, ?,
                        COALESCE((SELECT saldo_disponivel - ? FROM om_mercado_saldo WHERE partner_id = ?), 0),
                        COALESCE((SELECT saldo_disponivel FROM om_mercado_saldo WHERE partner_id = ?), ?),
                        'concluido', NOW()
                ");
                $stmt->execute([
                    $repasse['partner_id'],
                    $repasse['order_id'],
                    $valor,
                    "Repasse Pedido #{$repasse['order_number']}",
                    $valor, $repasse['partner_id'],
                    $repasse['partner_id'], $valor
                ]);

            } elseif ($tipo === 'shopper' && $repasse['shopper_id']) {
                // Atualizar saldo do shopper
                $stmt = $db->prepare("
                    INSERT INTO om_shopper_saldo (shopper_id, saldo_disponivel, total_ganhos, updated_at)
                    VALUES (?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                        saldo_disponivel = saldo_disponivel + VALUES(saldo_disponivel),
                        saldo_pendente = GREATEST(0, saldo_pendente - VALUES(saldo_disponivel)),
                        total_ganhos = total_ganhos + VALUES(total_ganhos),
                        updated_at = NOW()
                ");
                $stmt->execute([$repasse['shopper_id'], $valor, $valor]);

                // Registrar transação
                $stmt = $db->prepare("
                    INSERT INTO om_shopper_wallet (shopper_id, tipo, valor, descricao, referencia_tipo, referencia_id, status, created_at)
                    VALUES (?, 'ganho', ?, ?, 'pedido', ?, 'concluido', NOW())
                ");
                $stmt->execute([
                    $repasse['shopper_id'],
                    $valor,
                    "Ganho Pedido #{$repasse['order_number']}",
                    $repasse['order_id']
                ]);

            } elseif ($tipo === 'motorista' && $repasse['motorista_id']) {
                // Atualizar saldo do motorista
                $stmt = $db->prepare("
                    INSERT INTO om_motorista_saldo (motorista_id, saldo_disponivel, total_ganhos, updated_at)
                    VALUES (?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                        saldo_disponivel = saldo_disponivel + VALUES(saldo_disponivel),
                        saldo_pendente = GREATEST(0, saldo_pendente - VALUES(saldo_disponivel)),
                        total_ganhos = total_ganhos + VALUES(total_ganhos),
                        updated_at = NOW()
                ");
                $stmt->execute([$repasse['motorista_id'], $valor, $valor]);

                // Registrar transação
                $stmt = $db->prepare("
                    INSERT INTO om_motorista_wallet (motorista_id, tipo, valor, descricao, referencia_tipo, referencia_id, status, created_at)
                    VALUES (?, 'ganho', ?, ?, 'pedido', ?, 'concluido', NOW())
                ");
                $stmt->execute([
                    $repasse['motorista_id'],
                    $valor,
                    "Entrega Pedido #{$repasse['order_number']}",
                    $repasse['order_id']
                ]);
            }

            // Atualizar status do repasse
            $stmt = $db->prepare("
                UPDATE om_repasses
                SET status = 'liberado', liberado_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$repasseId]);

            // Log
            $stmt = $db->prepare("
                INSERT INTO om_repasses_log (repasse_id, status_anterior, status_novo, observacao, created_at)
                VALUES (?, 'hold', 'liberado', 'Liberado automaticamente pelo cron', NOW())
            ");
            $stmt->execute([$repasseId]);

            $db->commit();
            $processed++;

        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = "Repasse #{$repasseId}: " . $e->getMessage();
        }
    }

    // Output
    $result = [
        'success' => true,
        'processed' => $processed,
        'errors' => $errors,
        'timestamp' => $now
    ];

    if (php_sapi_name() === 'cli') {
        echo "Repasses processados: $processed\n";
        if ($errors) {
            echo "Erros:\n";
            foreach ($errors as $err) echo "  - $err\n";
        }
    } else {
        header('Content-Type: application/json');
        echo json_encode($result);
    }

} catch (Exception $e) {
    if (php_sapi_name() === 'cli') {
        echo "ERRO FATAL: " . $e->getMessage() . "\n";
        exit(1);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}
