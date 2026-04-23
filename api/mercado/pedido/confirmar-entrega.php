<?php
/**
 * CONFIRMAR ENTREGA DO PEDIDO
 *
 * Chamado quando:
 * 1. Cliente confirma recebimento no app
 * 2. Motorista finaliza entrega com codigo de confirmacao
 *
 * O que faz:
 * 1. Marca pedido como entregue
 * 2. Registra timestamp da confirmacao
 * 3. Adiciona valor como PENDENTE na wallet do mercado
 * 4. Apos 2 HORAS, cron libera o valor para disponivel
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/notify.php';
require_once __DIR__ . '/../helpers/ws-customer-broadcast.php';
require_once __DIR__ . '/../helpers/zapi-whatsapp.php';
require_once dirname(__DIR__, 3) . '/includes/classes/OmAuth.php';

setCorsHeaders();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    response(false, null, "Método não permitido", 405);
}

$input = getInput();

$order_id = (int)($input['order_id'] ?? 0);
$codigo_confirmacao = trim($input['codigo'] ?? '');
$foto_entrega = trim($input['foto'] ?? '');
$confirmado_por = $input['confirmado_por'] ?? 'cliente';

// SECURITY: Whitelist confirmado_por to prevent auth bypass
$confirmadoresPermitidos = ['cliente', 'motorista', 'shopper'];
if (!in_array($confirmado_por, $confirmadoresPermitidos, true)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "confirmado_por inválido"]);
    exit;
}

try {
    $db = getDB();

    if (!$order_id) {
        response(false, null, "order_id obrigatório", 400);
    }

    // Autenticacao obrigatoria para todos os tipos
    OmAuth::getInstance()->setDb($db);
    $token = om_auth()->getTokenFromRequest();
    if (!$token) {
        response(false, null, "Autenticação obrigatória", 401);
    }
    $authPayload = om_auth()->validateToken($token);
    if (!$authPayload) {
        response(false, null, "Token inválido ou expirado", 401);
    }
    $authType = $authPayload['type'] ?? '';
    $authUid = (int)($authPayload['uid'] ?? 0);

    if ($confirmado_por === 'cliente') {
        // Cliente: must be a customer token — reject non-customer types
        if ($authType !== 'customer') {
            response(false, null, "Token inválido para confirmação de cliente", 403);
        }
        // Use $authUid from OmAuth (already validated with revocation check)
        $auth_customer_id = $authUid;
        if (!$auth_customer_id) {
            response(false, null, "Autenticação obrigatória", 401);
        }
    } else {
        // Motorista/shopper: require token auth + codigo de confirmacao
        if (!in_array($authType, [OmAuth::USER_TYPE_SHOPPER, OmAuth::USER_TYPE_MOTORISTA, OmAuth::USER_TYPE_PARTNER], true)) {
            response(false, null, "Autenticação de shopper ou parceiro obrigatória", 401);
        }
        if (empty($codigo_confirmacao)) {
            response(false, null, "Código de confirmação obrigatório para $confirmado_por", 400);
        }
    }

    // Begin transaction early to use FOR UPDATE lock
    $db->beginTransaction();

    // Buscar pedido com lock para evitar confirmacao dupla
    $stmt = $db->prepare("
        SELECT o.*, p.name as mercado_nome
        FROM om_market_orders o
        INNER JOIN om_market_partners p ON o.partner_id = p.partner_id
        WHERE o.order_id = ?
        FOR UPDATE OF o
    ");
    $stmt->execute([$order_id]);
    $pedido = $stmt->fetch();

    if (!$pedido) {
        $db->rollBack();
        response(false, null, "Pedido não encontrado", 404);
    }

    // Verificar ownership se cliente
    if ($confirmado_por === 'cliente' && isset($auth_customer_id)) {
        if ((int)$pedido['customer_id'] !== $auth_customer_id) {
            $db->rollBack();
            response(false, null, "Pedido não encontrado", 404);
        }
    }

    // Verificar ownership se motorista/shopper
    if (in_array($confirmado_por, ['motorista', 'shopper'], true)) {
        $ownershipOk = false;
        if ($authType === OmAuth::USER_TYPE_SHOPPER && (int)($pedido['shopper_id'] ?? 0) === $authUid) {
            $ownershipOk = true;
        }
        if ($authType === OmAuth::USER_TYPE_MOTORISTA && (int)($pedido['motorista_id'] ?? 0) === $authUid) {
            $ownershipOk = true;
        }
        if ($authType === OmAuth::USER_TYPE_PARTNER && (int)($pedido['partner_id'] ?? 0) === $authUid) {
            $ownershipOk = true;
        }
        if (!$ownershipOk) {
            $db->rollBack();
            error_log("[confirmar-entrega] SECURITY: Auth uid={$authUid} type={$authType} nao relacionado ao pedido #{$order_id}");
            response(false, null, "Não autorizado para este pedido", 403);
        }
    }

    if (in_array($pedido['status'], ['entregue', 'retirado'])) {
        $db->rollBack();
        response(false, null, "Pedido já foi " . ($pedido['status'] === 'retirado' ? 'retirado' : 'entregue'), 400);
    }

    $isPickupOrder = !empty($pedido['is_pickup']) || ($pedido['delivery_type'] ?? '') === 'retirada';

    if (!in_array($pedido['status'], ['em_entrega', 'coletando', 'pronto'])) {
        $db->rollBack();
        response(false, null, "Pedido não esta em entrega", 400);
    }

    // SECURITY: Verify payment before allowing delivery confirmation
    // Cash/dinheiro orders are exempt (paid on delivery)
    $paymentMethod = $pedido['payment_method'] ?? $pedido['forma_pagamento'] ?? '';
    $isCashPayment = in_array($paymentMethod, ['dinheiro', 'cartao_entrega']);
    if (!$isCashPayment) {
        $pagStatus = $pedido['pagamento_status'] ?? $pedido['payment_status'] ?? '';
        if (!in_array($pagStatus, ['paid', 'pago', 'confirmado'])) {
            $db->rollBack();
            error_log("[confirmar-entrega] SECURITY: Pedido #{$order_id} pagamento nao confirmado (status={$pagStatus}, method={$paymentMethod})");
            response(false, null, "Pagamento não confirmado para este pedido", 400);
        }
    }

    // SECURITY: Customer cannot confirm DELIVERY on 'pronto' orders (must be em_entrega)
    // EXCEPTION: Pickup orders — customer confirms PICKUP when status is 'pronto'
    if ($confirmado_por === 'cliente' && $pedido['status'] === 'pronto' && !$isPickupOrder) {
        $db->rollBack();
        response(false, null, "Pedido ainda não saiu para entrega", 400);
    }

    // SECURITY: For non-pickup orders in 'pronto' status, motorista must first transition
    // to 'em_entrega' before finalizing to 'entregue'. Cannot skip the em_entrega step.
    if ($confirmado_por === 'motorista' && $pedido['status'] === 'pronto' && !$isPickupOrder) {
        // Transition to em_entrega instead of finalizing
        $stmtTransit = $db->prepare("
            UPDATE om_market_orders
            SET status = 'em_entrega', date_modified = NOW()
            WHERE order_id = ? AND status = 'pronto'
        ");
        $stmtTransit->execute([$order_id]);
        if ($stmtTransit->rowCount() === 0) {
            $db->rollBack();
            response(false, null, "Status do pedido foi alterado por outra operação", 409);
        }
        $db->commit();
        response(true, [
            "order_id" => $order_id,
            "status" => "em_entrega",
            "mensagem" => "Pedido marcado como em entrega. Confirme a entrega novamente ao finalizar."
        ]);
    }

    // Validar codigo de confirmacao para motorista/shopper
    if ($confirmado_por !== 'cliente') {
        $storedCode = $pedido['codigo_entrega'] ?? $pedido['confirmation_code'] ?? '';
        if (empty($storedCode)) {
            error_log("[confirmar-entrega] SECURITY: Pedido #$order_id sem codigo de confirmacao - rejeitando $confirmado_por");
            $db->rollBack();
            response(false, null, "Pedido sem código de confirmação configurado", 400);
        }
        $trimmedInput = trim($codigo_confirmacao);
        if (strlen($trimmedInput) < 4) {
            $db->rollBack();
            response(false, null, "Código de confirmação inválido", 400);
        }
        if (strtoupper($trimmedInput) !== strtoupper(trim($storedCode))) {
            $db->rollBack();
            response(false, null, "Código de confirmação inválido", 400);
        }
    }

    try {
        // 1. Atualizar pedido (pickup usa status 'retirado', entrega usa 'entregue')
        $finalStatus = $isPickupOrder ? 'retirado' : 'entregue';
        $stmt = $db->prepare("
            UPDATE om_market_orders
            SET status = ?,
                delivery_confirmed_at = NOW(),
                delivery_photo = ?,
                confirmed_by = ?,
                date_modified = NOW()
            WHERE order_id = ? AND status IN ('em_entrega', 'coletando', 'pronto')
        ");
        $stmt->execute([$finalStatus, $foto_entrega, $confirmado_por, $order_id]);
        if ($stmt->rowCount() === 0) {
            $db->rollBack();
            response(false, null, "Pedido já foi confirmado ou status foi alterado", 409);
        }

        // 2. Verificar se repasse ja existe (idempotencia — webhook pode ter criado)
        require_once dirname(__DIR__, 3) . '/includes/classes/OmPricing.php';
        require_once dirname(__DIR__, 3) . '/includes/classes/OmDailyBudget.php';
        require_once dirname(__DIR__, 3) . '/includes/classes/OmRepasse.php';

        $stmtRepasseCheck = $db->prepare("SELECT id FROM om_repasses WHERE order_id = ? AND order_type = 'mercado' LIMIT 1 FOR UPDATE");
        $stmtRepasseCheck->execute([$order_id]);
        $repasseJaExiste = (bool)$stmtRepasseCheck->fetch();

        $subtotal = floatval($pedido['subtotal']);
        $deliveryFee = floatval($pedido['delivery_fee'] ?? 0);
        $expressFee = floatval($pedido['express_fee'] ?? 0);
        $partnerId = (int)$pedido['partner_id'];
        $paymentMethod = $pedido['payment_method'] ?? $pedido['forma_pagamento'] ?? 'pix';
        $isCashOrder = in_array($paymentMethod, ['dinheiro', 'cartao_entrega']);

        // Determinar se usou BoraUm: checar om_entregas
        $stmtEntrega = $db->prepare("SELECT boraum_delivery_id, distancia_km FROM om_entregas WHERE referencia_id = ? AND origem_sistema = 'mercado' LIMIT 1");
        $stmtEntrega->execute([$order_id]);
        $entregaRow = $stmtEntrega->fetch();
        $usaBoraUm = !empty($entregaRow['boraum_delivery_id']);
        $distanciaKm = floatval($entregaRow['distancia_km'] ?? 3);

        // Comissao centralizada via OmPricing (pickup=8%, proprio=10%, boraum=18%)
        $tipoComissao = $isPickupOrder ? 'pickup' : ($usaBoraUm ? 'boraum' : 'proprio');
        $comissao = OmPricing::calcularComissao($subtotal, $tipoComissao);
        $comissaoPct = $comissao['taxa'];
        $comissaoValor = $comissao['valor'];
        $valor_repasse = round($subtotal - $comissaoValor, 2);

        // Se entregador proprio ou retirada, parceiro recebe a taxa de entrega BASE (sem express)
        // express_fee e receita da plataforma, nao vai pro parceiro
        $deliveryFeeBase = max(0, $deliveryFee - $expressFee);
        if (!$usaBoraUm && $deliveryFeeBase > 0) {
            $valor_repasse += $deliveryFeeBase;
        }

        // SECURITY: Ensure repasse value is non-negative
        if ($valor_repasse < 0) {
            error_log("[confirmar-entrega] SECURITY: valor_repasse negativo (R\${$valor_repasse}) para pedido #{$order_id} — forçando 0");
            $valor_repasse = 0;
        }

        if ($repasseJaExiste) {
            error_log("[confirmar-entrega] Repasse ja existe para pedido #{$order_id} — pulando financeiro (idempotencia)");
        } elseif ($isCashOrder) {
            // ═══════════════════════════════════════════════════════
            // PEDIDO CASH: Parceiro ja recebeu 100% na mao.
            // Debitar comissao da wallet do parceiro.
            // ═══════════════════════════════════════════════════════
            $stmtSaldo = $db->prepare("
                SELECT saldo_disponivel, saldo_devedor
                FROM om_mercado_saldo WHERE partner_id = ?
                FOR UPDATE
            ");
            $stmtSaldo->execute([$partnerId]);
            $saldoRow = $stmtSaldo->fetch();
            $saldoAtual = (float)($saldoRow['saldo_disponivel'] ?? 0);

            if ($saldoAtual >= $comissaoValor) {
                $db->prepare("UPDATE om_mercado_saldo SET saldo_disponivel = saldo_disponivel - ? WHERE partner_id = ?")
                   ->execute([$comissaoValor, $partnerId]);
            } else {
                $debitoDoSaldo = max(0, $saldoAtual);
                $restante = round($comissaoValor - $debitoDoSaldo, 2);
                if ($debitoDoSaldo > 0) {
                    $db->prepare("UPDATE om_mercado_saldo SET saldo_disponivel = 0 WHERE partner_id = ?")
                       ->execute([$partnerId]);
                }
                if ($restante > 0) {
                    $db->prepare("
                        INSERT INTO om_mercado_saldo (partner_id, saldo_devedor)
                        VALUES (?, ?)
                        ON CONFLICT (partner_id) DO UPDATE SET saldo_devedor = om_mercado_saldo.saldo_devedor + EXCLUDED.saldo_devedor
                    ")->execute([$partnerId, $restante]);
                }
            }

            // Registrar debito na wallet
            try {
                $db->prepare("
                    INSERT INTO om_mercado_wallet (partner_id, tipo, valor, descricao, referencia_tipo, referencia_id, status, created_at)
                    VALUES (?, 'taxa', ?, ?, 'om_market_orders', ?, 'concluido', NOW())
                ")->execute([$partnerId, -$comissaoValor, "Comissao pedido #{$order_id} ({$paymentMethod})", $order_id]);
            } catch (Exception $wErr) {
                error_log("[confirmar-entrega] Wallet log erro: " . $wErr->getMessage());
            }

            // Salvar comissao no pedido (sem repasse - parceiro ja recebeu cash)
            $stmt = $db->prepare("UPDATE om_market_orders SET commission_rate = ?, commission_amount = ?, repasse_valor = 0 WHERE order_id = ?");
            $stmt->execute([$comissaoPct * 100, $comissaoValor, $order_id]);

            $serviceFee = floatval($pedido['service_fee'] ?? 0);
            $receitaPlataforma = round($comissaoValor + $serviceFee + $expressFee, 2);
            error_log("[confirmar-entrega] Pedido #{$order_id} CASH: comissao R\${$comissaoValor} debitada do parceiro #{$partnerId} | receita_total=R\${$receitaPlataforma}");
        } else {
            // ═══════════════════════════════════════════════════════
            // PEDIDO ONLINE: Criar repasse normal com hold de 2h
            // ═══════════════════════════════════════════════════════
            $serviceFee = floatval($pedido['service_fee'] ?? 0);

            $repasseResult = om_repasse()->setDb($db)->criar(
                $order_id,
                'mercado',
                $partnerId,
                $valor_repasse,
                [
                    'subtotal' => $subtotal,
                    'comissao_pct' => $comissaoPct * 100,
                    'comissao_valor' => $comissaoValor,
                    'delivery_fee' => $deliveryFee,
                    'delivery_fee_base' => $deliveryFeeBase,
                    'express_fee' => $expressFee,
                    'service_fee' => $serviceFee,
                    'delivery_fee_destino' => $usaBoraUm ? 'boraum' : 'parceiro',
                    'is_pickup' => $isPickupOrder,
                    'tier' => $usaBoraUm ? 'boraum_18pct' : 'proprio_10pct',
                    'receita_plataforma' => round($comissaoValor + $serviceFee + $expressFee, 2),
                ]
            );

            // Salvar comissao e repasse no pedido
            $stmt = $db->prepare("UPDATE om_market_orders SET repasse_valor = ?, commission_rate = ?, commission_amount = ? WHERE order_id = ?");
            $stmt->execute([$valor_repasse, $comissaoPct * 100, $comissaoValor, $order_id]);
        }

        $db->commit();

        // WebSocket broadcast (never breaks the flow)
        try {
            $customer_id_ws = (int)($pedido['customer_id'] ?? 0);
            if ($customer_id_ws) {
                wsBroadcastToCustomer($customer_id_ws, 'order_update', [
                    'order_id' => $order_id,
                    'status' => $finalStatus,
                    'previous_status' => $pedido['status'],
                ]);
            }
            wsBroadcastToOrder($order_id, 'order_update', [
                'order_id' => $order_id,
                'status' => $finalStatus,
            ]);
            $pid_ws = (int)($pedido['partner_id'] ?? 0);
            if ($pid_ws && function_exists('wsBroadcastToPartner')) {
                wsBroadcastToPartner($pid_ws, 'order_update', [
                    'order_id' => $order_id, 'status' => $finalStatus,
                    'previous_status' => $pedido['status'], 'customer_id' => $customer_id_ws,
                ]);
            }
            if (function_exists('wsBroadcastToAdmin')) {
                wsBroadcastToAdmin('order_update', [
                    'order_id' => $order_id, 'partner_id' => $pid_ws, 'status' => $finalStatus,
                    'previous_status' => $pedido['status'], 'customer_id' => $customer_id_ws,
                ]);
            }
        } catch (\Throwable $e) {}

        // ═══════════════════════════════════════════════════════
        // P&L DIARIO — registrar dados financeiros
        // ═══════════════════════════════════════════════════════
        try {
            $custoBoraUmReal = $usaBoraUm ? OmPricing::calcularCustoBoraUm($distanciaKm) : 0;
            $serviceFeeVal = floatval($pedido['service_fee'] ?? 0);
            OmDailyBudget::getInstance()->setDb($db)->registrarPedido([
                'subtotal' => $subtotal,
                'comissao_valor' => $comissaoValor,
                'service_fee' => $serviceFeeVal,
                'margem_frete' => max(0, $deliveryFee - $custoBoraUmReal),
                'express_fee' => $expressFee,
                'custo_boraum' => $custoBoraUmReal,
                'subsidio' => 0,
                'cashback_valor' => 0,
            ]);
        } catch (Exception $pnlErr) {
            error_log("[confirmar-entrega] P&L error: " . $pnlErr->getMessage());
        }

        // ═══════════════════════════════════════════════════════
        // GORJETA - repassar ao entregador ou parceiro
        // (own transaction to ensure saldo+wallet are atomic)
        // ═══════════════════════════════════════════════════════
        $tipAmount = floatval($pedido['tip_amount'] ?? 0);
        if ($tipAmount > 0) {
            try {
                $db->beginTransaction();
                if ($usaBoraUm) {
                    // BoraUm: registrar gorjeta como bonus ao motorista
                    $db->prepare("
                        INSERT INTO om_mercado_wallet (partner_id, tipo, valor, descricao, referencia_tipo, referencia_id, status, created_at)
                        VALUES (?, 'gorjeta_driver', ?, ?, 'om_market_orders', ?, 'concluido', NOW())
                    ")->execute([$partnerId, -$tipAmount, "Gorjeta repassada ao entregador - Pedido #{$order_id}", $order_id]);

                    error_log("[confirmar-entrega] Gorjeta R\${$tipAmount} pedido #{$order_id} → entregador BoraUm");
                } else {
                    // Entrega propria ou retirada: gorjeta vai pro parceiro
                    $db->prepare("
                        INSERT INTO om_mercado_saldo (partner_id, saldo_disponivel)
                        VALUES (?, ?)
                        ON CONFLICT (partner_id) DO UPDATE SET saldo_disponivel = om_mercado_saldo.saldo_disponivel + EXCLUDED.saldo_disponivel
                    ")->execute([$partnerId, $tipAmount]);

                    $db->prepare("
                        INSERT INTO om_mercado_wallet (partner_id, tipo, valor, descricao, referencia_tipo, referencia_id, status, created_at)
                        VALUES (?, 'gorjeta', ?, ?, 'om_market_orders', ?, 'concluido', NOW())
                    ")->execute([$partnerId, $tipAmount, "Gorjeta pedido #{$order_id}", $order_id]);

                    error_log("[confirmar-entrega] Gorjeta R\${$tipAmount} pedido #{$order_id} → parceiro #{$partnerId}");
                }
                $db->commit();
            } catch (Exception $tipErr) {
                if ($db->inTransaction()) $db->rollBack();
                error_log("[confirmar-entrega] Erro gorjeta: " . $tipErr->getMessage());
            }
        }

        // ═══════════════════════════════════════════════════════
        // NOTIFICACOES PUSH (apos commit)
        // ═══════════════════════════════════════════════════════
        try {
            $customer_id = (int)($pedido['customer_id'] ?? 0);
            $partner_id = (int)($pedido['partner_id'] ?? 0);

            if ($customer_id) {
                if ($isPickupOrder) {
                    notifyCustomer($db, $customer_id,
                        'Retirada confirmada!',
                        sprintf('Seu pedido #%d foi retirado com sucesso. Obrigado por comprar no %s!', $order_id, $pedido['mercado_nome'] ?? 'SuperBora'),
                        '/mercado/vitrine/pedidos/' . $order_id
                    );
                } else {
                    notifyCustomer($db, $customer_id,
                        'Pedido entregue!',
                        sprintf('Seu pedido #%d foi entregue. Obrigado por comprar no %s!', $order_id, $pedido['mercado_nome'] ?? 'SuperBora'),
                        '/mercado/vitrine/pedidos/' . $order_id
                    );
                }
            }
            if ($partner_id) {
                notifyPartner($db, $partner_id,
                    $isPickupOrder ? 'Retirada confirmada' : 'Entrega confirmada',
                    sprintf('Pedido #%d %s com sucesso.', $order_id, $isPickupOrder ? 'retirado' : 'entregue'),
                    '/painel/mercado/pedidos.php'
                );
            }
        } catch (Exception $pushErr) {
            error_log("[confirmar-entrega] Push error: " . $pushErr->getMessage());
        }

        // ═══════════════════════════════════════════════════════
        // WHATSAPP - notificar cliente da entrega
        // ═══════════════════════════════════════════════════════
        try {
            $customerPhone = $pedido['customer_phone'] ?? '';
            if ($customerPhone) {
                $deliveredPartnerName = $pedido['mercado_nome'] ?? $pedido['partner_name'] ?? '';
                $waResult = whatsappOrderDelivered($customerPhone, $pedido['order_number'], $deliveredPartnerName);
                error_log("[confirmar-entrega] WhatsApp pedido #{$pedido['order_number']} phone=****" . substr($customerPhone, -4) . " success=" . ($waResult['success'] ? 'yes' : 'no'));

                // Send rating request after delivery notification (slight delay)
                if ($waResult['success']) {
                    usleep(500000); // 0.5s delay between messages
                    $partnerName = $pedido['mercado_nome'] ?? $pedido['partner_name'] ?? 'a loja';
                    $ratingResult = whatsappAskRating($customerPhone, $pedido['order_number'], $partnerName);
                    error_log("[confirmar-entrega] Rating request pedido #{$pedido['order_number']} success=" . ($ratingResult['success'] ? 'yes' : 'no'));
                }
            }
        } catch (\Throwable $waErr) {
            error_log("[confirmar-entrega] WhatsApp error: " . $waErr->getMessage());
        }

        // Proactive WhatsApp: log to conversation (message already sent above)
        try {
            require_once __DIR__ . '/../helpers/whatsapp-order-updates.php';
            sendOrderStatusWhatsApp($db, $order_id, $finalStatus, true);
        } catch (\Throwable $e) {
            error_log("[confirmar-entrega] Proactive WA update error: " . $e->getMessage());
        }

        // ═══════════════════════════════════════════════════════
        // CASHBACK - liberar cashback pendente
        // ═══════════════════════════════════════════════════════
        try {
            $customer_id = (int)($pedido['customer_id'] ?? 0);
            if ($customer_id) {
                // 1. Mudar status de 'pending' para 'available' em om_cashback (legacy)
                $stmtCb = $db->prepare("
                    SELECT amount FROM om_cashback
                    WHERE customer_id = ? AND order_id = ? AND status = 'pending'
                ");
                $stmtCb->execute([$customer_id, $order_id]);
                $cbRow = $stmtCb->fetch();

                if ($cbRow) {
                    $cbAmount = (float)$cbRow['amount'];
                    $db->prepare("
                        UPDATE om_cashback SET status = 'available'
                        WHERE customer_id = ? AND order_id = ? AND status = 'pending'
                    ")->execute([$customer_id, $order_id]);

                    // 2. Also credit to om_cashback_wallet (new system) for balance tracking
                    // SECURITY: Idempotency — check if already credited to wallet for this order
                    $stmtCbWalletCheck = $db->prepare("SELECT 1 FROM om_cashback_transactions WHERE order_id = ? AND type = 'credit' AND expired = 0 LIMIT 1");
                    $stmtCbWalletCheck->execute([$order_id]);
                    $alreadyCredited = $stmtCbWalletCheck->fetch();

                    if (!$alreadyCredited) {
                        $db->prepare("
                            INSERT INTO om_cashback_wallet (customer_id, balance, total_earned)
                            VALUES (?, ?, ?)
                            ON CONFLICT (customer_id) DO UPDATE SET
                                balance = om_cashback_wallet.balance + EXCLUDED.balance,
                                total_earned = om_cashback_wallet.total_earned + EXCLUDED.total_earned
                        ")->execute([$customer_id, $cbAmount, $cbAmount]);

                        error_log("[confirmar-entrega] Cashback R\${$cbAmount} liberado para customer #{$customer_id} pedido #{$order_id}");
                    } else {
                        error_log("[confirmar-entrega] Cashback wallet already credited for order #{$order_id} — skipping (idempotency)");
                    }
                }
            }
        } catch (Exception $cbErr) {
            error_log("[confirmar-entrega] Cashback error: " . $cbErr->getMessage());
        }

        // ═══════════════════════════════════════════════════════
        // PONTOS DE FIDELIDADE - acumular ao entregar
        // ═══════════════════════════════════════════════════════
        try {
            $customer_id = (int)($pedido['customer_id'] ?? 0);
            if ($customer_id && $subtotal > 0) {
                // SECURITY: Idempotency — check if loyalty already credited for this order
                $stmtLoyCheck = $db->prepare("SELECT id FROM om_market_loyalty_transactions WHERE reference_id = ? AND type = 'earned' AND source = 'order_delivered' LIMIT 1");
                $stmtLoyCheck->execute([$order_id]);
                if ($stmtLoyCheck->fetch()) {
                    error_log("[confirmar-entrega] Loyalty already credited for order #{$order_id} — skipping (idempotency)");
                } else {
                    $isMembro = OmPricing::isSuperboraPlus($db, $customer_id);
                    $loyaltyPoints = OmPricing::calcularPontos($subtotal, $isMembro);

                    // Upsert loyalty points
                    $db->prepare("
                        INSERT INTO om_market_loyalty_points (customer_id, current_points, updated_at)
                        VALUES (?, ?, NOW())
                        ON CONFLICT (customer_id) DO UPDATE SET current_points = om_market_loyalty_points.current_points + EXCLUDED.current_points, updated_at = NOW()
                    ")->execute([$customer_id, $loyaltyPoints]);

                    // Log transaction
                    $db->prepare("
                        INSERT INTO om_market_loyalty_transactions (customer_id, points, type, source, reference_id, description, created_at)
                        VALUES (?, ?, 'earned', 'order_delivered', ?, ?, NOW())
                    ")->execute([$customer_id, $loyaltyPoints, $order_id, "Pedido #{$order_id} entregue (+{$loyaltyPoints} pts)"]);

                    // Save earned points to order
                    $db->prepare("UPDATE om_market_orders SET loyalty_points_earned = ? WHERE order_id = ?")->execute([$loyaltyPoints, $order_id]);

                    error_log("[confirmar-entrega] +{$loyaltyPoints} pontos para cliente #{$customer_id} (pedido #{$order_id})");
                }
            }
        } catch (Exception $loyErr) {
            error_log("[confirmar-entrega] Loyalty error: " . $loyErr->getMessage());
        }

        // Calcular quando vai liberar
        $libera_em = date('H:i', strtotime('+2 hours'));

        // Notificar parceiro via Pusher
        try {
            require_once dirname(__DIR__, 3) . '/includes/classes/PusherService.php';
            PusherService::walletUpdate((int)$pedido['partner_id'], [
                'order_id' => $order_id,
                'valor' => $valor_repasse,
                'comissao' => $comissaoValor,
                'status' => 'hold',
                'hold_hours' => 2
            ]);
        } catch (Exception $pe) {}

        // ── Referral completion: if this is the first delivered order for the customer,
        // credit R$10 cashback to whoever referred them (idempotent in completeReferral) ──
        if ($finalStatus === 'entregue') {
            try {
                require_once __DIR__ . '/../customer/referral.php';
                if (function_exists('completeReferral')) {
                    // Only fires if this customer has a status='pending' referral
                    $refResult = completeReferral($db, (int)$customer_id, (int)$order_id);
                    if ($refResult['success'] ?? false) {
                        error_log("[confirmar-entrega] referral completed: customer={$customer_id} order={$order_id}");
                    }
                }
            } catch (Exception $refErr) {
                error_log("[confirmar-entrega] referral hook failed: " . $refErr->getMessage());
            }
        }

        // ── Loyalty stamp: increment customer's stamp card for this partner (idempotent) ──
        try {
            $internalKey = $_ENV['INTERNAL_API_KEY'] ?? getenv('INTERNAL_API_KEY') ?: '';
            if (!empty($internalKey) && $finalStatus === 'entregue') {
                $stCh = curl_init('http://127.0.0.1/api/mercado/customer/loyalty-stamps.php');
                curl_setopt_array($stCh, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode(['action' => 'stamp', 'order_id' => $order_id]),
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'X-API-Key: ' . $internalKey,
                    ],
                    CURLOPT_TIMEOUT => 2,
                    CURLOPT_CONNECTTIMEOUT => 1,
                ]);
                curl_exec($stCh);
                curl_close($stCh);
            }
        } catch (Exception $stEx) { /* loyalty stamp is best-effort */ }

        response(true, [
            "order_id" => $order_id,
            "status" => $finalStatus,
            "mensagem" => $isPickupOrder ? "Retirada confirmada com sucesso!" : "Entrega confirmada com sucesso!",
            "repasse" => [
                "valor" => $valor_repasse,
                "comissao_pct" => $comissaoPct * 100,
                "comissao_valor" => $comissaoValor,
                "status" => "hold",
                "libera_em" => $libera_em,
                "mensagem" => "R$ " . number_format($valor_repasse, 2, ',', '.') . " sera liberado as {$libera_em} (comissao " . ($comissaoPct * 100) . "%)"
            ]
        ]);

    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) $db->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Erro confirmar entrega: " . $e->getMessage());
    response(false, null, "Erro ao confirmar entrega", 500);
}

