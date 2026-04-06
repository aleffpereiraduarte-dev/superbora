<?php
/**
 * POST /api/mercado/pedido/recusar.php
 * Parceiro recusa/cancela pedido com motivo
 * Body: { "order_id": 10, "motivo": "Produto indisponivel" }
 */
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../helpers/notify.php";
require_once __DIR__ . '/../helpers/ws-customer-broadcast.php';
require_once __DIR__ . '/../helpers/zapi-whatsapp.php';
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, "Metodo nao permitido", 405);
}

// CSRF protection: require JSON content type for session-auth endpoints
$ct = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
if (stripos($ct, 'application/json') === false) {
    response(false, null, "Content-Type deve ser application/json", 400);
}

try {
    session_start();
    session_write_close();
    $db = getDB();

    $mercado_id = $_SESSION['mercado_id'] ?? 0;
    if (!$mercado_id) {
        response(false, null, "Nao autorizado", 401);
    }

    $input = getInput();
    $order_id = (int)($input['order_id'] ?? 0);
    $motivo = strip_tags(trim(substr($input['motivo'] ?? '', 0, 500)));

    if (!$order_id) {
        response(false, null, "order_id obrigatorio", 400);
    }

    if (empty($motivo)) {
        response(false, null, "Motivo da recusa e obrigatorio", 400);
    }

    $db->beginTransaction();
    $stmt = $db->prepare("SELECT * FROM om_market_orders WHERE order_id = ? AND partner_id = ? FOR UPDATE");
    $stmt->execute([$order_id, $mercado_id]);
    $pedido = $stmt->fetch();

    if (!$pedido) {
        $db->rollBack();
        response(false, null, "Pedido nao encontrado", 404);
    }

    $statusPermitidos = ['pendente', 'confirmado', 'aceito', 'preparando'];
    if (!in_array($pedido['status'], $statusPermitidos)) {
        $db->rollBack();
        response(false, null, "Pedido nao pode ser recusado (status atual: {$pedido['status']})", 409);
    }

    $stmt = $db->prepare("
        UPDATE om_market_orders SET
            status = 'cancelado',
            cancel_reason = ?,
            cancelled_at = NOW(),
            date_modified = NOW()
        WHERE order_id = ? AND status IN ('pendente', 'confirmado', 'aceito', 'preparando')
    ");
    $stmt->execute([$motivo, $order_id]);
    if ($stmt->rowCount() === 0) {
        $db->rollBack();
        response(false, null, "Pedido ja foi processado por outra sessao", 409);
    }

    // Devolver estoque
    $stmt = $db->prepare("SELECT product_id, quantity FROM om_market_order_items WHERE order_id = ?");
    $stmt->execute([$order_id]);
    $itens = $stmt->fetchAll();

    foreach ($itens as $item) {
        if ($item['product_id']) {
            $stmtEstoque = $db->prepare("UPDATE om_market_products SET quantity = quantity + ? WHERE product_id = ?");
            $stmtEstoque->execute([$item['quantity'], $item['product_id']]);
        }
    }

    // Liberar shopper se alocado
    if (!empty($pedido['shopper_id'])) {
        $db->prepare("UPDATE om_market_shoppers SET disponivel = 1, pedido_atual_id = NULL WHERE shopper_id = ?")->execute([$pedido['shopper_id']]);
    }

    // Save payment info for refund after commit (external calls must be outside transaction)
    $paymentMethod = $pedido['forma_pagamento'] ?? $pedido['payment_method'] ?? '';
    $stripePi = $pedido['stripe_payment_intent_id'] ?? '';
    // Only Stripe for wallet payments (Apple Pay, Google Pay)
    $needsStripeRefund = in_array($paymentMethod, ['stripe_wallet']) && $stripePi;

    // EFI PIX refund: find the e2eId (stored in payment_id) or txid from om_pix_intents
    $efiE2eId = $pedido['payment_id'] ?? '';
    $efiTxid = '';
    if (in_array($paymentMethod, ['pix'])) {
        // Try om_pix_intents for the txid (correlation_id stores the EFI txid)
        try {
            $intentStmt = $db->prepare("SELECT correlation_id FROM om_pix_intents WHERE order_id = ? AND status = 'paid' LIMIT 1");
            $intentStmt->execute([$order_id]);
            $efiTxid = $intentStmt->fetchColumn() ?: '';
        } catch (\Exception $e) {}
    }
    $needsEfiPixRefund = in_array($paymentMethod, ['pix']) && (!empty($efiE2eId) || !empty($efiTxid));

    // EFI Card refund: check for efi_charge_id
    $efiChargeId = (int)($pedido['efi_charge_id'] ?? 0);
    $needsEfiCardRefund = in_array($paymentMethod, ['efi_card', 'credito', 'debito']) && $efiChargeId > 0;

    // Restaurar pontos e cashback
    $pointsUsed = (int)($pedido['loyalty_points_used'] ?? 0);
    $customerId = (int)($pedido['customer_id'] ?? 0);
    if ($pointsUsed > 0 && $customerId) {
        try {
            $db->prepare("UPDATE om_market_loyalty_points SET current_points = current_points + ?, updated_at = NOW() WHERE customer_id = ?")->execute([$pointsUsed, $customerId]);
            $db->prepare("INSERT INTO om_market_loyalty_transactions (customer_id, points, type, source, reference_id, description, created_at) VALUES (?, ?, 'refund', 'partner_rejected', ?, ?, NOW())")
               ->execute([$customerId, $pointsUsed, $order_id, "Estorno recusa pedido #$order_id"]);
        } catch (Exception $e) {
            error_log("[recusar] Erro restaurar pontos: " . $e->getMessage());
        }
    }
    if ((float)($pedido['cashback_discount'] ?? 0) > 0) {
        try {
            require_once __DIR__ . '/../helpers/cashback.php';
            refundCashback($db, $order_id);
        } catch (Exception $e) {
            error_log("[recusar] Erro restaurar cashback: " . $e->getMessage());
        }
    }
    $couponId = (int)($pedido['coupon_id'] ?? 0);
    if ($couponId && $customerId) {
        try {
            $db->prepare("DELETE FROM om_market_coupon_usage WHERE coupon_id = ? AND customer_id = ? AND order_id = ?")->execute([$couponId, $customerId, $order_id]);
            $db->prepare("UPDATE om_market_coupons SET current_uses = GREATEST(0, current_uses - 1) WHERE id = ?")->execute([$couponId]);
        } catch (Exception $e) {
            error_log("[recusar] Erro restaurar cupom: " . $e->getMessage());
        }
    }

    $db->commit();

    // WebSocket broadcast (never breaks the flow)
    try {
        $customer_id_ws = (int)($pedido['customer_id'] ?? 0);
        if ($customer_id_ws) {
            wsBroadcastToCustomer($customer_id_ws, 'order_update', [
                'order_id' => $order_id,
                'status' => 'cancelado',
                'previous_status' => $pedido['status'],
                'motivo' => $motivo,
            ]);
        }
        wsBroadcastToOrder($order_id, 'order_update', [
            'order_id' => $order_id,
            'status' => 'cancelado',
            'motivo' => $motivo,
        ]);
    } catch (\Throwable $e) {}

    // Cancel repasses (partner payouts) — prevents money leak on refused orders
    try {
        require_once dirname(__DIR__, 3) . '/includes/classes/OmRepasse.php';
        $repasse = new OmRepasse($db);
        $stmtRepasses = $db->prepare("SELECT id FROM om_repasses WHERE order_id = ? AND order_type = 'mercado' AND status IN ('hold', 'pendente')");
        $stmtRepasses->execute([$order_id]);
        foreach ($stmtRepasses->fetchAll(PDO::FETCH_COLUMN) as $repasseId) {
            $repasse->cancelar((int)$repasseId, "Pedido #$order_id recusado: $motivo", 'sistema');
        }
    } catch (Exception $repErr) {
        error_log("[recusar] Erro cancelar repasse pedido #$order_id: " . $repErr->getMessage());
    }

    // Estornar Stripe APOS commit (external call outside transaction)
    if ($needsStripeRefund) {
        try {
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
            if ($STRIPE_SK) {
                // Idempotency-Key prevents double refunds on retries/timeouts
                $idempotencyKey = "refund_recusar_{$order_id}_{$stripePi}";
                $ch = curl_init("https://api.stripe.com/v1/refunds");
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => http_build_query([
                        'payment_intent' => $stripePi,
                        'reason' => 'requested_by_customer',
                        'metadata[order_id]' => $order_id,
                        'metadata[source]' => 'superbora_partner_reject',
                    ]),
                    CURLOPT_HTTPHEADER => [
                        'Authorization: Bearer ' . $STRIPE_SK,
                        'Content-Type: application/x-www-form-urlencoded',
                        'Stripe-Version: 2023-10-16',
                        'Idempotency-Key: ' . $idempotencyKey,
                    ],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 15,
                ]);
                $refundResult = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                $refundData = json_decode($refundResult, true);
                $refundOk = $httpCode >= 200 && $httpCode < 300 && !empty($refundData['id']);
                if ($refundOk) {
                    error_log("[recusar] Stripe refund OK para pedido #$order_id PI=$stripePi refund_id={$refundData['id']}");
                    $db->prepare("UPDATE om_market_orders SET notes = COALESCE(notes,'') || ? WHERE order_id = ?")
                       ->execute([" [REFUND OK: {$refundData['id']}]", $order_id]);
                } else {
                    error_log("[recusar] FALHA refund Stripe PI=$stripePi code=$httpCode resp=$refundResult");
                    $db->prepare("UPDATE om_market_orders SET notes = COALESCE(notes,'') || ' [REFUND FAILED]' WHERE order_id = ?")->execute([$order_id]);
                }
            }
        } catch (Exception $refErr) {
            error_log("[recusar] Erro refund: " . $refErr->getMessage());
        }
    }

    // Estornar PIX via EFI APOS commit (external call outside transaction)
    if ($needsEfiPixRefund) {
        try {
            require_once dirname(__DIR__, 3) . '/includes/classes/EfiClient.php';
            $efi = new EfiClient();

            // If we have e2eId, refund directly. Otherwise, look up via txid.
            $refundE2eId = $efiE2eId;
            if (empty($refundE2eId) && !empty($efiTxid)) {
                $chargeStatus = $efi->checkChargeStatus($efiTxid);
                $refundE2eId = $chargeStatus['e2e_id'] ?? '';
            }

            if (!empty($refundE2eId)) {
                $refundAmount = (float)$pedido['total'];
                $pixRefundResult = $efi->refundPix($refundE2eId, $refundAmount);
                if ($pixRefundResult['success']) {
                    error_log("[recusar] EFI PIX refund OK para pedido #$order_id e2e=$refundE2eId");
                    $db->prepare("UPDATE om_market_orders SET notes = COALESCE(notes,'') || ? WHERE order_id = ?")
                       ->execute([" [PIX REFUND OK: {$pixRefundResult['devolucao_id']}]", $order_id]);
                } else {
                    error_log("[recusar] FALHA EFI PIX refund e2e=$refundE2eId error=" . ($pixRefundResult['error'] ?? ''));
                    $db->prepare("UPDATE om_market_orders SET notes = COALESCE(notes,'') || ' [PIX REFUND FAILED - MANUAL]' WHERE order_id = ?")
                       ->execute([$order_id]);
                }
            } else {
                error_log("[recusar] No e2eId found for PIX refund pedido #$order_id");
                $db->prepare("UPDATE om_market_orders SET notes = COALESCE(notes,'') || ' [PIX REFUND NO E2EID]' WHERE order_id = ?")
                   ->execute([$order_id]);
            }
        } catch (Exception $pixErr) {
            error_log("[recusar] Erro EFI PIX refund: " . $pixErr->getMessage());
            $db->prepare("UPDATE om_market_orders SET notes = COALESCE(notes,'') || ' [PIX REFUND ERROR]' WHERE order_id = ?")
               ->execute([$order_id]);
        }
    }

    // Estornar cartao EFI APOS commit
    if ($needsEfiCardRefund) {
        try {
            require_once dirname(__DIR__, 3) . '/includes/classes/EfiClient.php';
            $efi = new EfiClient();
            $cardRefundResult = $efi->refundCard($efiChargeId);
            if ($cardRefundResult['success']) {
                error_log("[recusar] EFI card refund OK para pedido #$order_id chargeId=$efiChargeId");
                $db->prepare("UPDATE om_market_orders SET notes = COALESCE(notes,'') || ' [CARD REFUND OK]' WHERE order_id = ?")
                   ->execute([$order_id]);
            } else {
                error_log("[recusar] FALHA EFI card refund chargeId=$efiChargeId");
                $db->prepare("UPDATE om_market_orders SET notes = COALESCE(notes,'') || ' [CARD REFUND FAILED]' WHERE order_id = ?")
                   ->execute([$order_id]);
            }
        } catch (Exception $cardErr) {
            error_log("[recusar] Erro EFI card refund: " . $cardErr->getMessage());
        }
    }

    // Notificar cliente
    $customer_id = (int)($pedido['customer_id'] ?? 0);
    if ($customer_id) {
        notifyCustomer($db, $customer_id,
            'Pedido cancelado',
            "Seu pedido #{$pedido['order_number']} foi cancelado. Motivo: $motivo",
            '/mercado/'
        );
    }

    // WhatsApp notification (never breaks the flow)
    try {
        $customerPhone = $pedido['customer_phone'] ?? '';
        if ($customerPhone) {
            $waResult = whatsappOrderCancelled($customerPhone, $pedido['order_number'], $motivo);
            error_log("[recusar] WhatsApp pedido #{$pedido['order_number']} phone=****" . substr($customerPhone, -4) . " success=" . ($waResult['success'] ? 'yes' : 'no'));
        }
    } catch (\Throwable $waErr) {
        error_log("[recusar] WhatsApp error: " . $waErr->getMessage());
    }

    // Cancel BoraUm delivery if dispatched
    try {
        $stmtEntrega = $db->prepare("SELECT boraum_delivery_id FROM om_entregas WHERE referencia_id = ? AND origem_sistema = 'mercado' AND status NOT IN ('cancelled', 'delivered') LIMIT 1");
        $stmtEntrega->execute([$order_id]);
        $entregaRow = $stmtEntrega->fetch();
        if ($entregaRow && !empty($entregaRow['boraum_delivery_id'])) {
            require_once __DIR__ . '/../helpers/delivery.php';
            cancelBoraUmDelivery($entregaRow['boraum_delivery_id'], "Pedido recusado pelo parceiro: $motivo");
            $db->prepare("UPDATE om_entregas SET status = 'cancelled', cancelled_at = NOW() WHERE referencia_id = ? AND origem_sistema = 'mercado'")->execute([$order_id]);
        }
    } catch (\Throwable $boraErr) {
        error_log("[recusar] Erro cancelar BoraUm pedido #$order_id: " . $boraErr->getMessage());
    }

    error_log("[recusar] Pedido #$order_id recusado por parceiro #$mercado_id | Motivo: $motivo");

    response(true, [
        "order_id" => $order_id,
        "status" => "cancelado",
        "motivo" => $motivo
    ], "Pedido recusado");

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("[recusar] Erro: " . $e->getMessage());
    response(false, null, "Erro ao recusar pedido", 500);
}
