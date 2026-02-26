<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * API: Processar Pagamentos de Cancelamento
 * POST /api/financeiro/processar-cancelamento.php
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Body: {
 *   "order_id": 123,
 *   "motivo": "demora_restaurante|cliente_desistiu|problema_produto|sem_estoque|outro",
 *   "cancelado_por": "customer|partner|system|admin",
 *   "detalhes": "texto livre opcional"
 * }
 *
 * Regras de pagamento por cancelamento:
 *
 * ┌──────────────────────────┬──────────────┬─────────┬───────────┬──────────┐
 * │ Motivo                   │ Restaurante  │ Shopper │ Motorista │ Cliente  │
 * ├──────────────────────────┼──────────────┼─────────┼───────────┼──────────┤
 * │ demora_restaurante       │ NAO paga     │ Paga    │ Paga*     │ Reembolso│
 * │ cliente_desistiu (cedo)  │ NAO paga     │ NAO     │ NAO       │ Reembolso│
 * │ cliente_desistiu (tarde) │ Paga         │ Paga    │ Paga*     │ Parcial  │
 * │ problema_produto         │ NAO paga     │ Paga    │ Paga*     │ Reembolso│
 * │ sem_estoque              │ NAO paga     │ Paga    │ NAO       │ Reembolso│
 * │ partner_cancelou         │ NAO paga     │ Paga    │ NAO       │ Reembolso│
 * └──────────────────────────┴──────────────┴─────────┴───────────┴──────────┘
 * * Motorista so recebe se ja coletou (picked_up)
 * Shopper so recebe se ja iniciou coleta (coletando/coleta_finalizada)
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

    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $order_id = intval($input['order_id'] ?? 0);
    $motivo = $input['motivo'] ?? 'outro';
    $cancelado_por = $input['cancelado_por'] ?? 'system';
    $detalhes = $input['detalhes'] ?? '';

    if (!$order_id) {
        echo json_encode(['success' => false, 'error' => 'order_id obrigatorio']);
        exit;
    }

    // Verificar se ja processado
    $stmt = $pdo->prepare("SELECT * FROM om_pedido_financeiro WHERE order_id = ? AND order_type = 'mercado'");
    $stmt->execute([$order_id]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => true, 'message' => 'Pedido ja foi processado anteriormente']);
        exit;
    }

    // Buscar pedido
    $stmt = $pdo->prepare("
        SELECT o.*,
            (SELECT COUNT(*) FROM om_market_order_items WHERE order_id = o.order_id) as items_count,
            e.valor_frete AS valor_frete_real,
            e.motorista_id AS entrega_motorista_id,
            e.status AS entrega_status
        FROM om_market_orders o
        LEFT JOIN om_entregas e ON e.referencia_id = o.order_id AND e.origem_sistema = 'mercado'
        WHERE o.order_id = ?
    ");
    $stmt->execute([$order_id]);
    $pedido = $stmt->fetch();

    if (!$pedido) {
        echo json_encode(['success' => false, 'error' => 'Pedido nao encontrado']);
        exit;
    }

    // Status do pedido quando cancelou - determina quem ja trabalhou
    $statusNoCancelamento = $pedido['status'];
    $shopperId = intval($pedido['shopper_id'] ?? 0);
    $motoristaId = intval($pedido['driver_id'] ?? $pedido['delivery_driver_id'] ?? $pedido['entrega_motorista_id'] ?? 0);
    $partnerId = intval($pedido['partner_id'] ?? $pedido['market_id'] ?? 0);
    $subtotal = floatval($pedido['subtotal'] ?? 0);
    $gorjeta = floatval($pedido['tip_amount'] ?? 0);

    // ═══════════════════════════════════════════════════════════════════
    // DETERMINAR QUEM RECEBE COM BASE NO MOTIVO E STATUS
    // ═══════════════════════════════════════════════════════════════════

    // Shopper ja trabalhou?
    $shopperTrabalhou = $shopperId > 0 && in_array($statusNoCancelamento, [
        'coletando', 'coleta_finalizada', 'pronto', 'aguardando_entregador',
        'em_entrega', 'delivering', 'out_for_delivery'
    ]);

    // Motorista ja coletou?
    $motoristaColetou = $motoristaId > 0 && in_array($statusNoCancelamento, [
        'em_entrega', 'delivering', 'out_for_delivery'
    ]);

    // Restaurante ja preparou?
    $restaurantePreparou = in_array($statusNoCancelamento, [
        'preparando', 'pronto', 'coleta_finalizada', 'aguardando_entregador',
        'em_entrega', 'delivering', 'out_for_delivery'
    ]);

    // Regras por motivo
    $pagarRestaurante = false;
    $pagarShopper = false;
    $pagarMotorista = false;
    $reembolsoCliente = 'total'; // total, parcial, nenhum

    switch ($motivo) {
        case 'demora_restaurante':
            // Restaurante demorou → NAO paga restaurante, mas paga quem trabalhou
            $pagarRestaurante = false;
            $pagarShopper = $shopperTrabalhou;
            $pagarMotorista = $motoristaColetou;
            $reembolsoCliente = 'total';
            break;

        case 'problema_produto':
            // Produto com problema → NAO paga restaurante
            $pagarRestaurante = false;
            $pagarShopper = $shopperTrabalhou;
            $pagarMotorista = $motoristaColetou;
            $reembolsoCliente = 'total';
            break;

        case 'sem_estoque':
        case 'partner_cancelou':
            // Parceiro cancelou / sem estoque → NAO paga restaurante
            $pagarRestaurante = false;
            $pagarShopper = $shopperTrabalhou;
            $pagarMotorista = false; // ainda nao coletou normalmente
            $reembolsoCliente = 'total';
            break;

        case 'cliente_desistiu':
            if ($restaurantePreparou) {
                // Cliente desistiu DEPOIS do preparo → restaurante recebe
                $pagarRestaurante = true;
                $pagarShopper = $shopperTrabalhou;
                $pagarMotorista = $motoristaColetou;
                $reembolsoCliente = 'parcial'; // desconta taxa cancelamento
            } else {
                // Cliente desistiu ANTES do preparo → ninguem recebe
                $pagarRestaurante = false;
                $pagarShopper = false;
                $pagarMotorista = false;
                $reembolsoCliente = 'total';
            }
            break;

        default:
            // Outro motivo → analise caso a caso
            $pagarRestaurante = $restaurantePreparou;
            $pagarShopper = $shopperTrabalhou;
            $pagarMotorista = $motoristaColetou;
            $reembolsoCliente = $restaurantePreparou ? 'parcial' : 'total';
            break;
    }

    // ═══════════════════════════════════════════════════════════════════
    // CALCULAR VALORES
    // ═══════════════════════════════════════════════════════════════════

    $distribuicao = om_comissao()->calcularDistribuicao($pedido);

    $valorRestaurante = $pagarRestaurante ? $distribuicao['mercado_recebe'] : 0;
    $valorShopper = $pagarShopper ? $distribuicao['shopper']['valor'] : 0;
    $valorMotorista = $pagarMotorista ? $distribuicao['motorista']['valor_total'] : 0;

    // Taxa de cancelamento (10% do subtotal, max R$20) quando cliente cancela tarde
    $taxaCancelamento = 0;
    if ($reembolsoCliente === 'parcial') {
        $taxaCancelamento = min($subtotal * 0.10, 20.00);
    }

    $valorReembolso = 0;
    if ($reembolsoCliente === 'total') {
        $valorReembolso = floatval($pedido['total'] ?? 0);
    } elseif ($reembolsoCliente === 'parcial') {
        $valorReembolso = floatval($pedido['total'] ?? 0) - $taxaCancelamento;
    }

    // ═══════════════════════════════════════════════════════════════════
    // PROCESSAR PAGAMENTOS
    // ═══════════════════════════════════════════════════════════════════

    $pdo->beginTransaction();

    try {
        // Shopper
        if ($pagarShopper && $shopperId && $valorShopper > 0) {
            $pdo->prepare("
                INSERT INTO om_shopper_ganhos (shopper_id, order_id, order_type, valor_bruto, percentual_comissao, valor_comissao, valor_liquido, status, processed_at)
                VALUES (?, ?, 'mercado', ?, 0, 0, ?, 'processado', NOW())
                ON DUPLICATE KEY UPDATE status = 'processado'
            ")->execute([$shopperId, $order_id, $subtotal, $valorShopper]);

            $pdo->prepare("
                INSERT INTO om_shopper_saldo (shopper_id, saldo_disponivel, total_ganhos)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE saldo_disponivel = saldo_disponivel + ?, total_ganhos = total_ganhos + ?
            ")->execute([$shopperId, $valorShopper, $valorShopper, $valorShopper, $valorShopper]);

            $stmt = $pdo->prepare("SELECT saldo_disponivel FROM om_shopper_saldo WHERE shopper_id = ?");
            $stmt->execute([$shopperId]);
            $saldo = floatval($stmt->fetchColumn() ?: 0);

            $pdo->prepare("
                INSERT INTO om_shopper_wallet (shopper_id, tipo, valor, saldo_anterior, saldo_posterior, referencia_tipo, referencia_id, descricao, status, created_at)
                VALUES (?, 'ganho', ?, ?, ?, 'om_market_orders', ?, ?, 'concluido', NOW())
            ")->execute([$shopperId, $valorShopper, $saldo - $valorShopper, $saldo, $order_id, "Coleta pedido cancelado #$order_id"]);
        }

        // Motorista - creditar na wallet BORAUM
        if ($pagarMotorista && $motoristaId && $valorMotorista > 0) {
            // Registro interno SuperBora
            $pdo->prepare("
                INSERT INTO om_motorista_ganhos (motorista_id, order_id, order_type, valor_bruto, percentual_comissao, valor_comissao, valor_liquido, status, processed_at)
                VALUES (?, ?, 'mercado', ?, 0, 0, ?, 'processado', NOW())
                ON DUPLICATE KEY UPDATE status = 'processado'
            ")->execute([$motoristaId, $order_id, floatval($pedido['delivery_fee'] ?? 0), $valorMotorista]);

            // Creditar na wallet real do BoraUm
            $stmt = $pdo->prepare("SELECT saldo FROM boraum_motorista_wallet WHERE motorista_id = ?");
            $stmt->execute([$motoristaId]);
            $walletRow = $stmt->fetch();
            $saldoAntes = $walletRow ? floatval($walletRow['saldo']) : 0;

            if (!$walletRow) {
                $pdo->prepare("INSERT INTO boraum_motorista_wallet (motorista_id, saldo, total_ganhos) VALUES (?, 0, 0)")->execute([$motoristaId]);
            }

            $saldoDepois = $saldoAntes + $valorMotorista;
            $pdo->prepare("
                UPDATE boraum_motorista_wallet SET saldo = saldo + ?, total_ganhos = total_ganhos + ? WHERE motorista_id = ?
            ")->execute([$valorMotorista, $valorMotorista, $motoristaId]);

            $pdo->prepare("
                INSERT INTO boraum_motorista_transacoes (motorista_id, tipo, valor, descricao, referencia_id, saldo_antes, saldo_depois, created_at)
                VALUES (?, 'ganho', ?, ?, ?, ?, ?, NOW())
            ")->execute([$motoristaId, $valorMotorista, "Pedido cancelado SuperBora #$order_id (ja coletou)", $order_id, $saldoAntes, $saldoDepois]);
        }

        // Restaurante
        if ($pagarRestaurante && $partnerId && $valorRestaurante > 0) {
            $pdo->prepare("
                INSERT INTO om_mercado_repasses (mercado_id, order_id, valor_produtos, valor_taxa_plataforma, valor_liquido, status)
                VALUES (?, ?, ?, 0, ?, 'pendente')
                ON DUPLICATE KEY UPDATE valor_liquido = VALUES(valor_liquido)
            ")->execute([$partnerId, $order_id, $valorRestaurante, $valorRestaurante]);

            $pdo->prepare("
                UPDATE om_market_partners SET saldo_disponivel = COALESCE(saldo_disponivel, 0) + ? WHERE partner_id = ?
            ")->execute([$valorRestaurante, $partnerId]);

            $stmt = $pdo->prepare("SELECT COALESCE(saldo_disponivel, 0) FROM om_market_partners WHERE partner_id = ?");
            $stmt->execute([$partnerId]);
            $saldoMercado = floatval($stmt->fetchColumn() ?: 0);

            $pdo->prepare("
                INSERT INTO om_market_wallet (partner_id, tipo, valor, saldo_anterior, saldo_posterior, referencia_tipo, referencia_id, descricao, status, created_at)
                VALUES (?, 'venda', ?, ?, ?, 'om_market_orders', ?, ?, 'liberado', NOW())
            ")->execute([$partnerId, $valorRestaurante, $saldoMercado - $valorRestaurante, $saldoMercado, $order_id, "Pedido cancelado (cliente desistiu apos preparo) #$order_id"]);
        }

        // Custo absorvido pela plataforma
        $custoPlataforma = $valorShopper + $valorMotorista + $valorRestaurante;

        // Registrar resumo financeiro
        $pdo->prepare("
            INSERT INTO om_pedido_financeiro
            (order_id, order_type, valor_produtos, valor_entrega, valor_servico, valor_total,
             valor_mercado, valor_shopper, valor_motorista, valor_plataforma, status, processed_at)
            VALUES (?, 'mercado', ?, ?, ?, ?, ?, ?, ?, ?, 'cancelado', NOW())
            ON DUPLICATE KEY UPDATE status = 'cancelado', processed_at = NOW()
        ")->execute([
            $order_id,
            $subtotal,
            floatval($pedido['delivery_fee'] ?? 0),
            floatval($pedido['service_fee'] ?? 0),
            floatval($pedido['total'] ?? 0),
            $valorRestaurante,
            $valorShopper,
            $valorMotorista,
            $taxaCancelamento - $custoPlataforma // lucro/prejuizo da plataforma
        ]);

        // Log evento
        $pdo->prepare("
            INSERT INTO om_market_order_events (order_id, event_type, message, created_by, created_at)
            VALUES (?, 'cancel_settlement', ?, 'system', NOW())
        ")->execute([$order_id, json_encode([
            'motivo' => $motivo,
            'cancelado_por' => $cancelado_por,
            'pagar_restaurante' => $pagarRestaurante,
            'pagar_shopper' => $pagarShopper,
            'pagar_motorista' => $pagarMotorista,
            'valor_restaurante' => $valorRestaurante,
            'valor_shopper' => $valorShopper,
            'valor_motorista' => $valorMotorista,
            'reembolso_cliente' => $valorReembolso,
            'taxa_cancelamento' => $taxaCancelamento,
        ], JSON_UNESCAPED_UNICODE)]);

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Cancelamento processado',
            'regras' => [
                'motivo' => $motivo,
                'cancelado_por' => $cancelado_por,
                'status_no_cancelamento' => $statusNoCancelamento,
            ],
            'pagamentos' => [
                'restaurante' => ['paga' => $pagarRestaurante, 'valor' => $valorRestaurante, 'motivo' => $pagarRestaurante ? 'Ja preparou o pedido' : 'Cancelado - sem pagamento'],
                'shopper' => ['paga' => $pagarShopper, 'valor' => $valorShopper, 'motivo' => $pagarShopper ? 'Ja coletou itens' : 'Sem trabalho realizado'],
                'motorista' => ['paga' => $pagarMotorista, 'valor' => $valorMotorista, 'motivo' => $pagarMotorista ? 'Ja coletou/entregando' : 'Sem trabalho realizado'],
                'cliente_reembolso' => $valorReembolso,
                'taxa_cancelamento' => $taxaCancelamento,
            ]
        ], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    error_log("[processar-cancelamento] Erro: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro ao processar cancelamento']);
}
