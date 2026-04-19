<?php
/**
 * ============================================================================
 * CRON: investment-auto.php  — processes recurring auto-investments
 * ============================================================================
 * Schedule: 0 8 * * *
 *
 * - For every row in om_auto_investments where active=true and next_run_at <= now()
 *   moves funds from the customer wallet into the target product (same pattern
 *   as customer/investments.php POST action=invest).
 * - If the wallet doesn't have enough balance, records a 'skipped_no_funds'
 *   status and advances next_run_at so it doesn't spam.
 * - Never throws: each row is an independent transaction so one bad row
 *   does not block the rest.
 * ============================================================================
 */
ini_set('memory_limit', '256M');

$secret = $_ENV['CRON_SECRET'] ?? getenv('CRON_SECRET') ?: '';
if (empty($secret)) { http_response_code(503); die('no cron secret'); }
if (php_sapi_name() !== 'cli' && (!isset($_SERVER['HTTP_X_CRON_KEY']) || !hash_equals($secret, $_SERVER['HTTP_X_CRON_KEY']))) {
    http_response_code(403); die('denied');
}

require_once dirname(__DIR__) . '/config/database.php';

$lockFile = sys_get_temp_dir() . '/superbora_invest_auto.lock';
$fp = fopen($lockFile, 'w');
if (!flock($fp, LOCK_EX | LOCK_NB)) exit(0);

function iall(string $m): void { fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] [invest-auto] ' . $m . "\n"); }

// Same catalog as customer/investments.php
$PRODUCTS = [
    'CDB_100'  => ['name' => 'CDB 100% CDI',   'rate_year' => 0.1065],
    'CDB_110'  => ['name' => 'CDB 110% CDI',   'rate_year' => 0.1170],
    'POUPANCA' => ['name' => 'Poupança',       'rate_year' => 0.0600],
    'TESOURO'  => ['name' => 'Tesouro Direto', 'rate_year' => 0.1200],
];

$db = getDB();

function readWalletBalanceCron(PDO $db, int $customerId): float {
    $stmt = $db->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN type IN ('earned','bonus') AND status = 'available' THEN amount ELSE 0 END), 0)
              - COALESCE(SUM(CASE WHEN type = 'used' THEN amount ELSE 0 END), 0) AS available
        FROM om_cashback
        WHERE customer_id = ?
    ");
    $stmt->execute([$customerId]);
    return round(max(0, (float)($stmt->fetchColumn() ?: 0)), 2);
}

function nextRun(string $ts, string $freq): string {
    $t = strtotime($ts) ?: time();
    switch ($freq) {
        case 'weekly':   return date('Y-m-d H:i:s', strtotime('+7 days', $t));
        case 'biweekly': return date('Y-m-d H:i:s', strtotime('+14 days', $t));
        case 'monthly':
        default:         return date('Y-m-d H:i:s', strtotime('+1 month', $t));
    }
}

$processed = 0; $skipped = 0; $failed = 0;

try {
    $rows = $db->query("
        SELECT id, customer_id, product_code, amount, frequency, next_run_at
        FROM om_auto_investments
        WHERE active = true AND next_run_at <= NOW()
        ORDER BY next_run_at ASC
        LIMIT 500
    ")->fetchAll(PDO::FETCH_ASSOC);

    iall('auto-invest rows due: ' . count($rows));

    foreach ($rows as $row) {
        $customerId = (int)$row['customer_id'];
        $product    = $row['product_code'];
        $amount     = round((float)$row['amount'], 2);
        $frequency  = $row['frequency'];

        if (!isset($PRODUCTS[$product])) {
            iall("skipping row {$row['id']}: unknown product {$product}");
            $db->prepare("UPDATE om_auto_investments SET active=false, last_run_at=NOW(), last_run_status='invalid_product' WHERE id=?")
                ->execute([(int)$row['id']]);
            $failed++;
            continue;
        }

        try {
            $db->beginTransaction();

            $balance = readWalletBalanceCron($db, $customerId);
            if ($balance < $amount) {
                // advance next run so it retries next cycle
                $newNext = nextRun($row['next_run_at'], $frequency);
                $db->prepare("UPDATE om_auto_investments SET next_run_at=?, last_run_at=NOW(), last_run_status='skipped_no_funds', updated_at=NOW() WHERE id=?")
                    ->execute([$newNext, (int)$row['id']]);
                $db->commit();
                $skipped++;
                continue;
            }

            // Debit wallet
            $db->prepare("
                INSERT INTO om_cashback (customer_id, type, amount, description, status, created_at)
                VALUES (?, 'used', ?, ?, 'available', NOW())
            ")->execute([$customerId, $amount, 'Auto-investimento ' . $PRODUCTS[$product]['name']]);

            // Find or create position
            $pos = $db->prepare("
                SELECT id, principal, accrued FROM om_investments
                WHERE customer_id = ? AND product_code = ? AND closed_at IS NULL
                FOR UPDATE
            ");
            $pos->execute([$customerId, $product]);
            $p = $pos->fetch(PDO::FETCH_ASSOC);

            if ($p) {
                $newPrincipal = (float)$p['principal'] + $amount;
                $db->prepare("UPDATE om_investments SET principal=?, updated_at=NOW() WHERE id=?")
                    ->execute([$newPrincipal, (int)$p['id']]);
                $positionId   = (int)$p['id'];
                $balanceAfter = round($newPrincipal + (float)$p['accrued'], 2);
            } else {
                $ins = $db->prepare("
                    INSERT INTO om_investments (customer_id, product_code, principal, accrued, rate_year)
                    VALUES (?, ?, ?, 0, ?) RETURNING id
                ");
                $ins->execute([$customerId, $product, $amount, $PRODUCTS[$product]['rate_year']]);
                $positionId   = (int)$ins->fetchColumn();
                $balanceAfter = $amount;
            }

            $db->prepare("
                INSERT INTO om_investment_moves (customer_id, position_id, kind, amount, balance_after)
                VALUES (?, ?, 'invest', ?, ?)
            ")->execute([$customerId, $positionId, $amount, $balanceAfter]);

            $newNext = nextRun($row['next_run_at'], $frequency);
            $db->prepare("UPDATE om_auto_investments SET next_run_at=?, last_run_at=NOW(), last_run_status='ok', updated_at=NOW() WHERE id=?")
                ->execute([$newNext, (int)$row['id']]);

            $db->commit();
            $processed++;
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            iall("failed row {$row['id']}: " . $e->getMessage());
            try {
                $db->prepare("UPDATE om_auto_investments SET last_run_at=NOW(), last_run_status='error' WHERE id=?")
                    ->execute([(int)$row['id']]);
            } catch (Exception $ee) { /* ignore */ }
            $failed++;
        }
    }
} catch (Exception $e) {
    iall('auto-invest cron failed: ' . $e->getMessage());
}

iall("done: processed={$processed} skipped={$skipped} failed={$failed}");

flock($fp, LOCK_UN);
fclose($fp);
@unlink($lockFile);
exit(0);
