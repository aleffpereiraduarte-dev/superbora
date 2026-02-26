<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * POST /api/mercado/shopper/finalizar-entrega.php
 * Shopper entregou o pedido ao cliente
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * REQUER: Autenticacao de Shopper APROVADO pelo RH
 * Header: Authorization: Bearer <token>
 *
 * Body: {
 *   "order_id": 10,
 *   "codigo_confirmacao": "ABC123",  // codigo que cliente informa
 *   "foto_entrega": "base64..."      // opcional
 * }
 *
 * SISTEMA DE REPASSES COM HOLD:
 * - Ao finalizar entrega, os valores NAO sao creditados imediatamente
 * - Sao adicionados ao saldo_pendente
 * - Registros em om_repasses com status='hold' e hold_until = NOW() + 2 horas
 * - CRON libera automaticamente apos 2 horas
 * - Admin pode cancelar durante o periodo de hold
 *
 * SEGURANCA:
 * - Autenticacao obrigatoria
 * - Verificacao de ownership
 * - Validacao de codigo de entrega
 * - Validacao de transicao de estado
 * - Transacao atomica no banco
 * - Prepared statements
 */

require_once __DIR__ . "/../config/auth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/PushNotification.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmComissao.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmPricing.php";

try {
    $input = getInput();
    $db = getDB();

    // Autenticacao
    $auth = requireShopperAuth();
    $shopper_id = $auth['uid'];

    $order_id = (int)($input["order_id"] ?? 0);
    $codigo = trim($input["codigo_confirmacao"] ?? $input["codigo"] ?? "");
    $foto = $input["foto_entrega"] ?? null;

    if (!$order_id) {
        response(false, null, "order_id e obrigatorio", 400);
    }

    // Buscar configuracao de hold (before transaction, read-only config)
    $stmt = $db->prepare("SELECT valor FROM om_repasses_config WHERE chave = 'hold_horas'");
    $stmt->execute();
    $holdHoras = (int)($stmt->fetchColumn() ?: 2);

    // Iniciar transacao
    $db->beginTransaction();

    try {
        // Verificar pedido e ownership com lock (FOR UPDATE previne race condition)
        $stmt = $db->prepare("SELECT * FROM om_market_orders WHERE order_id = ? FOR UPDATE");
        $stmt->execute([$order_id]);
        $pedido = $stmt->fetch();

        if (!$pedido) {
            $db->rollBack();
            response(false, null, "Pedido nao encontrado", 404);
        }

        if ((int)$pedido['shopper_id'] !== (int)$shopper_id) {
            $db->rollBack();
            response(false, null, "Voce nao tem permissao para este pedido", 403);
        }

        // Validar transicao de estado
        try {
            om_status()->validateOrderTransition($pedido['status'], 'entregue');
        } catch (InvalidArgumentException $e) {
            $db->rollBack();
            error_log("[finalizar-entrega] Transicao invalida: " . $e->getMessage());
            response(false, null, 'Operacao nao permitida para o status atual do pedido', 409);
        }

        // Guardar status anterior para o WHERE atomico
        $statusAnterior = $pedido['status'];

        // Validar codigo de entrega (se existir)
        if ($pedido['codigo_entrega'] && strtoupper($codigo) !== strtoupper($pedido['codigo_entrega'])) {
            $db->rollBack();
            response(false, null, "Codigo de confirmacao invalido. Peca o codigo ao cliente.", 400);
        }

        // Calcular distribuicao usando classe unificada
        om_comissao()->setDb($db);
        $distribuicao = om_comissao()->calcularDistribuicao($pedido);

        // IDs dos destinatarios
        $motorista_id = (int)($pedido['delivery_driver_id'] ?? $pedido['driver_id'] ?? 0);
        $partner_id = (int)($pedido['partner_id'] ?? $pedido['market_id'] ?? 0);

        if (!$partner_id) {
            error_log("[finalizar-entrega] AVISO: partner_id ausente no pedido #$order_id - repasse do mercado sera ignorado");
        }

        // Valores calculados
        $valor_shopper = $distribuicao['shopper']['valor'] ?? 0;
        $valor_motorista = $distribuicao['motorista']['valor'] ?? 0;
        $valor_mercado = $distribuicao['mercado_recebe'] ?? 0;

        // ═══════════════════════════════════════════════════════════════════
        // ATUALIZAR STATUS DO PEDIDO (com WHERE atomico no status anterior)
        // ═══════════════════════════════════════════════════════════════════

        $stmt = $db->prepare("
            UPDATE om_market_orders SET
                status = 'entregue',
                delivered_at = NOW(),
                delivery_photo = ?
            WHERE order_id = ? AND status = ?
        ");
        $stmt->execute([$foto, $order_id, $statusAnterior]);

        if ($stmt->rowCount() === 0) {
            $db->rollBack();
            response(false, null, "Pedido ja foi atualizado por outra operacao", 409);
        }

        // Liberar shopper
        $stmt = $db->prepare("
            UPDATE om_market_shoppers SET
                disponivel = 1,
                pedido_atual_id = NULL,
                total_entregas = total_entregas + 1
            WHERE shopper_id = ?
        ");
        $stmt->execute([$shopper_id]);

        // ═══════════════════════════════════════════════════════════════════
        // CRIAR REPASSES COM HOLD (NAO CREDITA IMEDIATAMENTE)
        // ═══════════════════════════════════════════════════════════════════

        $holdUntil = date('Y-m-d H:i:s', strtotime("+{$holdHoras} hours"));
        $repasses = [];

        // 1. REPASSE SHOPPER
        if ($shopper_id && $valor_shopper > 0) {
            $calcShopper = $distribuicao['shopper']['calculo'] ?? [];

            $stmt = $db->prepare("
                INSERT INTO om_repasses
                (order_id, order_type, tipo, destinatario_id, valor_bruto, taxa_plataforma, valor_liquido, calculo_json, status, hold_until, created_at)
                VALUES (?, 'mercado', 'shopper', ?, ?, 0, ?, ?, 'hold', ?, NOW())
                ON CONFLICT (order_id, tipo) DO UPDATE SET
                    valor_bruto = EXCLUDED.valor_bruto,
                    valor_liquido = EXCLUDED.valor_liquido,
                    calculo_json = EXCLUDED.calculo_json,
                    hold_until = EXCLUDED.hold_until
            ");
            $stmt->execute([
                $order_id,
                $shopper_id,
                $valor_shopper,
                $valor_shopper,
                json_encode($calcShopper, JSON_UNESCAPED_UNICODE),
                $holdUntil
            ]);

            // Atualizar saldo_pendente do shopper
            $stmt = $db->prepare("
                INSERT INTO om_shopper_saldo (shopper_id, saldo_pendente, total_ganhos)
                VALUES (?, ?, ?)
                ON CONFLICT (shopper_id) DO UPDATE SET
                    saldo_pendente = om_shopper_saldo.saldo_pendente + ?,
                    total_ganhos = om_shopper_saldo.total_ganhos + ?
            ");
            $stmt->execute([$shopper_id, $valor_shopper, $valor_shopper, $valor_shopper, $valor_shopper]);

            $repasses['shopper'] = [
                'id' => $shopper_id,
                'valor' => $valor_shopper,
                'hold_until' => $holdUntil
            ];
        }

        // 2. REPASSE MOTORISTA
        if ($motorista_id && $valor_motorista > 0) {
            $calcMotorista = $distribuicao['motorista']['calculo'] ?? [];

            $stmt = $db->prepare("
                INSERT INTO om_repasses
                (order_id, order_type, tipo, destinatario_id, valor_bruto, taxa_plataforma, valor_liquido, calculo_json, status, hold_until, created_at)
                VALUES (?, 'mercado', 'motorista', ?, ?, ?, ?, ?, 'hold', ?, NOW())
                ON CONFLICT (order_id, tipo) DO UPDATE SET
                    valor_bruto = EXCLUDED.valor_bruto,
                    valor_liquido = EXCLUDED.valor_liquido,
                    calculo_json = EXCLUDED.calculo_json,
                    hold_until = EXCLUDED.hold_until
            ");
            $stmt->execute([
                $order_id,
                $motorista_id,
                $calcMotorista['taxa_entrega'] ?? $valor_motorista,
                $calcMotorista['comissao_plataforma'] ?? 0,
                $valor_motorista,
                json_encode($calcMotorista, JSON_UNESCAPED_UNICODE),
                $holdUntil
            ]);

            // Atualizar saldo_pendente do motorista
            $stmt = $db->prepare("
                INSERT INTO om_motorista_saldo (motorista_id, saldo_pendente, total_ganhos)
                VALUES (?, ?, ?)
                ON CONFLICT (motorista_id) DO UPDATE SET
                    saldo_pendente = om_motorista_saldo.saldo_pendente + ?,
                    total_ganhos = om_motorista_saldo.total_ganhos + ?
            ");
            $stmt->execute([$motorista_id, $valor_motorista, $valor_motorista, $valor_motorista, $valor_motorista]);

            $repasses['motorista'] = [
                'id' => $motorista_id,
                'valor' => $valor_motorista,
                'hold_until' => $holdUntil
            ];
        }

        // 3. REPASSE MERCADO (com comissao)
        // Determinar se usou BoraUm
        $stmtEntrega = $db->prepare("SELECT boraum_delivery_id FROM om_entregas WHERE referencia_id = ? AND origem_sistema = 'mercado' LIMIT 1");
        $stmtEntrega->execute([$order_id]);
        $entregaRow = $stmtEntrega->fetch();
        $usaBoraUm = !empty($entregaRow['boraum_delivery_id']);

        // Comissao via OmPricing (fonte unica: 18% BoraUm, 10% proprio)
        $subtotalPedido = floatval($pedido['subtotal'] ?? 0);
        $deliveryFeePedido = floatval($pedido['delivery_fee'] ?? 0);
        $comissaoPct = $usaBoraUm ? OmPricing::COMISSAO_BORAUM : OmPricing::COMISSAO_PROPRIO;
        $comissaoValor = round($subtotalPedido * $comissaoPct, 2);
        $valor_mercado_final = round($subtotalPedido - $comissaoValor, 2);

        // Se entregador proprio, parceiro tambem recebe a taxa de entrega
        if (!$usaBoraUm && $deliveryFeePedido > 0) {
            $valor_mercado_final += $deliveryFeePedido;
        }

        if ($partner_id && $valor_mercado_final > 0) {
            $stmt = $db->prepare("
                INSERT INTO om_repasses
                (order_id, order_type, tipo, destinatario_id, valor_bruto, taxa_plataforma, valor_liquido, calculo_json, status, hold_until, created_at)
                VALUES (?, 'mercado', 'mercado', ?, ?, ?, ?, ?, 'hold', ?, NOW())
                ON CONFLICT (order_id, order_type, tipo, destinatario_id) DO UPDATE SET
                    valor_bruto = EXCLUDED.valor_bruto,
                    taxa_plataforma = EXCLUDED.taxa_plataforma,
                    valor_liquido = EXCLUDED.valor_liquido,
                    calculo_json = EXCLUDED.calculo_json,
                    hold_until = EXCLUDED.hold_until
            ");
            $calcJson = [
                'subtotal' => $subtotalPedido,
                'comissao_pct' => $comissaoPct * 100,
                'comissao_valor' => $comissaoValor,
                'delivery_fee' => $deliveryFeePedido,
                'delivery_fee_destino' => $usaBoraUm ? 'boraum' : 'parceiro',
                'tier' => $usaBoraUm ? 'boraum_18pct' : 'proprio_10pct',
            ];
            $stmt->execute([
                $order_id,
                $partner_id,
                $subtotalPedido,
                $comissaoValor,
                $valor_mercado_final,
                json_encode($calcJson, JSON_UNESCAPED_UNICODE),
                $holdUntil
            ]);

            // Atualizar saldo_pendente do mercado
            $stmt = $db->prepare("
                INSERT INTO om_mercado_saldo (partner_id, saldo_pendente, total_recebido)
                VALUES (?, ?, ?)
                ON CONFLICT (partner_id) DO UPDATE SET
                    saldo_pendente = om_mercado_saldo.saldo_pendente + ?,
                    total_recebido = om_mercado_saldo.total_recebido + ?
            ");
            $stmt->execute([$partner_id, $valor_mercado_final, $valor_mercado_final, $valor_mercado_final, $valor_mercado_final]);

            // Salvar comissao no pedido
            $db->prepare("UPDATE om_market_orders SET commission_rate = ?, commission_amount = ?, repasse_valor = ? WHERE order_id = ?")
               ->execute([$comissaoPct * 100, $comissaoValor, $valor_mercado_final, $order_id]);

            $repasses['mercado'] = [
                'id' => $partner_id,
                'valor' => $valor_mercado_final,
                'comissao_pct' => $comissaoPct * 100,
                'comissao_valor' => $comissaoValor,
                'hold_until' => $holdUntil
            ];
        }

        // ═══════════════════════════════════════════════════════════════════
        // REGISTRAR RESUMO FINANCEIRO
        // ═══════════════════════════════════════════════════════════════════

        $stmt = $db->prepare("
            INSERT INTO om_pedido_financeiro
            (order_id, order_type, valor_produtos, valor_entrega, valor_servico, valor_total,
             valor_mercado, valor_shopper, valor_motorista, valor_plataforma, status, processed_at)
            VALUES (?, 'mercado', ?, ?, 0, ?, ?, ?, ?, ?, 'processado', NOW())
            ON CONFLICT (order_id, order_type) DO UPDATE SET status = 'processado', processed_at = NOW()
        ");
        $stmt->execute([
            $order_id,
            $distribuicao['cliente_pagou']['produtos'] ?? 0,
            $distribuicao['cliente_pagou']['entrega'] ?? 0,
            $distribuicao['cliente_pagou']['total'] ?? 0,
            $valor_mercado,
            $valor_shopper,
            $valor_motorista,
            $distribuicao['plataforma']['total'] ?? 0
        ]);

        $db->commit();

        // Log
        logAudit('update', 'order', $order_id,
            ['status' => $pedido['status']],
            ['status' => 'entregue', 'repasses' => $repasses],
            "Entrega finalizada por shopper #$shopper_id - Repasses em hold por {$holdHoras}h"
        );

        // ═══════════════════════════════════════════════════════════════════
        // NOTIFICACOES EM TEMPO REAL (SSE)
        // ═══════════════════════════════════════════════════════════════════
        if (function_exists('om_realtime')) {
            om_realtime()->entregaFinalizada(
                $order_id,
                $partner_id,
                (int)$pedido['customer_id'],
                $shopper_id
            );
        }

        // ═══════════════════════════════════════════════════════════════════
        // CASHBACK - liberar cashback pendente para o cliente
        // ═══════════════════════════════════════════════════════════════════
        try {
            $cid = (int)$pedido['customer_id'];
            if ($cid) {
                $stmt = $db->prepare("
                    UPDATE om_cashback
                    SET status = 'available'
                    WHERE customer_id = ? AND order_id = ? AND status = 'pending'
                ");
                $stmt->execute([$cid, $order_id]);
            }
        } catch (Exception $cbErr) {
            error_log("[finalizar-entrega] Cashback error: " . $cbErr->getMessage());
        }

        // ═══════════════════════════════════════════════════════════════════
        // PUSH NOTIFICATIONS (FCM)
        // ═══════════════════════════════════════════════════════════════════
        try {
            PushNotification::getInstance()->setDb($db);

            // Notifica cliente
            om_push()->send(
                PushNotification::USER_TYPE_CUSTOMER,
                (int)$pedido['customer_id'],
                'Pedido Entregue!',
                'Seu pedido foi entregue com sucesso. Obrigado por usar o OneMundo!',
                ['type' => 'delivery_completed', 'order_id' => $order_id],
                '/avaliar-pedido/' . $order_id
            );

            // Notifica mercado
            om_push()->send(
                PushNotification::USER_TYPE_PARTNER,
                $partner_id,
                'Pedido Entregue',
                "O pedido #{$order_id} foi entregue com sucesso.",
                ['type' => 'delivery_completed', 'order_id' => $order_id],
                '/painel/pedidos/' . $order_id
            );

            // Notifica shopper sobre o pagamento em hold
            om_push()->send(
                PushNotification::USER_TYPE_SHOPPER,
                $shopper_id,
                'Entrega Concluida!',
                sprintf('Parabens! R$ %.2f sera liberado em %dh na sua carteira.', $valor_shopper, $holdHoras),
                [
                    'type' => 'payment_pending',
                    'order_id' => $order_id,
                    'valor' => $valor_shopper,
                    'hold_until' => $holdUntil
                ],
                '/shopper/saldo'
            );

            // Notifica motorista se houver
            if ($motorista_id && $valor_motorista > 0) {
                om_push()->send(
                    PushNotification::USER_TYPE_MOTORISTA,
                    $motorista_id,
                    'Entrega Concluida!',
                    sprintf('Parabens! R$ %.2f sera liberado em %dh na sua carteira.', $valor_motorista, $holdHoras),
                    [
                        'type' => 'payment_pending',
                        'order_id' => $order_id,
                        'valor' => $valor_motorista,
                        'hold_until' => $holdUntil
                    ],
                    '/motorista/saldo'
                );
            }

        } catch (Exception $pushError) {
            // Log but don't fail the request
            error_log("[finalizar-entrega] Push notification error: " . $pushError->getMessage());
        }

        // ═══════════════════════════════════════════════════════════════════
        // RESPOSTA
        // ═══════════════════════════════════════════════════════════════════

        $totalRepasses = $valor_shopper + $valor_motorista + $valor_mercado;

        response(true, [
            "order_id" => $order_id,
            "status" => "delivered",
            "entregue_em" => date('c'),
            "repasses" => [
                "shopper" => [
                    "valor" => $valor_shopper,
                    "status" => "hold",
                    "libera_em" => $holdUntil
                ],
                "motorista" => $motorista_id ? [
                    "valor" => $valor_motorista,
                    "status" => "hold",
                    "libera_em" => $holdUntil
                ] : null,
                "mercado" => [
                    "valor" => $valor_mercado,
                    "status" => "hold",
                    "libera_em" => $holdUntil
                ]
            ],
            "hold_info" => [
                "horas" => $holdHoras,
                "libera_em" => $holdUntil,
                "motivo" => "Periodo de seguranca para garantir qualidade da entrega"
            ],
            "mensagem" => sprintf(
                "Entrega concluida! Seu pagamento de R$ %.2f sera liberado em %d horas.",
                $valor_shopper,
                $holdHoras
            )
        ], "Entrega finalizada com sucesso!");

    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    error_log("[finalizar-entrega] Erro: " . $e->getMessage());
    response(false, null, "Erro ao finalizar entrega. Tente novamente.", 500);
}
