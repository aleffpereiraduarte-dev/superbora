<?php
/**
 * CRON: Auto-refund de pedidos abandonados pela loja
 * Executar a cada 15 minutos:
 *   crontab: STAR/15 * * * * php /var/www/html/api/mercado/cron/auto-refund.php
 *   (onde STAR = asterisco)
 *
 * Logica:
 * 1. Pedidos 'aceito'/'em_preparo' por mais de 2 horas sem progresso
 * 2. Pedidos 'em_entrega' por mais de 3 horas sem entrega
 * 3. Pedidos 'pronto' por mais de 4 horas sem coleta
 * 4. Para cada: cancela, estorna pagamento (Stripe/PIX), restaura estoque,
 *    estorna cashback, restaura cupom, notifica cliente
 * 5. Log completo para auditoria
 */

// Prevent web access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/helpers/notify.php';
require_once dirname(__DIR__) . '/helpers/ws-customer-broadcast.php';

// ─── Concurrent execution guard ─────────────────────────────
$lockFile = '/tmp/superbora_cron_auto_refund.lock';
$lockFp = fopen($lockFile, 'w');
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    echo "[" . date('H:i:s') . "] Another auto-refund instance is running. Exiting.\n";
    exit(0);
}

$db = getDB();
$log = function(string $msg) { echo "[" . date('H:i:s') . "] $msg\n"; };

$log("=== Auto-refund cron started ===");

$totalRefunded = 0;
$refundQueue = []; // External API calls after DB commits

// ═══════════════════════════════════════════════════════════════
// 1. Pedidos 'aceito'/'em_preparo' por mais de 2 horas
// ═══════════════════════════════════════════════════════════════
try {
    $stmt = $db->prepare("
        SELECT order_id FROM om_market_orders
        WHERE status IN ('aceito', 'em_preparo', 'preparando')
          AND date_modified < NOW() - INTERVAL '2 hours'
          AND status != 'cancelado'
        ORDER BY date_modified ASC
        LIMIT 50
    ");
    $stmt->execute();
    $candidates = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($candidates)) {
        $log("Fase 1: " . count($candidates) . " pedidos aceito/em_preparo > 2h");
        foreach ($candidates as $orderId) {
            $result = processAutoRefund($db, $orderId, 'Pedido nao preparado pela loja — cancelado automaticamente apos 2 horas', $log);
            if ($result) {
                $totalRefunded++;
                if (!empty($result['refund_info'])) {
                    $refundQueue[] = $result['refund_info'];
                }
            }
        }
    }
} catch (Exception $e) {
    $log("ERRO fase 1: " . $e->getMessage());
}

// ═══════════════════════════════════════════════════════════════
// 2. Pedidos 'em_entrega' por mais de 3 horas
// ═══════════════════════════════════════════════════════════════
try {
    $stmt = $db->prepare("
        SELECT order_id FROM om_market_orders
        WHERE status IN ('em_entrega', 'em_transito', 'delivering')
          AND date_modified < NOW() - INTERVAL '3 hours'
          AND status != 'cancelado'
        ORDER BY date_modified ASC
        LIMIT 50
    ");
    $stmt->execute();
    $candidates = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($candidates)) {
        $log("Fase 2: " . count($candidates) . " pedidos em_entrega > 3h");
        foreach ($candidates as $orderId) {
            $result = processAutoRefund($db, $orderId, 'Entrega nao concluida — cancelado automaticamente apos 3 horas', $log);
            if ($result) {
                $totalRefunded++;
                if (!empty($result['refund_info'])) {
                    $refundQueue[] = $result['refund_info'];
                }
            }
        }
    }
} catch (Exception $e) {
    $log("ERRO fase 2: " . $e->getMessage());
}

// ═══════════════════════════════════════════════════════════════
// 3. Pedidos 'pronto' por mais de 4 horas sem coleta
// ═══════════════════════════════════════════════════════════════
try {
    $stmt = $db->prepare("
        SELECT order_id FROM om_market_orders
        WHERE status IN ('pronto', 'pronto_coleta', 'pronto_retirada')
          AND date_modified < NOW() - INTERVAL '4 hours'
          AND status != 'cancelado'
        ORDER BY date_modified ASC
        LIMIT 50
    ");
    $stmt->execute();
    $candidates = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($candidates)) {
        $log("Fase 3: " . count($candidates) . " pedidos pronto > 4h sem coleta");
        foreach ($candidates as $orderId) {
            $result = processAutoRefund($db, $orderId, 'Pedido pronto nao coletado — cancelado automaticamente apos 4 horas', $log);
            if ($result) {
                $totalRefunded++;
                if (!empty($result['refund_info'])) {
                    $refundQueue[] = $result['refund_info'];
                }
            }
        }
    }
} catch (Exception $e) {
    $log("ERRO fase 3: " . $e->getMessage());
}

// ═══════════════════════════════════════════════════════════════
// 4. Processar estornos externos (Stripe/PIX) apos todos os commits
// ═══════════════════════════════════════════════════════════════
foreach ($refundQueue as $info) {
    $orderId = $info['order_id'];

    // Stripe refund
    if (!empty($info['stripe_pi'])) {
        try {
            refundStripeAutoRefund($info['stripe_pi'], $orderId);
            $log("  Stripe refund processado pedido #$orderId");
        } catch (Exception $e) {
            $log("  ERRO Stripe refund pedido #$orderId: " . $e->getMessage());
        }
    }

    // PIX refund (Woovi)
    if (!empty($info['pix_correlation'])) {
        try {
            refundPixAutoRefund($info['pix_correlation'], $orderId);
            $log("  PIX refund processado pedido #$orderId");
        } catch (Exception $e) {
            $log("  ERRO PIX refund pedido #$orderId: " . $e->getMessage());
        }
    }
}

$log("=== Auto-refund cron finished: $totalRefunded pedidos reembolsados ===\n");

// Release file lock
flock($lockFp, LOCK_UN);
fclose($lockFp);


// ═══════════════════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════════════════

/**
 * Processa auto-refund de um pedido individual
 * Retorna array com refund_info para processamento externo, ou null se falhou
 */
function processAutoRefund(PDO $db, int $orderId, string $cancelReason, callable $log): ?array {
    try {
        $db->beginTransaction();

        // Lock row and verify still eligible
        $stmt = $db->prepare("
            SELECT * FROM om_market_orders
            WHERE order_id = ?
              AND status NOT IN ('cancelado', 'entregue', 'retirado', 'finalizado', 'refunded')
            FOR UPDATE
        ");
        $stmt->execute([$orderId]);
        $pedido = $stmt->fetch();

        if (!$pedido) {
            $db->rollBack();
            return null;
        }

        $customerId = (int)($pedido['customer_id'] ?? 0);
        $orderTotal = (float)($pedido['total'] ?? $pedido['final_total'] ?? 0);
        $previousStatus = $pedido['status'];

        // 1. Marcar como cancelado
        $notaCancel = " | Auto-refund: $cancelReason";
        $db->prepare("
            UPDATE om_market_orders
            SET status = 'cancelado',
                cancel_reason = ?,
                cancelled_at = NOW(),
                notes = COALESCE(notes, '') || ?,
                date_modified = NOW()
            WHERE order_id = ?
        ")->execute([$cancelReason, $notaCancel, $orderId]);

        // 2. Restaurar estoque
        $stmtItens = $db->prepare("SELECT product_id, quantity FROM om_market_order_items WHERE order_id = ?");
        $stmtItens->execute([$orderId]);
        foreach ($stmtItens->fetchAll() as $item) {
            if ($item['product_id']) {
                $db->prepare("UPDATE om_market_products SET quantity = quantity + ? WHERE product_id = ?")
                   ->execute([$item['quantity'], $item['product_id']]);
            }
        }

        // 3. Restaurar cashback usado
        $cashbackUsed = (float)($pedido['cashback_discount'] ?? 0);
        if ($cashbackUsed > 0) {
            require_once dirname(__DIR__) . '/helpers/cashback.php';
            refundCashback($db, $orderId);
        }

        // 4. Restaurar pontos de fidelidade
        $pointsUsed = (int)($pedido['loyalty_points_used'] ?? 0);
        if ($pointsUsed > 0) {
            $db->prepare("UPDATE om_market_loyalty_points SET current_points = current_points + ?, updated_at = NOW() WHERE customer_id = ?")
               ->execute([$pointsUsed, $customerId]);
            $db->prepare("INSERT INTO om_market_loyalty_transactions (customer_id, points, type, source, reference_id, description, created_at) VALUES (?, ?, 'refund', 'auto_refund', ?, ?, NOW())")
               ->execute([$customerId, $pointsUsed, $orderId, "Estorno auto-refund pedido #$orderId"]);
        }

        // 5. Restaurar cupom
        $couponId = (int)($pedido['coupon_id'] ?? 0);
        if ($couponId) {
            $db->prepare("DELETE FROM om_market_coupon_usage WHERE coupon_id = ? AND customer_id = ? AND order_id = ?")
               ->execute([$couponId, $customerId, $orderId]);
            $db->prepare("UPDATE om_market_coupons SET current_uses = GREATEST(0, current_uses - 1) WHERE id = ?")
               ->execute([$couponId]);
        }

        // 6. Liberar shopper se tiver
        if (!empty($pedido['shopper_id'])) {
            $db->prepare("UPDATE om_market_shoppers SET disponivel = 1, pedido_atual_id = NULL WHERE shopper_id = ?")
               ->execute([$pedido['shopper_id']]);
        }

        // 7. Route cleanup (DoubleDash)
        $routeId = (int)($pedido['route_id'] ?? 0);
        if ($routeId) {
            $db->prepare("UPDATE om_delivery_route_stops SET status = 'cancelled' WHERE route_id = ? AND order_id = ?")
               ->execute([$routeId, $orderId]);
            $db->prepare("UPDATE om_delivery_routes SET total_orders = GREATEST(0, total_orders - 1) WHERE route_id = ?")
               ->execute([$routeId]);
        }

        // 8. Log event for audit trail
        try {
            $db->prepare("
                INSERT INTO om_market_order_events (order_id, event_type, message, created_by, created_at)
                VALUES (?, 'auto_refund', ?, 'system_cron', NOW())
            ")->execute([$orderId, $cancelReason]);
        } catch (Exception $e) {
            // Table may not exist
        }

        // 9. Registrar reembolso na tabela om_market_refunds
        try {
            $db->prepare("
                INSERT INTO om_market_refunds (order_id, customer_id, amount, reason, status, admin_note, reviewed_at, created_at)
                VALUES (?, ?, ?, ?, 'approved', 'Auto-refund pelo sistema (pedido abandonado pela loja)', NOW(), NOW())
            ")->execute([$orderId, $customerId, $orderTotal, $cancelReason]);
        } catch (Exception $e) {
            error_log("[auto-refund] Erro ao registrar reembolso: " . $e->getMessage());
        }

        $db->commit();

        // Collect info for external refund processing (after commit)
        $refundInfo = ['order_id' => $orderId];

        $paymentMethod = $pedido['forma_pagamento'] ?? $pedido['payment_method'] ?? '';
        $stripePi = $pedido['stripe_payment_intent_id'] ?? $pedido['payment_id'] ?? '';

        // Stripe refund
        if (in_array($paymentMethod, ['stripe_card', 'stripe_wallet', 'credito']) && $stripePi) {
            $refundInfo['stripe_pi'] = $stripePi;
        }

        // PIX refund
        $needsPixRefund = ($paymentMethod === 'pix') && (
            ($pedido['pagamento_status'] ?? '') === 'pago' ||
            ($pedido['payment_status'] ?? '') === 'paid' ||
            ($pedido['pix_paid'] ?? false)
        );
        if ($needsPixRefund) {
            try {
                $txStmt = $db->prepare("SELECT pagarme_order_id FROM om_pagarme_transacoes WHERE pedido_id = ? AND tipo = 'pix' ORDER BY created_at DESC LIMIT 1");
                $txStmt->execute([$orderId]);
                $txRow = $txStmt->fetch();
                if (!empty($txRow['pagarme_order_id'])) {
                    $refundInfo['pix_correlation'] = $txRow['pagarme_order_id'];
                }
            } catch (Exception $e) {
                error_log("[auto-refund] Erro buscar PIX correlation pedido #$orderId: " . $e->getMessage());
            }
        }

        // WebSocket broadcast (non-blocking)
        try {
            wsBroadcastToCustomer($customerId, 'order_update', [
                'order_id' => $orderId,
                'status' => 'cancelado',
                'previous_status' => $previousStatus,
                'cancellation_fee' => 0,
                'refund_amount' => $orderTotal,
                'auto_refund' => true,
            ]);
            wsBroadcastToOrder($orderId, 'order_update', [
                'order_id' => $orderId,
                'status' => 'cancelado',
            ]);
        } catch (\Throwable $e) {}

        // Push notification to customer
        try {
            notifyCustomer($db, $customerId,
                'Pedido cancelado — reembolso automatico',
                "Seu pedido #{$pedido['order_number']} foi cancelado porque a loja nao processou a tempo. O reembolso de R$ " . number_format($orderTotal, 2, ',', '.') . " sera processado automaticamente.",
                '/mercado/',
                [
                    'order_id' => $orderId,
                    'order_number' => $pedido['order_number'] ?? '',
                    'type' => 'auto_refund',
                ]
            );
        } catch (Exception $e) {
            error_log("[auto-refund] Erro notificar cliente pedido #$orderId: " . $e->getMessage());
        }

        // Notify partner
        $partnerId = (int)($pedido['partner_id'] ?? 0);
        if ($partnerId) {
            try {
                notifyPartner($db, $partnerId,
                    'Pedido cancelado automaticamente',
                    "Pedido #{$pedido['order_number']} foi cancelado e reembolsado ao cliente por falta de processamento.",
                    '/painel/mercado/pedidos.php'
                );
            } catch (Exception $e) {}
        }

        // Cancel BoraUm delivery if dispatched
        try {
            require_once dirname(__DIR__) . '/helpers/delivery.php';
            cancelBoraUmDelivery($db, $orderId);
        } catch (Exception $e) {
            error_log("[auto-refund] Erro cancelar BoraUm pedido #$orderId: " . $e->getMessage());
        }

        $log("  Pedido #$orderId reembolsado (status anterior: $previousStatus, total: R$" . number_format($orderTotal, 2, ',', '.') . ")");

        return ['refund_info' => $refundInfo];

    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $log("  ERRO pedido #$orderId: " . $e->getMessage());
        error_log("[auto-refund] Erro pedido #$orderId: " . $e->getMessage());
        return null;
    }
}

/**
 * Estornar pagamento Stripe (full refund)
 */
function refundStripeAutoRefund(string $paymentIntentId, int $orderId): void {
    global $db;

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
    if (empty($STRIPE_SK)) return;

    $idempotencyKey = "refund_auto_{$orderId}_{$paymentIntentId}";
    $ch = curl_init("https://api.stripe.com/v1/refunds");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'payment_intent' => $paymentIntentId,
            'reason' => 'requested_by_customer',
            'metadata[order_id]' => $orderId,
            'metadata[source]' => 'superbora_auto_refund',
        ]),
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
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $refundData = json_decode($response, true);
    $refundOk = $httpCode >= 200 && $httpCode < 300 && !empty($refundData['id']);

    try {
        if ($refundOk) {
            error_log("[auto-refund] Stripe refund OK pedido #$orderId PI=$paymentIntentId refund_id={$refundData['id']}");
            $db->prepare("UPDATE om_market_orders SET notes = COALESCE(notes, '') || ? WHERE order_id = ?")
               ->execute([" [AUTO-REFUND STRIPE OK: {$refundData['id']}]", $orderId]);
        } else {
            error_log("[auto-refund] Stripe refund FAILED pedido #$orderId PI=$paymentIntentId HTTP=$httpCode response=$response");
            $db->prepare("UPDATE om_market_orders SET notes = COALESCE(notes, '') || ? WHERE order_id = ?")
               ->execute([" [AUTO-REFUND STRIPE FAILED: HTTP $httpCode]", $orderId]);
        }
    } catch (Exception $e) {
        error_log("[auto-refund] Erro atualizar notes Stripe pedido #$orderId: " . $e->getMessage());
    }
}

/**
 * Estornar pagamento PIX via Woovi
 */
function refundPixAutoRefund(string $correlationId, int $orderId): void {
    global $db;

    try {
        require_once dirname(__DIR__, 3) . '/includes/classes/WooviClient.php';
        $woovi = new WooviClient();
        $pixRefundResult = $woovi->refundCharge($correlationId, "Auto-refund pedido #$orderId");

        error_log("[auto-refund] PIX refund pedido #$orderId correlation=$correlationId result=" . json_encode($pixRefundResult['data'] ?? []));

        $pixRefundOk = !empty($pixRefundResult['data']['refund']['status'])
            || !empty($pixRefundResult['data']['status'])
            || (isset($pixRefundResult['success']) && $pixRefundResult['success']);

        if ($pixRefundOk) {
            $db->prepare("UPDATE om_pagarme_transacoes SET status = 'refunded' WHERE pedido_id = ? AND tipo = 'pix'")->execute([$orderId]);
            $db->prepare("UPDATE om_market_orders SET notes = COALESCE(notes, '') || ' [AUTO-REFUND PIX OK]' WHERE order_id = ?")->execute([$orderId]);
        } else {
            error_log("[auto-refund] PIX refund FAILED pedido #$orderId correlation=$correlationId — needs manual processing");
            $db->prepare("UPDATE om_pagarme_transacoes SET status = 'refund_failed' WHERE pedido_id = ? AND tipo = 'pix'")->execute([$orderId]);
            $db->prepare("UPDATE om_market_orders SET notes = COALESCE(notes, '') || ' [AUTO-REFUND PIX FAILED - MANUAL]' WHERE order_id = ?")->execute([$orderId]);
        }
    } catch (Exception $e) {
        error_log("[auto-refund] Erro PIX refund pedido #$orderId: " . $e->getMessage());
        try {
            $db->prepare("UPDATE om_market_orders SET notes = COALESCE(notes, '') || ' [AUTO-REFUND PIX ERROR]' WHERE order_id = ?")->execute([$orderId]);
        } catch (Exception $e2) {}
    }
}
