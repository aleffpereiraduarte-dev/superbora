<?php
/**
 * cron/cofre-recurring.php  —  runs once a day at 09:00.
 *
 * Finds every active cofre with recurring_enabled=true whose
 * recurring_next_at is <= NOW(), then for each one:
 *   1. Checks the customer has enough balance in the cashback wallet
 *   2. Moves recurring_amount from wallet → cofre (same debit/credit as deposit.php)
 *   3. Writes a row to om_market_savings_tx with type='recurring'
 *   4. Advances recurring_next_at by one period
 *
 * If the wallet doesn't have enough, we DON'T fail the cofre forever —
 * we just skip this run (keep recurring_next_at where it is so we try
 * again tomorrow) and log it so support can reach out to the customer.
 */
require_once __DIR__ . '/../config/database.php';

$db = getDB();
$log = function($msg) {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
};

$stmt = $db->prepare("
    SELECT s.id, s.customer_id, s.name, s.current_amount, s.target_amount,
           s.recurring_amount, s.recurring_frequency, s.recurring_next_at,
           s.last_milestone
    FROM om_market_savings s
    WHERE s.recurring_enabled = true
      AND s.status = 'active'
      AND s.recurring_next_at IS NOT NULL
      AND s.recurring_next_at <= NOW()
    ORDER BY s.recurring_next_at ASC
");
$stmt->execute();
$rows = $stmt->fetchAll();

$log("Found " . count($rows) . " cofres due for recurring deposit");

$walletAvailable = function(PDO $db, int $customerId): float {
    $w = $db->prepare("
        SELECT COALESCE(SUM(CASE WHEN type IN ('earned','bonus') AND status = 'available' THEN amount ELSE 0 END), 0)
             - COALESCE(SUM(CASE WHEN type = 'used' THEN amount ELSE 0 END), 0) AS available
        FROM om_cashback WHERE customer_id = ?
    ");
    $w->execute([$customerId]);
    return round(max(0, (float)($w->fetchColumn() ?: 0)), 2);
};

$processed = 0;
$skipped = 0;

foreach ($rows as $r) {
    $cofreId = (int)$r['id'];
    $customerId = (int)$r['customer_id'];
    $amount = round((float)$r['recurring_amount'], 2);
    $freq = $r['recurring_frequency'];

    if ($amount <= 0) { $skipped++; continue; }

    $available = $walletAvailable($db, $customerId);
    if ($amount > $available) {
        $log("Cofre $cofreId (customer $customerId): insufficient wallet ($available < $amount) — will retry tomorrow");
        // Notify the customer via in-app feed so they know to top up
        try {
            $db->prepare("
                INSERT INTO om_market_notifications (customer_id, title, body, link, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ")->execute([
                $customerId,
                'Depósito automático não feito',
                "Seu cofre \"{$r['name']}\" precisa de R$ " . number_format($amount, 2, ',', '.') . " e sua carteira está sem saldo suficiente.",
                "/carteira/cofrinho/$cofreId",
            ]);
        } catch (Throwable $e) { /* notifications table may not exist on older envs */ }
        $skipped++;
        continue;
    }

    // Transactional deposit
    $db->beginTransaction();
    try {
        $lock = $db->prepare("SELECT id, name, target_amount, current_amount, last_milestone FROM om_market_savings WHERE id = ? AND customer_id = ? FOR UPDATE");
        $lock->execute([$cofreId, $customerId]);
        $cofre = $lock->fetch();
        if (!$cofre) { $db->rollBack(); $skipped++; continue; }

        $before = (float)$cofre['current_amount'];
        $target = $cofre['target_amount'] !== null ? (float)$cofre['target_amount'] : null;
        $newBalance = round($before + $amount, 2);

        // Milestones
        $lastMilestone = (int)$cofre['last_milestone'];
        $completed = false;
        if ($target && $target > 0) {
            $pctNow = ($newBalance / $target) * 100;
            foreach ([25, 50, 75, 100] as $t) {
                if ($pctNow >= $t && $lastMilestone < $t) $lastMilestone = $t;
            }
            if ($newBalance >= $target) $completed = true;
        }

        // Debit wallet
        $db->prepare("SELECT id FROM om_cashback WHERE customer_id = ? FOR UPDATE")->execute([$customerId]);
        $db->prepare("
            INSERT INTO om_cashback (customer_id, amount, type, status, description, created_at)
            VALUES (?, ?, 'used', 'available', ?, NOW())
        ")->execute([$customerId, $amount, "Depósito automático: " . $cofre['name']]);

        // Advance recurring_next_at
        $tz = new DateTimeZone('America/Sao_Paulo');
        $nextRun = new DateTimeImmutable($r['recurring_next_at'], $tz);
        $intervalSpec = ['weekly' => 'P7D', 'biweekly' => 'P14D', 'monthly' => 'P1M'][$freq] ?? 'P7D';
        $nextRun = $nextRun->add(new DateInterval($intervalSpec));
        // If the new date is still in the past (should only happen when the
        // cron was offline for >1 period), fast-forward to the next future slot.
        $now = new DateTimeImmutable('now', $tz);
        while ($nextRun < $now) $nextRun = $nextRun->add(new DateInterval($intervalSpec));

        // Update cofre
        $db->prepare("
            UPDATE om_market_savings
            SET current_amount = ?,
                last_milestone = ?,
                status = CASE WHEN ? THEN 'completed' ELSE status END,
                completed_at = CASE WHEN ? AND completed_at IS NULL THEN NOW() ELSE completed_at END,
                recurring_next_at = ?,
                recurring_enabled = CASE WHEN ? THEN false ELSE recurring_enabled END
            WHERE id = ?
        ")->execute([
            $newBalance,
            $lastMilestone,
            $completed ? 1 : 0,
            $completed ? 1 : 0,
            $nextRun->format('Y-m-d H:i:s'),
            $completed ? 1 : 0,
            $cofreId,
        ]);

        // Transaction log
        $db->prepare("
            INSERT INTO om_market_savings_tx (cofre_id, customer_id, type, amount, balance_after, description, created_at)
            VALUES (?, ?, 'recurring', ?, ?, ?, NOW())
        ")->execute([$cofreId, $customerId, $amount, $newBalance, 'Depósito automático']);

        $db->commit();

        // Notify customer in-app
        try {
            $db->prepare("
                INSERT INTO om_market_notifications (customer_id, title, body, link, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ")->execute([
                $customerId,
                $completed ? 'Meta do cofre batida!' : 'Depósito automático feito',
                "R$ " . number_format($amount, 2, ',', '.') . " adicionado ao cofre \"{$cofre['name']}\".",
                "/carteira/cofrinho/$cofreId",
            ]);
        } catch (Throwable $e) { /* ignore */ }

        $processed++;
        $log("Cofre $cofreId: +R$ $amount (balance now $newBalance" . ($completed ? ', META BATIDA' : '') . ')');
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        $log("Cofre $cofreId ERROR: " . $e->getMessage());
        $skipped++;
    }
}

$log("DONE — processed: $processed, skipped: $skipped");
