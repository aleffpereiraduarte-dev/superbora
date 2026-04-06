<?php
/**
 * CRON: Process email queue
 * Sends pending emails from om_market_email_queue table.
 *
 * Run every minute:
 *   * * * * * php /var/www/html/api/mercado/cron/process-email-queue.php >> /var/log/superbora-email-queue.log 2>&1
 *
 * Features:
 * - Processes up to 50 emails per run (configurable)
 * - Respects scheduled_at for delayed sends
 * - Retries failed emails up to max_attempts
 * - High-priority emails processed first
 * - SKIP LOCKED prevents duplicate processing
 * - Cleans up old queue entries (sent >30d, failed >90d)
 */

// Prevent web access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/helpers/EmailService.php';

// ─── Concurrent execution guard ─────────────────────────────
$lockFile = '/tmp/superbora_cron_email_queue.lock';
$lockFp = fopen($lockFile, 'w');
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    echo "[" . date('H:i:s') . "] Another email queue instance is running. Exiting.\n";
    exit(0);
}

$db = getDB();
$log = function(string $msg) { echo "[" . date('H:i:s') . "] $msg\n"; };

$log("=== Email queue processing started ===");

try {
    $emailService = new EmailService($db);

    if (!$emailService->isEnabled()) {
        $log("SMTP not configured. Skipping queue processing.");
        exit(0);
    }

    // Process pending emails (max 50 per run)
    $stats = $emailService->processQueue(50);

    $log("Processed: {$stats['processed']}, Sent: {$stats['sent']}, Failed: {$stats['failed']}");

    // Every hour (minute 0), clean up old queue entries
    if (date('i') === '00') {
        $cleaned = $emailService->cleanupQueue(30, 90);
        if ($cleaned > 0) {
            $log("Cleaned {$cleaned} old queue entries");
        }
    }

    // Log summary stats to error_log for monitoring
    if ($stats['processed'] > 0) {
        error_log("[EmailQueue] Cron: processed={$stats['processed']} sent={$stats['sent']} failed={$stats['failed']}");
    }

    // Check for stuck emails (status = 'sending' for more than 5 minutes)
    $stuckStmt = $db->prepare("
        UPDATE om_market_email_queue
        SET status = 'pending', updated_at = NOW()
        WHERE status = 'sending'
          AND updated_at < NOW() - INTERVAL '5 minutes'
    ");
    $stuckStmt->execute();
    $stuckCount = $stuckStmt->rowCount();
    if ($stuckCount > 0) {
        $log("Reset {$stuckCount} stuck emails back to pending");
    }

    // Report queue depth for monitoring
    $depthStmt = $db->query("
        SELECT COUNT(*) as pending
        FROM om_market_email_queue
        WHERE status = 'pending' AND scheduled_at <= NOW()
    ");
    $depth = $depthStmt->fetch(PDO::FETCH_ASSOC);
    $pending = (int)($depth['pending'] ?? 0);
    if ($pending > 100) {
        error_log("[EmailQueue] WARNING: {$pending} emails pending in queue");
    }

} catch (Exception $e) {
    $log("ERROR: " . $e->getMessage());
    error_log("[EmailQueue] Cron error: " . $e->getMessage());
    exit(1);
}

$log("=== Email queue processing complete ===");

// Release lock
flock($lockFp, LOCK_UN);
fclose($lockFp);
