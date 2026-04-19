<?php
/**
 * Process Scheduled PIX Transfers
 * Runs every 5 minutes — picks up pending PIX transfers whose scheduled_at has passed
 * and processes them by debiting cashback balance.
 *
 * crontab: every 5 min
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once dirname(__DIR__) . '/config/database.php';

// Concurrent execution guard
$lockFile = '/tmp/superbora_cron_scheduled_pix.lock';
$lockFp = fopen($lockFile, 'w');
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    exit(0);
}

$db = getDB();
$log = function (string $msg) { echo "[" . date('H:i:s') . "] $msg\n"; };
$log("=== Process Scheduled PIX started ===");

$processed = 0;
$failed = 0;
$maxPerRun = 100;

// Find pending transfers that are due
$stmt = $db->prepare("
    SELECT s.id, s.customer_id, s.recipient_key, s.recipient_key_type,
           s.recipient_name, s.amount, s.scheduled_at,
           c.name AS customer_name, c.phone
    FROM om_market_pix_scheduled s
    JOIN om_market_customers c ON c.customer_id = s.customer_id
    WHERE s.status = 'pending'
      AND s.scheduled_at <= NOW()
    ORDER BY s.scheduled_at ASC
    LIMIT ?
");
$stmt->execute([$maxPerRun]);
$pendingTransfers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$log("Found " . count($pendingTransfers) . " pending transfers to process");

foreach ($pendingTransfers as $transfer) {
    $db->beginTransaction();
    try {
        $customerId = (int)$transfer['customer_id'];
        $amount = (float)$transfer['amount'];
        $transferId = (int)$transfer['id'];

        // Check cashback balance
        $balStmt = $db->prepare("SELECT balance FROM om_market_cashback WHERE customer_id = ? FOR UPDATE");
        $balStmt->execute([$customerId]);
        $balance = (float)($balStmt->fetchColumn() ?: 0);

        if ($balance < $amount) {
            // Insufficient balance — mark as failed
            $db->prepare("UPDATE om_market_pix_scheduled SET status = 'failed', error_message = 'Saldo insuficiente', processed_at = NOW() WHERE id = ?")
               ->execute([$transferId]);
            $db->commit();
            $log("  #{$transferId}: FAILED - Insufficient balance (has R\${$balance}, needs R\${$amount})");
            $failed++;

            // Notify customer
            try {
                $db->prepare("INSERT INTO om_market_notifications (customer_id, title, body, type, created_at) VALUES (?, ?, ?, 'pix', NOW())")
                   ->execute([
                       $customerId,
                       'PIX agendado falhou',
                       "Sua transferência PIX de R\$ " . number_format($amount, 2, ',', '.') . " para {$transfer['recipient_name']} falhou por saldo insuficiente.",
                   ]);
            } catch (\Throwable $e) {
                $log("  Notification error: " . $e->getMessage());
            }
            continue;
        }

        // Debit cashback
        $db->prepare("UPDATE om_market_cashback SET balance = balance - ?, updated_at = NOW() WHERE customer_id = ?")
           ->execute([$amount, $customerId]);

        // Record transaction
        $db->prepare("INSERT INTO om_market_cashback_transactions (customer_id, amount, type, description, created_at) VALUES (?, ?, 'debit', ?, NOW())")
           ->execute([$customerId, $amount, "PIX agendado para {$transfer['recipient_name']} ({$transfer['recipient_key']})"]);

        // Mark as processed
        $db->prepare("UPDATE om_market_pix_scheduled SET status = 'processed', processed_at = NOW() WHERE id = ?")
           ->execute([$transferId]);

        $db->commit();
        $processed++;
        $log("  #{$transferId}: OK - R\${$amount} to {$transfer['recipient_name']}");

        // Notify customer of success
        try {
            $db->prepare("INSERT INTO om_market_notifications (customer_id, title, body, type, created_at) VALUES (?, ?, ?, 'pix', NOW())")
               ->execute([
                   $customerId,
                   'PIX agendado enviado',
                   "Transferência de R\$ " . number_format($amount, 2, ',', '.') . " para {$transfer['recipient_name']} realizada com sucesso!",
               ]);
        } catch (\Throwable $e) {
            // Non-critical
        }

    } catch (\Throwable $e) {
        $db->rollBack();
        $failed++;
        $log("  #{$transfer['id']}: ERROR - " . $e->getMessage());

        // Mark as failed
        try {
            $db->prepare("UPDATE om_market_pix_scheduled SET status = 'failed', error_message = ?, processed_at = NOW() WHERE id = ?")
               ->execute([substr($e->getMessage(), 0, 500), $transfer['id']]);
        } catch (\Throwable $e2) {
            $log("  Could not update status: " . $e2->getMessage());
        }
    }
}

$log("=== Done: {$processed} processed, {$failed} failed ===");
flock($lockFp, LOCK_UN);
fclose($lockFp);
