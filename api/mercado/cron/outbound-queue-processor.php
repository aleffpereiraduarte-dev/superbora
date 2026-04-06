<?php
/**
 * CRON: Outbound call queue processor
 * Run every 5 minutes:
 *   crontab: STAR/5 * * * * php /var/www/html/api/mercado/cron/outbound-queue-processor.php
 *   (where STAR = asterisk)
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/helpers/outbound-calls.php';

$lockFile = '/tmp/superbora_outbound_queue.lock';
$lockFp = fopen($lockFile, 'w');
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    echo "[" . date('H:i:s') . "] Another instance running. Exiting.\n";
    exit(0);
}

$db = getDB();
$log = function(string $msg) { echo "[" . date('H:i:s') . "] $msg\n"; };

$log("=== Outbound queue processor started ===");

$result = processScheduledCalls($db, 20);

$log("Processed: {$result['processed']}, Succeeded: {$result['succeeded']}, Failed: {$result['failed']}");
$log("=== Done ===");

flock($lockFp, LOCK_UN);
fclose($lockFp);
