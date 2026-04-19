<?php
/**
 * ============================================================================
 * CRON: investment-yield.php  — daily yield + snapshots for om_investments
 * ============================================================================
 * Schedule: 30 23 * * *  (runs after wallet-yield.php)
 *
 * - Walks every open om_investments position and accrues daily yield using
 *   compound formula: daily_rate = (1 + rate_year)^(1/365) - 1.
 * - Writes a 'yield' movement row per position/day.
 * - Writes a per-customer snapshot into om_investment_snapshots
 *   so the performance chart has real historical points.
 * ============================================================================
 */
ini_set('memory_limit', '256M');

$secret = $_ENV['CRON_SECRET'] ?? getenv('CRON_SECRET') ?: '';
if (empty($secret)) { http_response_code(503); die('no cron secret'); }
if (php_sapi_name() !== 'cli' && (!isset($_SERVER['HTTP_X_CRON_KEY']) || !hash_equals($secret, $_SERVER['HTTP_X_CRON_KEY']))) {
    http_response_code(403); die('denied');
}

require_once dirname(__DIR__) . '/config/database.php';

$lockFile = sys_get_temp_dir() . '/superbora_invest_yield.lock';
$fp = fopen($lockFile, 'w');
if (!flock($fp, LOCK_EX | LOCK_NB)) exit(0);

function ill(string $m): void { fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] [invest-yield] ' . $m . "\n"); }

$db = getDB();

// Ensure tables (idempotent).
$db->exec("
    CREATE TABLE IF NOT EXISTS om_investments (
        id SERIAL PRIMARY KEY,
        customer_id INTEGER NOT NULL,
        product_code VARCHAR(30) NOT NULL,
        principal NUMERIC(12,2) NOT NULL DEFAULT 0,
        accrued NUMERIC(12,2) NOT NULL DEFAULT 0,
        rate_year NUMERIC(6,4) NOT NULL,
        created_at TIMESTAMP DEFAULT NOW(),
        updated_at TIMESTAMP DEFAULT NOW(),
        closed_at TIMESTAMP
    )
");
$db->exec("
    CREATE TABLE IF NOT EXISTS om_investment_moves (
        id SERIAL PRIMARY KEY,
        customer_id INTEGER NOT NULL,
        position_id INTEGER NOT NULL,
        kind VARCHAR(20) NOT NULL,
        amount NUMERIC(12,2) NOT NULL,
        balance_after NUMERIC(12,2) NOT NULL,
        created_at TIMESTAMP DEFAULT NOW()
    )
");
$db->exec("
    CREATE TABLE IF NOT EXISTS om_investment_snapshots (
        id SERIAL PRIMARY KEY,
        customer_id INTEGER NOT NULL,
        snap_date DATE NOT NULL,
        principal NUMERIC(12,2) NOT NULL DEFAULT 0,
        accrued NUMERIC(12,2) NOT NULL DEFAULT 0,
        total NUMERIC(12,2) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT NOW(),
        UNIQUE (customer_id, snap_date)
    )
");

ill('running daily yield accrual');

$updatedPositions = 0;
$totalYieldCredited = 0.0;

try {
    $stmt = $db->query("
        SELECT id, customer_id, principal, accrued, rate_year, updated_at
        FROM om_investments
        WHERE closed_at IS NULL AND principal > 0
    ");
    $positions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    ill('open positions: ' . count($positions));

    foreach ($positions as $p) {
        $principal = (float)$p['principal'];
        if ($principal <= 0) continue;

        $total = $principal + (float)$p['accrued'];
        $last  = strtotime($p['updated_at']);
        $days  = max(0, floor((time() - $last) / 86400));
        if ($days === 0) continue;

        $daily    = pow(1 + (float)$p['rate_year'], 1 / 365) - 1;
        $newTotal = $total * pow(1 + $daily, $days);
        $newAccrued = round($newTotal - $principal, 2);
        $yieldDelta = round($newAccrued - (float)$p['accrued'], 2);

        if ($yieldDelta <= 0) continue;

        try {
            $db->beginTransaction();
            $db->prepare("UPDATE om_investments SET accrued = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$newAccrued, (int)$p['id']]);
            $db->prepare("
                INSERT INTO om_investment_moves (customer_id, position_id, kind, amount, balance_after)
                VALUES (?, ?, 'yield', ?, ?)
            ")->execute([
                (int)$p['customer_id'],
                (int)$p['id'],
                $yieldDelta,
                round($principal + $newAccrued, 2),
            ]);
            $db->commit();
            $updatedPositions++;
            $totalYieldCredited += $yieldDelta;
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            ill("yield failed for position {$p['id']}: " . $e->getMessage());
        }
    }
} catch (Exception $e) {
    ill('yield pass failed: ' . $e->getMessage());
}

// Snapshot per customer after yield was credited.
try {
    $rows = $db->query("
        SELECT customer_id,
               COALESCE(SUM(principal), 0)           AS principal,
               COALESCE(SUM(accrued),   0)           AS accrued,
               COALESCE(SUM(principal + accrued), 0) AS total
        FROM om_investments
        WHERE closed_at IS NULL
        GROUP BY customer_id
    ")->fetchAll(PDO::FETCH_ASSOC);

    $ins = $db->prepare("
        INSERT INTO om_investment_snapshots (customer_id, snap_date, principal, accrued, total)
        VALUES (?, CURRENT_DATE, ?, ?, ?)
        ON CONFLICT (customer_id, snap_date) DO UPDATE
          SET principal = EXCLUDED.principal,
              accrued   = EXCLUDED.accrued,
              total     = EXCLUDED.total
    ");
    foreach ($rows as $r) {
        $ins->execute([
            (int)$r['customer_id'],
            round((float)$r['principal'], 2),
            round((float)$r['accrued'], 2),
            round((float)$r['total'], 2),
        ]);
    }
    ill('snapshots written: ' . count($rows));
} catch (Exception $e) {
    ill('snapshot failed: ' . $e->getMessage());
}

ill(sprintf('done: positions=%d yield_credited=R$ %s',
    $updatedPositions, number_format($totalYieldCredited, 2, ',', '.')));

flock($fp, LOCK_UN);
fclose($fp);
@unlink($lockFile);
exit(0);
