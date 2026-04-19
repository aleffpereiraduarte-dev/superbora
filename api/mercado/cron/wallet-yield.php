<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * CRON: Rendimento diario da carteira SuperBora (CDI)
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Executa diariamente as 23:00 para creditar rendimento na carteira
 * Crontab: 0 23 * * * /usr/bin/php /var/www/html/api/mercado/cron/wallet-yield.php
 *
 * Como funciona:
 *  - CDI anual default: 10.65% (100% CDI)
 *  - Taxa diaria = (1 + anual)^(1/365) - 1  ≈ 0.0292% para 10.65%
 *  - Para cada cliente com saldo > 0 na om_cashback_wallet:
 *      yield = balance * daily_rate
 *      balance += yield  (total_earned += yield)
 *      registra em om_wallet_yield_history
 *      registra transacao em om_cashback_transactions (type='yield')
 *
 * Idempotente: constraint UNIQUE (customer_id, yield_date) impede duplicar
 * se rodar duas vezes no mesmo dia.
 */

// Secret / CLI guard (same pattern as expire-cashback.php)
$secret = $_ENV['CRON_SECRET'] ?? getenv('CRON_SECRET') ?: '';
if (empty($secret)) {
    http_response_code(503);
    echo json_encode(['error' => 'Cron secret not configured']);
    exit;
}
if (php_sapi_name() !== 'cli' && (!isset($_SERVER['HTTP_X_CRON_KEY']) || !hash_equals($secret, $_SERVER['HTTP_X_CRON_KEY']))) {
    http_response_code(403);
    die('Acesso negado');
}

require_once dirname(__DIR__) . "/config/database.php";

// Global lock to prevent concurrent executions
$lockFile = sys_get_temp_dir() . '/superbora_wallet_yield.lock';
$lockFp = fopen($lockFile, 'w');
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    echo "[" . date('Y-m-d H:i:s') . "] SKIP: Another instance is running\n";
    exit(0);
}

echo "[" . date('Y-m-d H:i:s') . "] Iniciando rendimento da carteira (CDI)...\n";

// CDI config (pode ser puxado de um config table no futuro)
$cdiAnnualPct = (float)($_ENV['WALLET_CDI_ANNUAL'] ?? getenv('WALLET_CDI_ANNUAL') ?: 10.65);
$cdiPct = (float)($_ENV['WALLET_CDI_PCT_OF_CDI'] ?? getenv('WALLET_CDI_PCT_OF_CDI') ?: 100);
$effectiveAnnual = $cdiAnnualPct * ($cdiPct / 100.0);
$dailyRate = pow(1 + ($effectiveAnnual / 100.0), 1 / 365) - 1; // e.g. 0.0002775

echo "  CDI anual: {$cdiAnnualPct}% | % do CDI: {$cdiPct}% | taxa diaria: "
    . number_format($dailyRate * 100, 6) . "%\n";

try {
    $db = getDB();

    // Ensure table exists (self-heal if migration didn't run)
    $db->exec("
        CREATE TABLE IF NOT EXISTS om_wallet_yield_history (
            id SERIAL PRIMARY KEY,
            customer_id INTEGER NOT NULL,
            yield_date DATE NOT NULL,
            balance_before DECIMAL(12,2) NOT NULL,
            yield_amount DECIMAL(12,4) NOT NULL,
            cdi_rate DECIMAL(8,6) NOT NULL,
            cdi_annual DECIMAL(6,4) NOT NULL,
            balance_after DECIMAL(12,2) NOT NULL,
            created_at TIMESTAMP DEFAULT NOW(),
            UNIQUE(customer_id, yield_date)
        )
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_yield_history_customer ON om_wallet_yield_history(customer_id, yield_date DESC)");

    $today = date('Y-m-d');

    // Stop if already ran today (extra safety)
    $ranStmt = $db->prepare("SELECT COUNT(*) FROM om_wallet_yield_history WHERE yield_date = ?");
    $ranStmt->execute([$today]);
    $alreadyRan = (int)$ranStmt->fetchColumn();
    if ($alreadyRan > 0) {
        echo "  Ja rodou hoje ($alreadyRan registros). Nada a fazer.\n";
        goto done;
    }

    // Get customers with balance > 0 — UNION both wallet tables (legacy + new)
    $stmt = $db->query("
        SELECT customer_id, MAX(balance) AS balance, MIN(source) AS source FROM (
            SELECT customer_id, COALESCE(balance, 0) AS balance, 'cashback' AS source
            FROM om_cashback_wallet
            WHERE COALESCE(balance, 0) > 0
            UNION ALL
            SELECT customer_id, COALESCE(balance, 0) AS balance, 'main' AS source
            FROM om_wallet
            WHERE COALESCE(balance, 0) > 0
        ) u
        GROUP BY customer_id
        ORDER BY customer_id
    ");
    $wallets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "  " . count($wallets) . " carteiras com saldo > 0\n";

    $totalYield = 0;
    $processed = 0;
    $skipped = 0;

    foreach ($wallets as $w) {
        $customerId = (int)$w['customer_id'];
        $balance = (float)$w['balance'];
        $yield = round($balance * $dailyRate, 4);

        if ($yield < 0.01) {
            // Arredondamento: saldos muito pequenos geram yield < 1 centavo — pula
            $skipped++;
            continue;
        }

        $source = $w['source'] ?? 'cashback';
        $walletTable = ($source === 'main') ? 'om_wallet' : 'om_cashback_wallet';

        try {
            $db->beginTransaction();

            // Lock wallet row on the correct table
            $lk = $db->prepare("SELECT balance FROM {$walletTable} WHERE customer_id = ? FOR UPDATE");
            $lk->execute([$customerId]);
            $lockedBal = $lk->fetchColumn();
            if ($lockedBal === false) { $db->rollBack(); continue; }
            $lockedBal = (float)$lockedBal;

            if ($lockedBal <= 0) { $db->rollBack(); continue; }

            // Recompute yield against locked balance
            $yield = round($lockedBal * $dailyRate, 4);
            if ($yield < 0.01) { $db->rollBack(); $skipped++; continue; }

            $newBalance = round($lockedBal + $yield, 2);

            // Insert yield history (idempotent via UNIQUE)
            $ins = $db->prepare("
                INSERT INTO om_wallet_yield_history
                    (customer_id, yield_date, balance_before, yield_amount, cdi_rate, cdi_annual, balance_after)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT (customer_id, yield_date) DO NOTHING
                RETURNING id
            ");
            $ins->execute([
                $customerId,
                $today,
                $lockedBal,
                $yield,
                $dailyRate,
                $effectiveAnnual,
                $newBalance,
            ]);
            $inserted = $ins->fetchColumn();
            if (!$inserted) {
                // already processed today (race condition)
                $db->rollBack();
                continue;
            }

            // Credit wallet on the correct table (om_wallet uses total_cashback_earned)
            if ($source === 'main') {
                $db->prepare("
                    UPDATE om_wallet
                    SET balance = balance + ?,
                        total_cashback_earned = COALESCE(total_cashback_earned, 0) + ?,
                        updated_at = NOW()
                    WHERE customer_id = ?
                ")->execute([$yield, $yield, $customerId]);
            } else {
                $db->prepare("
                    UPDATE om_cashback_wallet
                    SET balance = balance + ?,
                        total_earned = COALESCE(total_earned, 0) + ?
                    WHERE customer_id = ?
                ")->execute([$yield, $yield, $customerId]);
            }

            // Register transaction (visible in extrato)
            try {
                $db->prepare("
                    INSERT INTO om_cashback_transactions
                        (customer_id, type, amount, balance_after, description, expired)
                    VALUES (?, 'yield', ?, ?, ?, 0)
                ")->execute([
                    $customerId,
                    $yield,
                    $newBalance,
                    'Rendimento diario (CDI ' . number_format($cdiPct, 0) . '%)',
                ]);
            } catch (Exception $eTx) {
                // Schema difference? ignore — history already persisted
                error_log("[wallet-yield] could not insert cashback_transactions: " . $eTx->getMessage());
            }

            $db->commit();

            $totalYield += $yield;
            $processed++;

            if ($processed % 100 === 0) {
                echo "  ...processados: $processed / " . count($wallets) . "\n";
            }
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log("[wallet-yield] customer #$customerId erro: " . $e->getMessage());
            echo "  [ERRO] cliente #$customerId: " . $e->getMessage() . "\n";
        }
    }

    echo "\n[" . date('Y-m-d H:i:s') . "] Resumo:\n";
    echo "  - Carteiras processadas: $processed\n";
    echo "  - Saldos muito pequenos (skip): $skipped\n";
    echo "  - Rendimento total distribuido: R$ " . number_format($totalYield, 2, ',', '.') . "\n";

    done:
    echo "\n[" . date('Y-m-d H:i:s') . "] Cron finalizado.\n";

} catch (Exception $e) {
    echo "[ERRO FATAL] " . $e->getMessage() . "\n";
    error_log("[wallet-yield] FATAL: " . $e->getMessage());
    exit(1);
} finally {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    @unlink($lockFile);
}
