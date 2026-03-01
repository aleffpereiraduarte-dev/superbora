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
require_once dirname(__DIR__, 3) . '/includes/classes/OmAuth.php';

setCorsHeaders();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    response(false, null, "Metodo nao permitido", 405);
}

$input = getInput();

$order_id = (int)($input['order_id'] ?? 0);
$codigo_confirmacao = trim($input['codigo'] ?? '');
$foto_entrega = trim($input['foto'] ?? '');
$confirmado_por = $input['confirmado_por'] ?? 'cliente';

// SECURITY: Whitelist confirmado_por to prevent auth bypass
$confirmadoresPermitidos = ['cliente', 'motorista'];
if (!in_array($confirmado_por, $confirmadoresPermitidos, true)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "confirmado_por invalido"]);
    exit;
}

try {
    $db = getDB();

    if (!$order_id) {
        response(false, null, "order_id obrigatorio", 400);
    }

    // Autenticacao obrigatoria para todos os tipos
    OmAuth::getInstance()->setDb($db);
    $token = om_auth()->getTokenFromRequest();
    if (!$token) {
        response(false, null, "Autenticacao obrigatoria", 401);
    }
    $authPayload = om_auth()->validateToken($token);
    if (!$authPayload) {
        response(false, null, "Token invalido ou expirado", 401);
    }
    $authType = $authPayload['type'] ?? '';
    $authUid = (int)($authPayload['uid'] ?? 0);

    if ($confirmado_por === 'cliente') {
        // Cliente: must be a customer token — reject non-customer types
        if ($authType !== 'customer') {
            response(false, null, "Token invalido para confirmacao de cliente", 403);
        }
        // Use $authUid from OmAuth (already validated with revocation check)
        $auth_customer_id = $authUid;
        if (!$auth_customer_id) {
            response(false, null, "Autenticacao obrigatoria", 401);
        }
    } else {
        // Motorista/shopper: require token auth + codigo de confirmacao
        if (!in_array($authType, [OmAuth::USER_TYPE_SHOPPER, OmAuth::USER_TYPE_MOTORISTA, OmAuth::USER_TYPE_PARTNER], true)) {
            response(false, null, "Autenticacao de shopper ou parceiro obrigatoria", 401);
        }
        if (empty($codigo_confirmacao)) {
            response(false, null, "Codigo de confirmacao obrigatorio para $confirmado_por", 400);
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
        response(false, null, "Pedido nao encontrado", 404);
    }

    // Verificar ownership se cliente
    if ($confirmado_por === 'cliente' && isset($auth_customer_id)) {
        if ((int)$pedido['customer_id'] !== $auth_customer_id) {
            $db->rollBack();
            response(false, null, "Pedido nao encontrado", 404);
        }
    }

    // Verificar ownership se motorista/shopper
    if ($confirmado_por === 'motorista') {
        $ownershipOk = false;
        if ($authType === OmAuth::USER_TYPE_SHOPPER && (int)($pedido['shopper_id'] ?? 0) === $authUid) {
            $ownershipOk = true;
        }
        if ($authType === OmAuth::USER_TYPE_MOTORISTA && (int)($pedido['driver_id'] ?? 0) === $authUid) {
            $ownershipOk = true;
        }
        if ($authType === OmAuth::USER_TYPE_PARTNER && (int)($pedido['partner_id'] ?? 0) === $authUid) {
            $ownershipOk = true;
        }
        if (!$ownershipOk) {
            $db->rollBack();
            error_log("[confirmar-entrega] SECURITY: Auth uid={$authUid} type={$authType} nao relacionado ao pedido #{$order_id}");
            response(false, null, "Nao autorizado para este pedido", 403);
        }
    }

    if (in_array($pedido['status'], ['entregue', 'retirado'])) {
        $db->rollBack();
        response(false, null, "Pedido ja foi " . ($pedido['status'] === 'retirado' ? 'retirado' : 'entregue'), 400);
    }

    $isPickupOrder = (bool)($pedido['is_pickup'] ?? false);

    if (!in_array($pedido['status'], ['em_entrega', 'coletando', 'pronto'])) {
        $db->rollBack();
        response(false, null, "Pedido nao esta em entrega", 400);
    }

    // SECURITY: Customer cannot confirm DELIVERY on 'pronto' orders (must be em_entrega)
    // EXCEPTION: Pickup orders — customer confirms PICKUP when status is 'pronto'
    if ($confirmado_por === 'cliente' && $pedido['status'] === 'pronto' && !$isPickupOrder) {
        $db->rollBack();
        response(false, null, "Pedido ainda nao saiu para entrega", 400);
    }

    // SECURITY: For non-pickup orders in 'pronto' status, motorista must first transition
    // to 'em_entrega' before finalizing to 'entregue'. Cannot skip the em_entrega step.
    if ($confirmado_por === 'motorista' && $pedido['status'] === 'pronto' && !$isPickupOrder) {
        // Transition to em_entrega instead of finalizing
        $stmtTransit = $db->prepare("
            UPDATE om_market_orders
            SET status = 'em_entrega', date_modified = NOW()
            WHERE order_id = ?
        ");
        $stmtTransit->execute([$order_id]);
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
            response(false, null, "Pedido sem codigo de confirmacao configurado", 400);
        }
        if (strtoupper(trim($codigo_confirmacao)) !== strtoupper(trim($storedCode))) {
            $db->rollBack();
            response(false, null, "Codigo de confirmacao invalido", 400);
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
            WHERE order_id = ?
        ");
        $stmt->execute([$finalStatus, $foto_entrega, $confirmado_por, $order_id]);

        // 2. Verificar se repasse ja existe (idempotencia — webhook pode ter criado)
        require_once dirname(__DIR__, 3) . '/includes/classes/OmPricing.php';
        require_once dirname(__DIR__, 3) . '/includes/classes/OmDailyBudget.php';
        require_once dirname(__DIR__, 3) . '/includes/classes/OmRepasse.php';

        $stmtRepasseCheck = $db->prepare("SELECT id FROM om_repasses WHERE order_id = ? AND order_type = 'mercado' LIMIT 1");
        $stmtRepasseCheck->execute([$order_id]);
        $repasseJaExiste = (bool)$stmtRepasseCheck->fetch();

        $subtotal = floatval($pedido['subtotal']);
        $deliveryFee = floatval($pedido['delivery_fee'] ?? 0);
        $expressFee = floatval($pedido['express_fee'] ?? 0);
        $isPickup = (bool)($pedido['is_pickup'] ?? false);
        $partnerId = (int)$pedido['partner_id'];
        $paymentMethod = $pedido['payment_method'] ?? $pedido['forma_pagamento'] ?? 'pix';
        $isCashOrder = in_array($paymentMethod, ['dinheiro', 'cartao_entrega']);

        // Determinar se usou BoraUm: checar om_entregas
        $stmtEntrega = $db->prepare("SELECT boraum_delivery_id, distancia_km FROM om_entregas WHERE referencia_id = ? AND origem_sistema = 'mercado' LIMIT 1");
        $stmtEntrega->execute([$order_id]);
        $entregaRow = $stmtEntrega->fetch();
        $usaBoraUm = !empty($entregaRow['boraum_delivery_id']);
        $distanciaKm = floatval($entregaRow['distancia_km'] ?? 3);

        // Comissao centralizada via OmPricing
        $comissao = OmPricing::calcularComissao($subtotal, $usaBoraUm ? 'boraum' : 'proprio');
        $comissaoPct = $comissao['taxa'];
        $comissaoValor = $comissao['valor'];
        $valor_repasse = round($subtotal - $comissaoValor, 2);

        // Se entregador proprio ou retirada, parceiro recebe a taxa de entrega BASE (sem express)
        // express_fee e receita da plataforma, nao vai pro parceiro
        $deliveryFeeBase = max(0, $deliveryFee - $expressFee);
        if (!$usaBoraUm && $deliveryFeeBase > 0) {
            $valor_repasse += $deliveryFeeBase;
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
                    'is_pickup' => $isPickup,
                    'tier' => $usaBoraUm ? 'boraum_18pct' : 'proprio_10pct',
                    'receita_plataforma' => round($comissaoValor + $serviceFee + $expressFee, 2),
                ]
            );

            // Salvar comissao e repasse no pedido
            $stmt = $db->prepare("UPDATE om_market_orders SET repasse_valor = ?, commission_rate = ?, commission_amount = ? WHERE order_id = ?");
            $stmt->execute([$valor_repasse, $comissaoPct * 100, $comissaoValor, $order_id]);
        }

        $db->commit();

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
        // CASHBACK - liberar cashback pendente
        // ═══════════════════════════════════════════════════════
        try {
            $customer_id = (int)($pedido['customer_id'] ?? 0);
            if ($customer_id) {
                // Mudar status de 'pending' para 'available' para este pedido
                $stmt = $db->prepare("
                    UPDATE om_cashback
                    SET status = 'available'
                    WHERE customer_id = ? AND order_id = ? AND status = 'pending'
                ");
                $stmt->execute([$customer_id, $order_id]);
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
        $db->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Erro confirmar entrega: " . $e->getMessage());
    response(false, null, "Erro ao confirmar entrega", 500);
}

