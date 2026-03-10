<?php
/**
 * POST /api/mercado/pedido/cancelar.php
 * Body: { "order_id": 1, "motivo": "Desisti", "confirm_fee": true }
 *
 * Politica de cancelamento estilo iFood:
 * ┌────────────────────┬─────────────┬──────────────────────────────┐
 * │ Status             │ Taxa        │ Reembolso                    │
 * ├────────────────────┼─────────────┼──────────────────────────────┤
 * │ pendente/confirmado│ 0%          │ 100% (reembolso total)       │
 * │ aceito             │ 0%          │ 100% (reembolso total)       │
 * │ preparando/em_prep │ taxa preparo│ parcial (desconta taxa)      │
 * │ pronto             │ subtotal    │ so devolve taxa entrega      │
 * │ em_entrega         │ bloqueado   │ contestar com suporte        │
 * │ entregue/retirado  │ bloqueado   │ contestar com suporte (48h)  │
 * └────────────────────┴─────────────┴──────────────────────────────┘
 *
 * Fluxo:
 * 1. Sem confirm_fee: retorna preview da taxa (nao cancela)
 * 2. Com confirm_fee=true: efetua cancelamento + aplica taxa
 * 3. Cliente pode contestar com suporte a qualquer momento
 */
require_once __DIR__ . "/../config/database.php";
setCorsHeaders();
require_once __DIR__ . "/../helpers/notify.php";
require_once __DIR__ . '/../helpers/ws-customer-broadcast.php';
require_once __DIR__ . '/../helpers/zapi-whatsapp.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, "Metodo nao permitido", 405);
}

try {
    $input = getInput();
    $db = getDB();

    // Autenticacao obrigatoria
    $customer_id = requireCustomerAuth();

    $order_id = intval($input["order_id"] ?? 0);
    $motivo = strip_tags(trim(substr($input["motivo"] ?? "", 0, 500)));
    $confirmFee = !empty($input["confirm_fee"]);

    if (!$order_id) response(false, null, "order_id obrigatorio", 400);

    // Buscar pedido com lock para evitar race condition
    $db->beginTransaction();

    // SECURITY: Include customer_id in lock query to prevent DoS via lock contention
    $stmtPedido = $db->prepare("SELECT * FROM om_market_orders WHERE order_id = ? AND customer_id = ? FOR UPDATE");
    $stmtPedido->execute([$order_id, $customer_id]);
    $pedido = $stmtPedido->fetch();

    if (!$pedido) {
        $db->rollBack();
        response(false, null, "Pedido nao encontrado", 404);
    }

    $status = $pedido["status"];
    $orderTotal = (float)($pedido['total'] ?? $pedido['final_total'] ?? 0);
    $subtotal = (float)($pedido['subtotal'] ?? $pedido['custo_produtos'] ?? $orderTotal);
    $deliveryFee = (float)($pedido['delivery_fee'] ?? $pedido['shipping_fee'] ?? 0);

    // ─── Calcular taxa de cancelamento baseada no status ───
    $cancellationFee = 0;
    $refundAmount = $orderTotal;
    $canCancel = true;
    $feeReason = '';
    $supportOnly = false;

    // Cancelamento livre (antes do preparo)
    if (in_array($status, ['pendente', 'confirmado', 'aceito'])) {
        $cancellationFee = 0;
        $refundAmount = $orderTotal;
        $feeReason = 'Cancelamento gratuito';

    // Em preparo: cobra 30% do subtotal como taxa de preparo
    } elseif (in_array($status, ['preparando', 'em_preparo'])) {
        $cancellationFee = round($subtotal * 0.30, 2);
        $refundAmount = round($orderTotal - $cancellationFee, 2);
        $feeReason = 'Taxa de preparo (30% do valor dos produtos)';

    // Pronto pra coleta/entrega: cobra subtotal inteiro, devolve so taxa de entrega
    } elseif (in_array($status, ['pronto', 'pronto_coleta', 'pronto_retirada'])) {
        $cancellationFee = $subtotal;
        $refundAmount = round($deliveryFee, 2);
        $feeReason = 'Pedido ja preparado. Reembolso apenas da taxa de entrega.';

    // Em entrega ou apos: nao pode cancelar pelo app
    } elseif (in_array($status, ['em_entrega', 'delivering', 'em_transito'])) {
        $canCancel = false;
        $supportOnly = true;
        $feeReason = 'Pedido em entrega. Conteste pelo suporte.';

    } elseif (in_array($status, ['delivered', 'entregue', 'retirado'])) {
        $canCancel = false;
        $supportOnly = true;
        $feeReason = 'Pedido ja entregue. Conteste pelo suporte em ate 48h.';

    } elseif (in_array($status, ['cancelado', 'refunded'])) {
        $db->rollBack();
        response(false, null, "Pedido ja foi cancelado.", 400);

    } else {
        $canCancel = false;
        $supportOnly = true;
        $feeReason = 'Nao e possivel cancelar neste status.';
    }

    // Se nao pode cancelar, retorna com instrucao de suporte
    if (!$canCancel) {
        $db->rollBack();
        response(false, [
            'support_only' => true,
            'reason' => $feeReason,
            'support_message' => 'Entre em contato pelo chat de suporte para solicitar o cancelamento.',
        ], $feeReason, 400);
    }

    // Se tem taxa e cliente ainda nao confirmou: retorna preview
    if ($cancellationFee > 0 && !$confirmFee) {
        $db->rollBack();
        response(true, [
            'preview' => true,
            'cancellation_fee' => $cancellationFee,
            'refund_amount' => max(0, $refundAmount),
            'order_total' => $orderTotal,
            'fee_reason' => $feeReason,
            'status' => $status,
            'message' => "Sera cobrada uma taxa de R$ " . number_format($cancellationFee, 2, ',', '.') . ". Voce recebera R$ " . number_format(max(0, $refundAmount), 2, ',', '.') . " de reembolso.",
        ], "Confirme o cancelamento");
    }

    // ─── Efetuar cancelamento ───
    try {
        // 1. Marcar como cancelado
        $notaCancel = " | Cancelado pelo cliente: " . $motivo;
        if ($cancellationFee > 0) {
            $notaCancel .= " | Taxa cancelamento: R$" . number_format($cancellationFee, 2, ',', '.');
        }
        $stmtUpd = $db->prepare("UPDATE om_market_orders SET status = 'cancelado', cancel_reason = ?, cancelled_at = NOW(), notes = COALESCE(notes,'') || ?, date_modified = NOW() WHERE order_id = ?");
        $stmtUpd->execute([$motivo, $notaCancel, $order_id]);

        // 1b. Route cleanup (DoubleDash)
        $routeId = (int)($pedido['route_id'] ?? 0);
        $routeSeq = (int)($pedido['route_stop_sequence'] ?? 0);
        if ($routeId) {
            // Mark route stop as cancelled
            $db->prepare("UPDATE om_delivery_route_stops SET status = 'cancelled' WHERE route_id = ? AND order_id = ?")
               ->execute([$routeId, $order_id]);

            // Decrement total_orders on route
            $db->prepare("UPDATE om_delivery_routes SET total_orders = GREATEST(0, total_orders - 1) WHERE route_id = ?")
               ->execute([$routeId]);

            // If primary order (seq 1): cascade cancel all secondary orders in the route
            if ($routeSeq <= 1) {
                $stmtSecondaries = $db->prepare("
                    SELECT order_id FROM om_market_orders
                    WHERE route_id = ? AND order_id != ? AND status NOT IN ('cancelado', 'cancelled', 'entregue', 'retirado')
                    FOR UPDATE
                ");
                $stmtSecondaries->execute([$routeId, $order_id]);
                $secondaries = $stmtSecondaries->fetchAll(PDO::FETCH_COLUMN);
                foreach ($secondaries as $secId) {
                    $db->prepare("UPDATE om_market_orders SET status = 'cancelado', cancel_reason = 'Pedido primario cancelado', cancelled_at = NOW(), date_modified = NOW() WHERE order_id = ?")->execute([$secId]);
                    // Restore stock for secondary order items (with FOR UPDATE lock)
                    $stmtSecItems = $db->prepare("SELECT product_id, quantity FROM om_market_order_items WHERE order_id = ?");
                    $stmtSecItems->execute([$secId]);
                    foreach ($stmtSecItems->fetchAll() as $si) {
                        if ($si['product_id']) {
                            $db->prepare("SELECT product_id FROM om_market_products WHERE product_id = ? FOR UPDATE")->execute([$si['product_id']]);
                            $db->prepare("UPDATE om_market_products SET quantity = quantity + ? WHERE product_id = ?")->execute([$si['quantity'], $si['product_id']]);
                        }
                    }
                    // Mark route stop as cancelled
                    $db->prepare("UPDATE om_delivery_route_stops SET status = 'cancelled' WHERE route_id = ? AND order_id = ?")->execute([$routeId, $secId]);
                    error_log("[cancelar] Cascade cancelled secondary order #$secId from route #$routeId");
                }
            }
        }

        // 2. Restaurar estoque dos produtos (with FOR UPDATE to prevent race conditions)
        $stmtItens = $db->prepare("SELECT product_id, quantity FROM om_market_order_items WHERE order_id = ?");
        $stmtItens->execute([$order_id]);
        $itens = $stmtItens->fetchAll();
        foreach ($itens as $item) {
            if ($item['product_id']) {
                $db->prepare("SELECT product_id FROM om_market_products WHERE product_id = ? FOR UPDATE")
                   ->execute([$item['product_id']]);
                $db->prepare("UPDATE om_market_products SET quantity = quantity + ? WHERE product_id = ?")
                   ->execute([$item['quantity'], $item['product_id']]);
            }
        }

        // 3. Save Stripe info for refund AFTER commit (external call must not hold FOR UPDATE lock)
        $refundResult = null;
        $paymentMethod = $pedido['forma_pagamento'] ?? $pedido['payment_method'] ?? '';
        $stripePi = $pedido['stripe_payment_intent_id'] ?? '';
        // Only Stripe for wallet payments (Apple Pay, Google Pay)
        $needsStripeRefund = in_array($paymentMethod, ['stripe_wallet']) && $stripePi;
        // Se tem taxa, fazer refund parcial no Stripe. null = full refund, 0 = no refund needed
        $stripeRefundAmount = $cancellationFee > 0 ? max(0, $refundAmount) : null; // null = full refund
        // If refund amount is 0 (e.g. pickup order cancelled at 'pronto' status), skip Stripe call
        if ($stripeRefundAmount !== null && $stripeRefundAmount <= 0) {
            $needsStripeRefund = false;
        }

        // 4. Restaurar pontos de fidelidade usados (proporcional se taxa)
        $pointsUsed = (int)($pedido['loyalty_points_used'] ?? 0);
        if ($pointsUsed > 0) {
            $pointsToRefund = $cancellationFee > 0
                ? (int)round($pointsUsed * (max(0, $refundAmount) / max(1, $orderTotal)))
                : $pointsUsed;
            $pointsToRefund = max(0, min($pointsToRefund, $pointsUsed));
            if ($pointsToRefund > 0) {
                $db->prepare("UPDATE om_market_loyalty_points SET current_points = current_points + ?, updated_at = NOW() WHERE customer_id = ?")
                   ->execute([$pointsToRefund, $customer_id]);
                $db->prepare("INSERT INTO om_market_loyalty_transactions (customer_id, points, type, source, reference_id, description, created_at) VALUES (?, ?, 'refund', 'order_cancelled', ?, ?, NOW())")
                   ->execute([$customer_id, $pointsToRefund, $order_id, "Estorno cancelamento pedido #$order_id"]);
            }
        }

        // 5. Restaurar cashback usado (always refund — customer already pays the cancellation fee)
        $cashbackUsed = (float)($pedido['cashback_discount'] ?? 0);
        if ($cashbackUsed > 0) {
            require_once __DIR__ . '/../helpers/cashback.php';
            refundCashback($db, $order_id);
        }

        // 6. Restaurar cupom de uso unico (always restore — order is cancelled regardless of fee)
        $couponId = (int)($pedido['coupon_id'] ?? 0);
        if ($couponId) {
            $db->prepare("DELETE FROM om_market_coupon_usage WHERE coupon_id = ? AND customer_id = ? AND order_id = ?")
               ->execute([$couponId, $customer_id, $order_id]);
            $db->prepare("UPDATE om_market_coupons SET current_uses = GREATEST(0, current_uses - 1) WHERE id = ?")->execute([$couponId]);
        }

        // 7. Liberar shopper se tiver
        if ($pedido["shopper_id"]) {
            $stmtShopper = $db->prepare("UPDATE om_market_shoppers SET disponivel = 1, pedido_atual_id = NULL WHERE shopper_id = ?");
            $stmtShopper->execute([$pedido["shopper_id"]]);
        }

        $db->commit();

        // WebSocket broadcast (never breaks the flow)
        try {
            wsBroadcastToCustomer($customer_id, 'order_update', [
                'order_id' => $order_id,
                'status' => 'cancelado',
                'previous_status' => $status,
                'cancellation_fee' => $cancellationFee,
                'refund_amount' => max(0, $refundAmount),
            ]);
            wsBroadcastToOrder($order_id, 'order_update', [
                'order_id' => $order_id,
                'status' => 'cancelado',
            ]);
        } catch (\Throwable $e) {}

        // 3a. Cancel repasses (partner payouts) — prevents money leak on cancelled orders
        try {
            require_once dirname(__DIR__, 3) . '/includes/classes/OmRepasse.php';
            $repasse = new OmRepasse($db);
            $stmtRepasses = $db->prepare("SELECT id FROM om_repasses WHERE order_id = ? AND order_type = 'mercado' AND status IN ('hold', 'pendente')");
            $stmtRepasses->execute([$order_id]);
            foreach ($stmtRepasses->fetchAll(PDO::FETCH_COLUMN) as $repasseId) {
                $repasse->cancelar((int)$repasseId, "Pedido #$order_id cancelado: $motivo", 'sistema');
            }
        } catch (Exception $repErr) {
            error_log("[cancelar] Erro cancelar repasse pedido #$order_id: " . $repErr->getMessage());
        }

        // 3b. Cancelar entrega BoraUm se despachada (external API call outside transaction)
        try {
            require_once __DIR__ . '/../helpers/delivery.php';
            $cancelResult = cancelBoraUmDelivery($db, $order_id);
            if (!empty($cancelResult['boraum_cancelled'])) {
                error_log("[cancelar] BoraUm delivery cancelada para pedido #$order_id");
            }
        } catch (Exception $boraErr) {
            error_log("[cancelar] Erro cancelar BoraUm: " . $boraErr->getMessage());
        }

        // 3c. Estornar Stripe APOS commit (parcial se tem taxa)
        if ($needsStripeRefund) {
            try {
                $refundResult = refundStripePayment($stripePi, $order_id, $stripeRefundAmount);
                if ($refundResult['success'] ?? false) {
                    $db->prepare("UPDATE om_market_orders SET notes = COALESCE(notes,'') || ? WHERE order_id = ?")
                       ->execute([" [REFUND OK: {$refundResult['refund_id']}]", $order_id]);
                } else {
                    $db->prepare("UPDATE om_market_orders SET notes = COALESCE(notes,'') || ' [REFUND FAILED]' WHERE order_id = ?")
                       ->execute([$order_id]);
                }
            } catch (Exception $refErr) {
                error_log("[cancelar] Erro estorno Stripe PI={$stripePi}: " . $refErr->getMessage());
                $db->prepare("UPDATE om_market_orders SET notes = COALESCE(notes,'') || ' [REFUND ERROR]' WHERE order_id = ?")
                   ->execute([$order_id]);
            }
        }

        // 3d. Estornar PIX via EFI se pagamento foi confirmado
        $needsPixRefund = ($paymentMethod === 'pix') && (
            ($pedido['pagamento_status'] ?? '') === 'pago' ||
            ($pedido['payment_status'] ?? '') === 'paid' ||
            ($pedido['pix_paid'] ?? false)
        );
        if ($needsPixRefund) {
            try {
                require_once dirname(__DIR__, 3) . '/includes/classes/EfiClient.php';
                $efi = new EfiClient();

                // e2eId is stored in payment_id, or look up via om_pix_intents txid
                $efiE2eId = $pedido['payment_id'] ?? '';
                $efiTxid = '';
                if (empty($efiE2eId)) {
                    $intentStmt = $db->prepare("SELECT correlation_id FROM om_pix_intents WHERE order_id = ? AND status = 'paid' LIMIT 1");
                    $intentStmt->execute([$order_id]);
                    $efiTxid = $intentStmt->fetchColumn() ?: '';
                }

                // If no e2eId, get it from checking the charge
                $refundE2eId = $efiE2eId;
                if (empty($refundE2eId) && !empty($efiTxid)) {
                    $chargeStatus = $efi->checkChargeStatus($efiTxid);
                    $refundE2eId = $chargeStatus['e2e_id'] ?? '';
                }

                if (!empty($refundE2eId)) {
                    $refundAmt = $cancellationFee > 0 ? max(0, $refundAmount) : (float)$pedido['total'];
                    if ($refundAmt > 0) {
                        $pixRefundResult = $efi->refundPix($refundE2eId, $refundAmt);
                        if ($pixRefundResult['success']) {
                            error_log("[cancelar] EFI PIX refund OK pedido #$order_id e2e=$refundE2eId dev={$pixRefundResult['devolucao_id']}");
                            $db->prepare("UPDATE om_market_orders SET notes = COALESCE(notes,'') || ? WHERE order_id = ?")
                               ->execute([" [PIX REFUND OK: {$pixRefundResult['devolucao_id']}]", $order_id]);
                        } else {
                            error_log("[cancelar] EFI PIX refund FAILED pedido #$order_id e2e=$refundE2eId");
                            $db->prepare("UPDATE om_market_orders SET notes = COALESCE(notes,'') || ' [PIX REFUND FAILED - MANUAL]' WHERE order_id = ?")
                               ->execute([$order_id]);
                        }
                    }
                } else {
                    error_log("[cancelar] No e2eId for PIX refund pedido #$order_id");
                }
            } catch (Exception $pixRefErr) {
                error_log("[cancelar] Erro estorno EFI PIX pedido #$order_id: " . $pixRefErr->getMessage());
            }
        }

        // 3e. Estornar cartao EFI se pagamento por cartao brasileiro
        $efiChargeId = (int)($pedido['efi_charge_id'] ?? 0);
        if (in_array($paymentMethod, ['efi_card', 'credito', 'debito']) && $efiChargeId > 0) {
            try {
                require_once dirname(__DIR__, 3) . '/includes/classes/EfiClient.php';
                $efi = new EfiClient();
                $cardRefundResult = $efi->refundCard($efiChargeId);
                if ($cardRefundResult['success']) {
                    error_log("[cancelar] EFI card refund OK pedido #$order_id chargeId=$efiChargeId");
                    $db->prepare("UPDATE om_market_orders SET notes = COALESCE(notes,'') || ' [CARD REFUND OK]' WHERE order_id = ?")
                       ->execute([$order_id]);
                } else {
                    error_log("[cancelar] EFI card refund FAILED pedido #$order_id chargeId=$efiChargeId");
                }
            } catch (Exception $cardErr) {
                error_log("[cancelar] Erro EFI card refund: " . $cardErr->getMessage());
            }
        }

        // 8. Notificar parceiro (apos commit)
        $partnerId = (int)($pedido['partner_id'] ?? 0);
        if ($partnerId) {
            try {
                notifyPartner($db, $partnerId,
                    'Pedido cancelado',
                    "Pedido #{$pedido['order_number']} foi cancelado pelo cliente." . ($motivo ? " Motivo: $motivo" : ""),
                    '/painel/mercado/pedidos.php'
                );
            } catch (Exception $e) {}
        }

        // WhatsApp notification (never breaks the flow)
        try {
            $customerPhone = $pedido['customer_phone'] ?? '';
            if ($customerPhone) {
                $waResult = whatsappOrderCancelled($customerPhone, $pedido['order_number'], $motivo);
                error_log("[cancelar] WhatsApp pedido #{$pedido['order_number']} phone=****" . substr($customerPhone, -4) . " success=" . ($waResult['success'] ? 'yes' : 'no'));
            }
        } catch (\Throwable $waErr) {
            error_log("[cancelar] WhatsApp error: " . $waErr->getMessage());
        }

        $responseData = [
            "order_id" => $order_id,
            "status" => "cancelado",
            "cancellation_fee" => $cancellationFee,
            "refund_amount" => max(0, $refundAmount),
        ];
        if ($refundResult && ($refundResult['success'] ?? false)) {
            $responseData['refund'] = 'Estorno processado';
        }
        if ($cancellationFee > 0) {
            $responseData['fee_message'] = "Taxa de cancelamento: R$ " . number_format($cancellationFee, 2, ',', '.');
        }

        $refundMsg = $cancellationFee > 0
            ? "Pedido cancelado. Reembolso de R$ " . number_format(max(0, $refundAmount), 2, ',', '.') . " sera processado."
            : "Pedido cancelado com sucesso. Reembolso total sera processado.";

        error_log("[cancelar] Pedido #{$order_id} cancelado. Taxa=R\${$cancellationFee} Reembolso=R\${$refundAmount} Pontos={$pointsUsed} Cashback=R\${$cashbackUsed} Cupom={$couponId} Stripe=" . ($refundResult ? 'sim' : 'n/a'));

        response(true, $responseData, $refundMsg);

    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("[pedido/cancelar] Erro: " . $e->getMessage());
    response(false, null, 'Erro interno do servidor', 500);
}

/**
 * Estornar pagamento Stripe via API (suporta refund parcial)
 */
function refundStripePayment(string $paymentIntentId, int $orderId, ?float $amount = null): array {
    // Load Stripe keys
    $stripeEnv = dirname(__DIR__, 3) . '/.env.stripe';
    $STRIPE_SK = '';
    if (file_exists($stripeEnv)) {
        foreach (file($stripeEnv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            if (strpos($line, '=') !== false) {
                [$key, $value] = explode('=', $line, 2);
                if (trim($key) === 'STRIPE_SECRET_KEY') $STRIPE_SK = trim($value);
            }
        }
    }
    if (empty($STRIPE_SK)) {
        return ['success' => false, 'error' => 'Stripe not configured'];
    }

    $postFields = [
        'payment_intent' => $paymentIntentId,
        'reason' => 'requested_by_customer',
        'metadata[order_id]' => $orderId,
        'metadata[source]' => 'superbora_cancel',
    ];
    // Refund parcial: converter para centavos (Stripe usa menor unidade)
    if ($amount !== null && $amount > 0) {
        $postFields['amount'] = (int)round($amount * 100);
    }

    // Idempotency-Key prevents double refunds on retries/timeouts
    $amountKey = $amount !== null ? (int)round($amount * 100) : 'full';
    $idempotencyKey = "refund_cancelar_{$orderId}_{$paymentIntentId}_{$amountKey}";

    $ch = curl_init("https://api.stripe.com/v1/refunds");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postFields),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $STRIPE_SK,
            'Content-Type: application/x-www-form-urlencoded',
            'Stripe-Version: 2023-10-16',
            'Idempotency-Key: ' . $idempotencyKey,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $result = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($result, true);
    $success = $code >= 200 && $code < 300 && !empty($data['id']);

    error_log("[cancelar] Stripe refund PI=$paymentIntentId amount=" . ($amount ?? 'full') . " code=$code success=" . ($success ? 'yes' : 'no'));

    return ['success' => $success, 'refund_id' => $data['id'] ?? null, 'status' => $data['status'] ?? null];
}
