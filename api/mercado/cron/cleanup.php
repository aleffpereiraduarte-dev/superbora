<?php
/**
 * CRON: Limpeza e manutencao automatica
 * Executar a cada 5 minutos:
 *   crontab: STAR/5 * * * * php /var/www/html/api/mercado/cron/cleanup.php
 *   (onde STAR = asterisco)
 *
 * Tarefas:
 * 1. Expirar pedidos PIX nao pagos (10 min)
 * 2. Expirar pedidos pendentes nao aceitos pelo parceiro (15 min)
 * 3. Liberar repasses em hold apos 2 horas
 * 4. Expirar cashback vencido
 * 5. Limpar push tokens inativos (30 dias)
 * 6. Expirar/limpar PIX intents pendentes
 */

// Prevent web access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once dirname(__DIR__) . '/config/database.php';

// ─── Concurrent execution guard ─────────────────────────────
$lockFile = '/tmp/superbora_cron_cleanup.lock';
$lockFp = fopen($lockFile, 'w');
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    echo "[" . date('H:i:s') . "] Another cron instance is running. Exiting.\n";
    exit(0);
}

$db = getDB();
$now = date('Y-m-d H:i:s');
$log = function(string $msg) { echo "[" . date('H:i:s') . "] $msg\n"; };

$log("=== Cron cleanup started ===");

// ═══════════════════════════════════════════════════════════════
// 1. Expirar pedidos PIX nao pagos (timer_expires passou)
// ═══════════════════════════════════════════════════════════════
try {
    // First, SELECT candidates without locking
    $stmt = $db->prepare("
        SELECT order_id FROM om_market_orders
        WHERE status = 'pendente'
          AND forma_pagamento = 'pix'
          AND timer_expires IS NOT NULL
          AND timer_expires < NOW()
    ");
    $stmt->execute();
    $candidates = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($candidates)) {
        $expiredIds = [];

        foreach ($candidates as $orderId) {
            try {
                $db->beginTransaction();

                // Lock the row and verify it's still pendente
                $stmtLock = $db->prepare("
                    SELECT order_id, customer_id, loyalty_points_used, coupon_id
                    FROM om_market_orders
                    WHERE order_id = ?
                      AND status = 'pendente'
                      AND forma_pagamento = 'pix'
                      AND timer_expires IS NOT NULL
                      AND timer_expires < NOW()
                    FOR UPDATE
                ");
                $stmtLock->execute([$orderId]);
                $orderData = $stmtLock->fetch();

                if (!$orderData) {
                    // Order was already handled by another process
                    $db->rollBack();
                    continue;
                }

                // Cancel the order
                $db->prepare("
                    UPDATE om_market_orders
                    SET status = 'cancelado',
                        cancel_reason = 'PIX nao pago — expirado automaticamente',
                        cancelled_at = NOW(),
                        date_modified = NOW()
                    WHERE order_id = ?
                ")->execute([$orderId]);

                // Restaurar estoque
                $stmtItens = $db->prepare("SELECT product_id, quantity FROM om_market_order_items WHERE order_id = ?");
                $stmtItens->execute([$orderId]);
                foreach ($stmtItens->fetchAll() as $item) {
                    $db->prepare("UPDATE om_market_products SET quantity = quantity + ? WHERE product_id = ?")
                       ->execute([$item['quantity'], $item['product_id']]);
                }

                // Restaurar cashback usado
                $db->prepare("UPDATE om_cashback SET status = 'available', order_id = NULL WHERE order_id = ? AND status = 'used'")
                   ->execute([$orderId]);

                // Restaurar pontos
                if ((int)$orderData['loyalty_points_used'] > 0) {
                    $db->prepare("UPDATE om_market_loyalty_points SET current_points = current_points + ? WHERE customer_id = ?")
                       ->execute([$orderData['loyalty_points_used'], $orderData['customer_id']]);
                }

                // Restaurar cupom + decrementar current_uses
                if ((int)$orderData['coupon_id'] > 0) {
                    $db->prepare("DELETE FROM om_market_coupon_usage WHERE coupon_id = ? AND customer_id = ? AND order_id = ?")
                       ->execute([$orderData['coupon_id'], $orderData['customer_id'], $orderId]);
                    $db->prepare("UPDATE om_market_coupons SET current_uses = GREATEST(0, current_uses - 1) WHERE id = ?")->execute([$orderData['coupon_id']]);
                }

                $db->commit();
                $expiredIds[] = $orderId;
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $log("  ERRO PIX pedido #{$orderId}: " . $e->getMessage());
            }
        }

        if (!empty($expiredIds)) {
            $log("PIX expirados: " . count($expiredIds) . " pedidos — IDs: " . implode(', ', $expiredIds));
        }
    }
} catch (Exception $e) {
    $log("ERRO PIX: " . $e->getMessage());
}

// ═══════════════════════════════════════════════════════════════
// 2. Expirar pedidos pendentes nao aceitos (15 min para cartao/dinheiro)
// ═══════════════════════════════════════════════════════════════
try {
    // First, SELECT candidates without locking
    // Include 'confirmado' status: Stripe-paid orders where partner never accepted
    $stmt = $db->prepare("
        SELECT order_id FROM om_market_orders
        WHERE status IN ('pendente', 'confirmado')
          AND forma_pagamento != 'pix'
          AND date_added < NOW() - INTERVAL '15 minutes'
    ");
    $stmt->execute();
    $candidates = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($candidates)) {
        $expiredIds = [];
        $refundQueue = []; // Stripe refunds to process after commits

        foreach ($candidates as $orderId) {
            try {
                $db->beginTransaction();

                // Lock the row and verify it's still pendente/confirmado
                $stmtLock = $db->prepare("
                    SELECT order_id, customer_id, loyalty_points_used, coupon_id,
                           forma_pagamento, stripe_payment_intent_id
                    FROM om_market_orders
                    WHERE order_id = ?
                      AND status IN ('pendente', 'confirmado')
                      AND forma_pagamento != 'pix'
                      AND date_added < NOW() - INTERVAL '15 minutes'
                    FOR UPDATE
                ");
                $stmtLock->execute([$orderId]);
                $orderData = $stmtLock->fetch();

                if (!$orderData) {
                    $db->rollBack();
                    continue;
                }

                // Cancel the order
                $db->prepare("
                    UPDATE om_market_orders
                    SET status = 'cancelado',
                        cancel_reason = 'Nao aceito pelo parceiro — expirado automaticamente',
                        cancelled_at = NOW(),
                        date_modified = NOW()
                    WHERE order_id = ?
                ")->execute([$orderId]);

                // Restaurar estoque
                $stmtItens = $db->prepare("SELECT product_id, quantity FROM om_market_order_items WHERE order_id = ?");
                $stmtItens->execute([$orderId]);
                foreach ($stmtItens->fetchAll() as $item) {
                    $db->prepare("UPDATE om_market_products SET quantity = quantity + ? WHERE product_id = ?")
                       ->execute([$item['quantity'], $item['product_id']]);
                }

                // Restaurar cashback usado
                $db->prepare("UPDATE om_cashback SET status = 'available', order_id = NULL WHERE order_id = ? AND status = 'used'")
                   ->execute([$orderId]);

                // Restaurar pontos de fidelidade
                if ((int)$orderData['loyalty_points_used'] > 0) {
                    $db->prepare("UPDATE om_market_loyalty_points SET current_points = current_points + ? WHERE customer_id = ?")
                       ->execute([$orderData['loyalty_points_used'], $orderData['customer_id']]);
                }

                // Restaurar cupom + decrementar current_uses
                if ((int)$orderData['coupon_id'] > 0) {
                    $db->prepare("DELETE FROM om_market_coupon_usage WHERE coupon_id = ? AND customer_id = ? AND order_id = ?")
                       ->execute([$orderData['coupon_id'], $orderData['customer_id'], $orderId]);
                    $db->prepare("UPDATE om_market_coupons SET current_uses = GREATEST(0, current_uses - 1) WHERE id = ?")->execute([$orderData['coupon_id']]);
                }

                $db->commit();
                $expiredIds[] = $orderId;

                // Queue Stripe refund for after commit (don't refund inside transaction)
                if (in_array($orderData['forma_pagamento'], ['stripe_card', 'stripe_wallet', 'credito']) && $orderData['stripe_payment_intent_id']) {
                    $refundQueue[] = ['pi' => $orderData['stripe_payment_intent_id'], 'id' => $orderId];
                }
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $log("  ERRO pendente pedido #{$orderId}: " . $e->getMessage());
            }
        }

        // Process Stripe refunds after all commits (external call outside transactions)
        foreach ($refundQueue as $refund) {
            try {
                refundStripe($refund['pi'], $refund['id']);
                $log("  Stripe refund para pedido #{$refund['id']}");
            } catch (Exception $e) {
                $log("  ERRO refund #{$refund['id']}: " . $e->getMessage());
            }
        }

        if (!empty($expiredIds)) {
            $log("Pendentes expirados: " . count($expiredIds) . " pedidos — IDs: " . implode(', ', $expiredIds));
        }
    }
} catch (Exception $e) {
    $log("ERRO pendentes: " . $e->getMessage());
}

// ═══════════════════════════════════════════════════════════════
// 3. Liberar repasses em hold apos 2 horas
//    Usa OmRepasse::liberar() que: decrementa saldo_pendente,
//    credita saldo_disponivel, desconta saldo_devedor, registra
//    wallet + log, e seta status 'liberado'.
// ═══════════════════════════════════════════════════════════════
try {
    require_once dirname(__DIR__, 3) . '/includes/classes/OmRepasse.php';
    $repasse = om_repasse();
    $repasse->setDb($db);

    // SELECT candidates using hold_until (set by OmRepasse::criar)
    $stmt = $db->prepare("
        SELECT id FROM om_repasses
        WHERE status = 'hold'
          AND hold_until IS NOT NULL
          AND hold_until < NOW()
    ");
    $stmt->execute();
    $candidates = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($candidates)) {
        $releasedIds = [];

        foreach ($candidates as $repasseId) {
            try {
                $result = $repasse->liberar($repasseId);
                if ($result['success']) {
                    $releasedIds[] = $repasseId;
                    $log("  Repasse #{$repasseId} liberado — R$" . number_format($result['valor'] ?? 0, 2, ',', '.'));
                } else {
                    $log("  Repasse #{$repasseId} skip: " . ($result['error'] ?? 'desconhecido'));
                }
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $log("  ERRO liberar repasse #{$repasseId}: " . $e->getMessage());
            }
        }

        if (!empty($releasedIds)) {
            $log("Repasses liberados: " . count($releasedIds) . " — IDs: " . implode(', ', $releasedIds));
        }
    }
} catch (Exception $e) {
    $log("ERRO repasses: " . $e->getMessage());
}

// ═══════════════════════════════════════════════════════════════
// 4. Expirar cashback vencido
// ═══════════════════════════════════════════════════════════════
try {
    $stmt = $db->prepare("
        UPDATE om_cashback
        SET status = 'expired'
        WHERE status = 'available'
          AND expires_at IS NOT NULL
          AND expires_at < NOW()
    ");
    $stmt->execute();
    $expiredCb = $stmt->rowCount();
    if ($expiredCb > 0) {
        $log("Cashback expirados: $expiredCb registros");
    }
} catch (Exception $e) {
    $log("ERRO cashback: " . $e->getMessage());
}

// ═══════════════════════════════════════════════════════════════
// 4b. Promote pending cashback for delivered orders (catch-up)
//     If confirmar-entrega crashed after commit but before cashback update,
//     this ensures pending cashback is eventually promoted to available.
// ═══════════════════════════════════════════════════════════════
try {
    $stmt = $db->prepare("
        UPDATE om_cashback
        SET status = 'available'
        WHERE status = 'pending'
          AND order_id IN (
              SELECT order_id FROM om_market_orders
              WHERE status IN ('entregue', 'retirado', 'finalizado')
          )
    ");
    $stmt->execute();
    $promoted = $stmt->rowCount();
    if ($promoted > 0) {
        $log("Cashback promovido: $promoted registros (pending → available)");
    }
} catch (Exception $e) {
    // Table may not exist
}

// ═══════════════════════════════════════════════════════════════
// 5. Limpar push tokens inativos (30 dias sem uso)
// ═══════════════════════════════════════════════════════════════
try {
    $stmt = $db->prepare("
        DELETE FROM om_market_push_tokens
        WHERE updated_at < NOW() - INTERVAL '30 days'
    ");
    $stmt->execute();
    $cleaned = $stmt->rowCount();
    if ($cleaned > 0) {
        $log("Push tokens limpos: $cleaned");
    }
} catch (Exception $e) {
    // Table may not exist yet
}

// ═══════════════════════════════════════════════════════════════
// 6. Expirar/limpar PIX intents pendentes expirados
// ═══════════════════════════════════════════════════════════════
try {
    // Mark expired intents
    $stmt = $db->prepare("
        UPDATE om_pix_intents
        SET status = 'expired'
        WHERE status = 'pending' AND expires_at < NOW()
    ");
    $stmt->execute();
    $expired = $stmt->rowCount();
    if ($expired > 0) {
        $log("PIX intents expirados: $expired");
    }

    // Clean old expired/cancelled intents (older than 7 days)
    $stmt = $db->prepare("
        DELETE FROM om_pix_intents
        WHERE status IN ('expired', 'cancelled')
        AND created_at < NOW() - INTERVAL '7 days'
    ");
    $stmt->execute();
    $cleaned = $stmt->rowCount();
    if ($cleaned > 0) {
        $log("PIX intents limpos: $cleaned");
    }
} catch (Exception $e) {
    $log("Erro ao limpar PIX intents: " . $e->getMessage());
}

$log("=== Cron cleanup finished ===\n");

// Release file lock
flock($lockFp, LOCK_UN);
fclose($lockFp);

// ─── Helpers ─────────────────────────────────────────────────

function refundStripe(string $paymentIntentId, int $orderId): void {
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

    $idempotencyKey = "refund_cron_expire_{$orderId}_{$paymentIntentId}";
    $ch = curl_init("https://api.stripe.com/v1/refunds");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'payment_intent' => $paymentIntentId,
            'reason' => 'requested_by_customer',
            'metadata[order_id]' => $orderId,
            'metadata[source]' => 'superbora_cron_expire',
        ]),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $STRIPE_SK,
            'Content-Type: application/x-www-form-urlencoded',
            'Idempotency-Key: ' . $idempotencyKey,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $refundData = json_decode($response, true);
    $refundOk = $httpCode >= 200 && $httpCode < 300 && !empty($refundData['id']);

    // Track refund result in order notes for audit trail
    try {
        if ($refundOk) {
            error_log("[cron/cleanup] Stripe refund OK for order #$orderId PI=$paymentIntentId refund_id={$refundData['id']}");
            $db->prepare("UPDATE om_market_orders SET notes = COALESCE(notes,'') || ? WHERE order_id = ?")
               ->execute([" [CRON REFUND OK: {$refundData['id']}]", $orderId]);
        } else {
            error_log("[cron/cleanup] Stripe refund FAILED for PI $paymentIntentId (order #$orderId): HTTP $httpCode — $response");
            $db->prepare("UPDATE om_market_orders SET notes = COALESCE(notes,'') || ? WHERE order_id = ?")
               ->execute([" [CRON REFUND FAILED: HTTP $httpCode]", $orderId]);
        }
    } catch (Exception $e) {
        error_log("[cron/cleanup] Failed to update refund notes for order #$orderId: " . $e->getMessage());
    }
}
