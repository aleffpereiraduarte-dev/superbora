<?php
/**
 * CRON: Refund Processor
 * Run every 5 minutes via crontab
 *
 * Processes approved refunds into actual money movement:
 * 1. Approved refunds → credit to customer wallet
 * 2. Failed refunds → retry with exponential backoff
 * 3. Delivery fee refunds on cancelled orders
 * 4. Reconciliation: marks processed after wallet credit confirmed
 */

$isCli = php_sapi_name() === 'cli';

function cron_log($msg) {
    global $isCli;
    $line = "[" . date('Y-m-d H:i:s') . "] [refund-processor] {$msg}";
    if ($isCli) echo $line . "\n";
    error_log($line);
}

try {
    $db = new PDO(
        "pgsql:host=147.93.12.236;port=5432;dbname=love1",
        'love1', 'Aleff2009@',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    // Ensure retry columns exist
    $db->exec("
        ALTER TABLE om_market_refunds ADD COLUMN IF NOT EXISTS retry_count INT DEFAULT 0;
        ALTER TABLE om_market_refunds ADD COLUMN IF NOT EXISTS next_retry_at TIMESTAMP;
        ALTER TABLE om_market_refunds ADD COLUMN IF NOT EXISTS processed_at TIMESTAMP;
        ALTER TABLE om_market_refunds ADD COLUMN IF NOT EXISTS processing_method VARCHAR(30);
        ALTER TABLE om_market_refunds ADD COLUMN IF NOT EXISTS error_body TEXT;
    ");

    $stats = [
        'checked' => 0,
        'processed_wallet' => 0,
        'delivery_fee_refunds' => 0,
        'retries' => 0,
        'failed' => 0,
        'total_amount' => 0,
        'errors' => 0,
    ];

    // ============================================================
    // 1. PROCESS APPROVED REFUNDS → WALLET CREDIT
    // ============================================================
    cron_log("--- Processing approved refunds ---");

    $stmtApproved = $db->query("
        SELECT r.refund_id, r.order_id, r.amount, r.reason, r.retry_count,
               r.created_at, o.customer_id
        FROM om_market_refunds r
        LEFT JOIN om_market_orders o ON o.order_id = r.order_id
        WHERE r.status = 'approved'
          AND (r.next_retry_at IS NULL OR r.next_retry_at <= NOW())
          AND r.retry_count < 5
        ORDER BY r.created_at ASC
        LIMIT 100
    ");
    $approvedRefunds = $stmtApproved->fetchAll();
    $stats['checked'] = count($approvedRefunds);

    foreach ($approvedRefunds as $refund) {
        $refundId = (int)$refund['refund_id'];
        $customerId = (int)($refund['customer_id'] ?? 0);
        $amount = (float)$refund['amount'];
        $orderId = (int)$refund['order_id'];
        $retryCount = (int)$refund['retry_count'];

        try {
            $db->beginTransaction();

            // Credit customer wallet
            if (!$customerId) {
                throw new Exception("Pedido #{$orderId} sem customer_id associado");
            }
            $stmtWallet = $db->prepare("
                UPDATE om_customer_wallet
                SET balance = COALESCE(balance, 0) + ?
                WHERE customer_id = ?
                RETURNING balance
            ");
            $stmtWallet->execute([$amount, $customerId]);
            $result = $stmtWallet->fetch();

            if (!$result) {
                // Criar wallet se nao existir
                $db->prepare("
                    INSERT INTO om_customer_wallet (customer_id, balance, cashback_balance, points, total_earned, created_at)
                    VALUES (?, ?, 0, 0, 0, NOW())
                ")->execute([$customerId, $amount]);
                $newBalance = $amount;
            } else {
                $newBalance = (float)$result['balance'];
            }

            // Record wallet transaction
            $db->prepare("
                INSERT INTO om_wallet_transactions (customer_id, order_id, type, amount, balance_before, balance_after, description, reference, created_at)
                VALUES (?, ?, 'credit', ?, ?, ?, ?, ?, NOW())
            ")->execute([$customerId, $orderId, $amount, $newBalance - $amount, $newBalance, 'Reembolso do pedido #' . $orderId . ' - ' . ($refund['reason'] ?? 'reembolso'), 'refund_' . $refundId]);

            // Mark refund as processed
            $db->prepare("
                UPDATE om_market_refunds
                SET status = 'processed',
                    processed_at = NOW(),
                    processing_method = 'wallet_credit',
                    error_body = NULL
                WHERE refund_id = ?
            ")->execute([$refundId]);

            // Update order timeline
            $timelineDesc = "Reembolso de R\$" . number_format($amount, 2, '.', '') . " creditado na carteira do cliente. Saldo: R\$" . number_format($newBalance, 2, '.', '');
            $db->prepare("
                INSERT INTO om_order_timeline (order_id, status, description, actor_type, actor_id, created_at)
                VALUES (?, 'refund_processed', ?, 'system', 0, NOW())
            ")->execute([$orderId, $timelineDesc]);

            // Notify customer
            $customerBody = "R\$" . number_format($amount, 2, ',', '.') . " foram creditados na sua carteira referente ao pedido #{$orderId}. Saldo atual: R\$" . number_format($newBalance, 2, ',', '.');
            $db->prepare("
                INSERT INTO om_notifications (user_id, user_type, title, body, type, data, created_at)
                VALUES (?, 'customer', 'Reembolso creditado!', ?, 'refund_processed', ?::jsonb, NOW())
            ")->execute([$customerId, $customerBody, json_encode(['reference_type' => 'order', 'reference_id' => $orderId])]);

            $db->commit();
            $stats['processed_wallet']++;
            $stats['total_amount'] += $amount;
            cron_log("PROCESSED reembolso #{$refundId}: R\${$amount} → carteira cliente #{$customerId} (pedido #{$orderId})");

        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();

            // Calculate next retry with exponential backoff
            $nextRetry = $retryCount + 1;
            $backoffMinutes = pow(2, $nextRetry) * 5; // 10m, 20m, 40m, 80m, 160m
            $nextRetryAt = date('Y-m-d H:i:s', time() + ($backoffMinutes * 60));

            if ($nextRetry >= 5) {
                // Max retries reached - mark as failed, alert admin
                $db->prepare("
                    UPDATE om_market_refunds
                    SET status = 'failed',
                        retry_count = ?,
                        error_body = ?
                    WHERE refund_id = ?
                ")->execute([$nextRetry, $e->getMessage(), $refundId]);

                $failBody = "Reembolso #{$refundId} (R\${$amount}, pedido #{$orderId}) falhou apos 5 tentativas: " . $e->getMessage();
                $db->prepare("
                    INSERT INTO om_notifications (user_id, user_type, title, body, type, data, created_at)
                    VALUES (1, 'admin', 'FALHA: Reembolso nao processado', ?, 'refund_failed', ?::jsonb, NOW())
                ")->execute([$failBody, json_encode(['reference_type' => 'refund', 'reference_id' => $refundId])]);

                $stats['failed']++;
                cron_log("FAILED reembolso #{$refundId} apos 5 tentativas: " . $e->getMessage());
            } else {
                $db->prepare("
                    UPDATE om_market_refunds
                    SET retry_count = ?,
                        next_retry_at = ?,
                        error_body = ?
                    WHERE refund_id = ?
                ")->execute([$nextRetry, $nextRetryAt, $e->getMessage(), $refundId]);

                $stats['retries']++;
                cron_log("RETRY #{$nextRetry} reembolso #{$refundId} agendado para {$nextRetryAt}: " . $e->getMessage());
            }

            $stats['errors']++;
        }
    }

    // ============================================================
    // 2. DELIVERY FEE REFUNDS FOR CANCELLED ORDERS
    // ============================================================
    cron_log("--- Checking delivery fee refunds for cancelled orders ---");

    $stmtCancelled = $db->query("
        SELECT o.order_id, o.customer_id, o.delivery_fee
        FROM om_market_orders o
        WHERE o.status IN ('cancelado', 'cancelled')
          AND o.delivery_fee > 0
          AND o.updated_at > NOW() - INTERVAL '7 days'
          AND NOT EXISTS (
              SELECT 1 FROM om_market_refunds r
              WHERE r.order_id = o.order_id
                AND r.reason LIKE '%delivery_fee%'
          )
        ORDER BY o.updated_at DESC
        LIMIT 50
    ");
    $cancelledNoFeeRefund = $stmtCancelled->fetchAll();

    foreach ($cancelledNoFeeRefund as $order) {
        try {
            $orderId = (int)$order['order_id'];
            $customerId = (int)$order['customer_id'];
            $deliveryFee = (float)$order['delivery_fee'];

            if ($deliveryFee <= 0) continue;

            $db->prepare("
                INSERT INTO om_market_refunds (order_id, amount, reason, status, created_at)
                VALUES (?, ?, 'delivery_fee_cancelled_order', 'approved', NOW())
            ")->execute([$orderId, $deliveryFee]);

            $stats['delivery_fee_refunds']++;
            cron_log("DELIVERY FEE refund R\${$deliveryFee} para pedido cancelado #{$orderId}");
        } catch (Exception $e) {
            $stats['errors']++;
            cron_log("ERRO delivery fee #{$orderId}: " . $e->getMessage());
        }
    }

    // ============================================================
    // SUMMARY
    // ============================================================
    cron_log("=== RESUMO ===");
    cron_log("Verificados: {$stats['checked']}");
    cron_log("Processados (wallet): {$stats['processed_wallet']}");
    cron_log("Taxa entrega reembolsada: {$stats['delivery_fee_refunds']}");
    cron_log("Reagendados (retry): {$stats['retries']}");
    cron_log("Falhados (max retry): {$stats['failed']}");
    cron_log("Total processado: R\$" . number_format($stats['total_amount'], 2, '.', ''));
    cron_log("Erros: {$stats['errors']}");

    if (!$isCli) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'stats' => $stats, 'timestamp' => date('c')]);
    }

} catch (Exception $e) {
    cron_log("ERRO FATAL: " . $e->getMessage());
    if (!$isCli) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit(1);
}
