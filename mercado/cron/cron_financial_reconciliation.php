<?php
/**
 * CRON: Financial Reconciliation
 * Run daily at 3am: 0 3 * * * php /var/www/html/mercado/cron/cron_financial_reconciliation.php
 *
 * Detects financial discrepancies:
 * 1. Wallet balance vs calculated balance from transactions
 * 2. Refund totals exceeding order totals (over-refunds)
 * 3. Orphan transactions referencing non-existent orders/customers
 * 4. Double-processed refunds (same order, processed within 1 minute)
 * 5. Negative wallet balances
 */

$isCli = php_sapi_name() === 'cli';

function cron_log($msg) {
    global $isCli;
    $line = "[" . date('Y-m-d H:i:s') . "] [financial-reconciliation] {$msg}";
    if ($isCli) echo $line . "\n";
    error_log($line);
}

try {
    $db = new PDO(
        "pgsql:host=147.93.12.236;port=5432;dbname=love1",
        'love1', 'Aleff2009@',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    // Ensure om_financial_flags table exists
    $db->exec("
        CREATE TABLE IF NOT EXISTS om_financial_flags (
            flag_id SERIAL PRIMARY KEY,
            flag_type VARCHAR(50) NOT NULL,
            entity_type VARCHAR(30) NOT NULL,
            entity_id INT NOT NULL,
            expected_value DECIMAL(12,2),
            actual_value DECIMAL(12,2),
            difference DECIMAL(12,2),
            details TEXT,
            status VARCHAR(20) DEFAULT 'open',
            resolved_by INT,
            resolved_at TIMESTAMP,
            created_at TIMESTAMP DEFAULT NOW()
        )
    ");

    $stats = [
        'wallets_checked' => 0,
        'balance_mismatches' => 0,
        'over_refunds' => 0,
        'orphan_transactions' => 0,
        'double_refunds' => 0,
        'negative_balances' => 0,
        'flags_created' => 0,
        'critical_notifications' => 0,
        'errors' => 0,
    ];

    // ============================================================
    // 1. WALLET BALANCE VS TRANSACTIONS
    // ============================================================
    cron_log("--- Checking wallet balance vs transactions ---");

    $stmtWallets = $db->query("
        SELECT w.wallet_id, w.customer_id, w.balance,
               COALESCE((
                   SELECT SUM(CASE WHEN t.type = 'credit' THEN t.amount ELSE -t.amount END)
                   FROM om_wallet_transactions t
                   WHERE t.customer_id = w.customer_id
               ), 0) AS calculated_balance
        FROM om_customer_wallet w
        ORDER BY w.customer_id ASC
    ");
    $wallets = $stmtWallets->fetchAll();
    $stats['wallets_checked'] = count($wallets);

    foreach ($wallets as $wallet) {
        try {
            $walletBalance = round((float)$wallet['balance'], 2);
            $calculatedBalance = round((float)$wallet['calculated_balance'], 2);
            $difference = round($walletBalance - $calculatedBalance, 2);

            if (abs($difference) > 0.01) {
                // Check if we already have an open flag for this wallet
                $stmtExisting = $db->prepare("
                    SELECT flag_id FROM om_financial_flags
                    WHERE flag_type = 'balance_mismatch'
                      AND entity_type = 'wallet'
                      AND entity_id = ?
                      AND status = 'open'
                    LIMIT 1
                ");
                $stmtExisting->execute([(int)$wallet['wallet_id']]);
                if ($stmtExisting->fetch()) continue;

                $db->prepare("
                    INSERT INTO om_financial_flags (flag_type, entity_type, entity_id, expected_value, actual_value, difference, details, created_at)
                    VALUES ('balance_mismatch', 'wallet', ?, ?, ?, ?, ?, NOW())
                ")->execute([
                    (int)$wallet['wallet_id'],
                    $calculatedBalance,
                    $walletBalance,
                    $difference,
                    "Cliente #{$wallet['customer_id']}: saldo carteira R\${$walletBalance} vs calculado R\${$calculatedBalance} (diff R\${$difference})"
                ]);

                $stats['balance_mismatches']++;
                $stats['flags_created']++;
                cron_log("FLAG balance_mismatch: wallet #{$wallet['wallet_id']} cliente #{$wallet['customer_id']} saldo={$walletBalance} calc={$calculatedBalance} diff={$difference}");
            }
        } catch (Exception $e) {
            $stats['errors']++;
            cron_log("ERRO verificando wallet #{$wallet['wallet_id']}: " . $e->getMessage());
        }
    }

    // ============================================================
    // 2. REFUND TOTAL VS ORDER TOTAL (OVER-REFUNDS)
    // ============================================================
    cron_log("--- Checking over-refunds ---");

    $stmtOverRefunds = $db->query("
        SELECT r.order_id,
               SUM(r.amount) AS total_refunded,
               o.total AS order_total,
               COALESCE(o.delivery_fee, 0) AS delivery_fee,
               (o.total + COALESCE(o.delivery_fee, 0)) AS max_refundable,
               SUM(r.amount) - (o.total + COALESCE(o.delivery_fee, 0)) AS over_amount
        FROM om_market_refunds r
        JOIN om_market_orders o ON o.order_id = r.order_id
        WHERE r.status IN ('processed', 'approved')
        GROUP BY r.order_id, o.total, o.delivery_fee
        HAVING SUM(r.amount) > (o.total + COALESCE(o.delivery_fee, 0))
    ");
    $overRefunds = $stmtOverRefunds->fetchAll();

    foreach ($overRefunds as $row) {
        try {
            $orderId = (int)$row['order_id'];

            // Check if already flagged
            $stmtExisting = $db->prepare("
                SELECT flag_id FROM om_financial_flags
                WHERE flag_type = 'over_refund'
                  AND entity_type = 'order'
                  AND entity_id = ?
                  AND status = 'open'
                LIMIT 1
            ");
            $stmtExisting->execute([$orderId]);
            if ($stmtExisting->fetch()) continue;

            $maxRefundable = round((float)$row['max_refundable'], 2);
            $totalRefunded = round((float)$row['total_refunded'], 2);
            $overAmount = round((float)$row['over_amount'], 2);

            $db->prepare("
                INSERT INTO om_financial_flags (flag_type, entity_type, entity_id, expected_value, actual_value, difference, details, created_at)
                VALUES ('over_refund', 'order', ?, ?, ?, ?, ?, NOW())
            ")->execute([
                $orderId,
                $maxRefundable,
                $totalRefunded,
                $overAmount,
                "Pedido #{$orderId}: reembolso total R\${$totalRefunded} excede maximo R\${$maxRefundable} (excesso R\${$overAmount})"
            ]);

            // CRITICAL: Notify admin
            $notifBody = "Pedido #{$orderId}: reembolso total R\$" . number_format($totalRefunded, 2, '.', '') . " excede maximo permitido R\$" . number_format($maxRefundable, 2, '.', '') . ". Excesso: R\$" . number_format($overAmount, 2, '.', '') . ".";
            $db->prepare("
                INSERT INTO om_notifications (user_id, user_type, title, body, type, data, created_at)
                VALUES (1, 'admin', 'CRITICO: Over-refund detectado', ?, 'financial_critical', ?::jsonb, NOW())
            ")->execute([
                $notifBody,
                json_encode(['flag_type' => 'over_refund', 'entity_type' => 'order', 'entity_id' => $orderId])
            ]);

            $stats['over_refunds']++;
            $stats['flags_created']++;
            $stats['critical_notifications']++;
            cron_log("CRITICAL over_refund: pedido #{$orderId} refunded={$totalRefunded} max={$maxRefundable} over={$overAmount}");
        } catch (Exception $e) {
            $stats['errors']++;
            cron_log("ERRO over-refund pedido #{$row['order_id']}: " . $e->getMessage());
        }
    }

    // ============================================================
    // 3. ORPHAN TRANSACTIONS (non-existent orders or customers)
    // ============================================================
    cron_log("--- Checking orphan transactions ---");

    // 3a. Transactions referencing non-existent orders
    $stmtOrphanOrders = $db->query("
        SELECT t.transaction_id, t.customer_id, t.order_id, t.type, t.amount
        FROM om_wallet_transactions t
        WHERE t.order_id IS NOT NULL
          AND NOT EXISTS (
              SELECT 1 FROM om_market_orders o WHERE o.order_id = t.order_id
          )
        ORDER BY t.created_at DESC
        LIMIT 200
    ");
    $orphanOrderTxns = $stmtOrphanOrders->fetchAll();

    foreach ($orphanOrderTxns as $txn) {
        try {
            $txnId = (int)$txn['transaction_id'];

            $stmtExisting = $db->prepare("
                SELECT flag_id FROM om_financial_flags
                WHERE flag_type = 'orphan_transaction_order'
                  AND entity_type = 'transaction'
                  AND entity_id = ?
                  AND status = 'open'
                LIMIT 1
            ");
            $stmtExisting->execute([$txnId]);
            if ($stmtExisting->fetch()) continue;

            $db->prepare("
                INSERT INTO om_financial_flags (flag_type, entity_type, entity_id, expected_value, actual_value, difference, details, created_at)
                VALUES ('orphan_transaction_order', 'transaction', ?, 0, ?, ?, ?, NOW())
            ")->execute([
                $txnId,
                (float)$txn['amount'],
                (float)$txn['amount'],
                "Transacao #{$txnId} ({$txn['type']} R\${$txn['amount']}) referencia pedido #{$txn['order_id']} inexistente. Cliente #{$txn['customer_id']}."
            ]);

            $stats['orphan_transactions']++;
            $stats['flags_created']++;
            cron_log("FLAG orphan_transaction_order: txn #{$txnId} ref pedido #{$txn['order_id']} inexistente");
        } catch (Exception $e) {
            $stats['errors']++;
            cron_log("ERRO orphan order txn #{$txn['transaction_id']}: " . $e->getMessage());
        }
    }

    // 3b. Transactions referencing non-existent customers
    $stmtOrphanCustomers = $db->query("
        SELECT t.transaction_id, t.customer_id, t.order_id, t.type, t.amount
        FROM om_wallet_transactions t
        WHERE NOT EXISTS (
            SELECT 1 FROM om_customer_wallet w WHERE w.customer_id = t.customer_id
        )
        ORDER BY t.created_at DESC
        LIMIT 200
    ");
    $orphanCustomerTxns = $stmtOrphanCustomers->fetchAll();

    foreach ($orphanCustomerTxns as $txn) {
        try {
            $txnId = (int)$txn['transaction_id'];

            $stmtExisting = $db->prepare("
                SELECT flag_id FROM om_financial_flags
                WHERE flag_type = 'orphan_transaction_customer'
                  AND entity_type = 'transaction'
                  AND entity_id = ?
                  AND status = 'open'
                LIMIT 1
            ");
            $stmtExisting->execute([$txnId]);
            if ($stmtExisting->fetch()) continue;

            $db->prepare("
                INSERT INTO om_financial_flags (flag_type, entity_type, entity_id, expected_value, actual_value, difference, details, created_at)
                VALUES ('orphan_transaction_customer', 'transaction', ?, 0, ?, ?, ?, NOW())
            ")->execute([
                $txnId,
                (float)$txn['amount'],
                (float)$txn['amount'],
                "Transacao #{$txnId} ({$txn['type']} R\${$txn['amount']}) referencia cliente #{$txn['customer_id']} sem carteira. Pedido #{$txn['order_id']}."
            ]);

            $stats['orphan_transactions']++;
            $stats['flags_created']++;
            cron_log("FLAG orphan_transaction_customer: txn #{$txnId} ref cliente #{$txn['customer_id']} sem wallet");
        } catch (Exception $e) {
            $stats['errors']++;
            cron_log("ERRO orphan customer txn #{$txn['transaction_id']}: " . $e->getMessage());
        }
    }

    // ============================================================
    // 4. DOUBLE-PROCESSED REFUNDS
    // ============================================================
    cron_log("--- Checking double-processed refunds ---");

    $stmtDoubles = $db->query("
        SELECT r1.refund_id AS refund_id_1,
               r2.refund_id AS refund_id_2,
               r1.order_id,
               r1.amount AS amount_1,
               r2.amount AS amount_2,
               r1.created_at AS created_1,
               r2.created_at AS created_2
        FROM om_market_refunds r1
        JOIN om_market_refunds r2
            ON r1.order_id = r2.order_id
            AND r1.refund_id < r2.refund_id
            AND r2.status = 'processed'
            AND ABS(EXTRACT(EPOCH FROM (r2.created_at - r1.created_at))) <= 60
        WHERE r1.status = 'processed'
        ORDER BY r1.created_at DESC
        LIMIT 100
    ");
    $doubleRefunds = $stmtDoubles->fetchAll();

    foreach ($doubleRefunds as $row) {
        try {
            $orderId = (int)$row['order_id'];
            $refundId1 = (int)$row['refund_id_1'];
            $refundId2 = (int)$row['refund_id_2'];

            // Check if already flagged (use the second refund_id as entity)
            $stmtExisting = $db->prepare("
                SELECT flag_id FROM om_financial_flags
                WHERE flag_type = 'double_refund'
                  AND entity_type = 'refund'
                  AND entity_id = ?
                  AND status = 'open'
                LIMIT 1
            ");
            $stmtExisting->execute([$refundId2]);
            if ($stmtExisting->fetch()) continue;

            $amount1 = round((float)$row['amount_1'], 2);
            $amount2 = round((float)$row['amount_2'], 2);
            $totalDouble = $amount1 + $amount2;

            $db->prepare("
                INSERT INTO om_financial_flags (flag_type, entity_type, entity_id, expected_value, actual_value, difference, details, created_at)
                VALUES ('double_refund', 'refund', ?, ?, ?, ?, ?, NOW())
            ")->execute([
                $refundId2,
                $amount1,
                $totalDouble,
                $amount2,
                "Pedido #{$orderId}: reembolsos #{$refundId1} (R\${$amount1}) e #{$refundId2} (R\${$amount2}) processados com menos de 1 minuto de diferenca. Provavel duplicata."
            ]);

            $stats['double_refunds']++;
            $stats['flags_created']++;
            cron_log("FLAG double_refund: pedido #{$orderId} refunds #{$refundId1}+#{$refundId2} processados em <1min");
        } catch (Exception $e) {
            $stats['errors']++;
            cron_log("ERRO double refund pedido #{$row['order_id']}: " . $e->getMessage());
        }
    }

    // ============================================================
    // 5. NEGATIVE BALANCES
    // ============================================================
    cron_log("--- Checking negative balances ---");

    $stmtNegative = $db->query("
        SELECT w.wallet_id, w.customer_id, w.balance
        FROM om_customer_wallet w
        WHERE w.balance < 0
        ORDER BY w.balance ASC
    ");
    $negativeWallets = $stmtNegative->fetchAll();

    foreach ($negativeWallets as $wallet) {
        try {
            $walletId = (int)$wallet['wallet_id'];
            $customerId = (int)$wallet['customer_id'];
            $balance = round((float)$wallet['balance'], 2);

            // Check if already flagged
            $stmtExisting = $db->prepare("
                SELECT flag_id FROM om_financial_flags
                WHERE flag_type = 'negative_balance'
                  AND entity_type = 'wallet'
                  AND entity_id = ?
                  AND status = 'open'
                LIMIT 1
            ");
            $stmtExisting->execute([$walletId]);
            if ($stmtExisting->fetch()) continue;

            $db->prepare("
                INSERT INTO om_financial_flags (flag_type, entity_type, entity_id, expected_value, actual_value, difference, details, created_at)
                VALUES ('negative_balance', 'wallet', ?, 0, ?, ?, ?, NOW())
            ")->execute([
                $walletId,
                $balance,
                $balance,
                "Cliente #{$customerId}: saldo negativo R\${$balance}. Requer investigacao imediata."
            ]);

            // CRITICAL: Notify admin
            $notifBody = "Cliente #{$customerId} possui saldo negativo de R\$" . number_format(abs($balance), 2, '.', '') . " na carteira #{$walletId}. Investigar imediatamente.";
            $db->prepare("
                INSERT INTO om_notifications (user_id, user_type, title, body, type, data, created_at)
                VALUES (1, 'admin', 'CRITICO: Saldo negativo detectado', ?, 'financial_critical', ?::jsonb, NOW())
            ")->execute([
                $notifBody,
                json_encode(['flag_type' => 'negative_balance', 'entity_type' => 'wallet', 'entity_id' => $walletId])
            ]);

            $stats['negative_balances']++;
            $stats['flags_created']++;
            $stats['critical_notifications']++;
            cron_log("CRITICAL negative_balance: wallet #{$walletId} cliente #{$customerId} saldo={$balance}");
        } catch (Exception $e) {
            $stats['errors']++;
            cron_log("ERRO negative balance wallet #{$wallet['wallet_id']}: " . $e->getMessage());
        }
    }

    // ============================================================
    // SUMMARY
    // ============================================================
    cron_log("=== RESUMO ===");
    cron_log("Carteiras verificadas: {$stats['wallets_checked']}");
    cron_log("Divergencias de saldo: {$stats['balance_mismatches']}");
    cron_log("Over-refunds: {$stats['over_refunds']}");
    cron_log("Transacoes orfas: {$stats['orphan_transactions']}");
    cron_log("Reembolsos duplicados: {$stats['double_refunds']}");
    cron_log("Saldos negativos: {$stats['negative_balances']}");
    cron_log("Total flags criadas: {$stats['flags_created']}");
    cron_log("Notificacoes criticas: {$stats['critical_notifications']}");
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
