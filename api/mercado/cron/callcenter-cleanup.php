<?php
/**
 * CRON: Call center cleanup
 * Run hourly: 0 * * * * php /var/www/html/api/mercado/cron/callcenter-cleanup.php
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once dirname(__DIR__) . '/config/database.php';

$lockFile = '/tmp/superbora_cc_cleanup.lock';
$lockFp = fopen($lockFile, 'w');
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    echo "[" . date('H:i:s') . "] Another instance running. Exiting.\n";
    exit(0);
}

$db = getDB();
$log = function(string $msg) { echo "[" . date('H:i:s') . "] $msg\n"; };

$log("=== Call center cleanup started ===");

// 1. Clean old call center rate limit entries (> 2 hours)
$stmt = $db->exec("DELETE FROM om_callcenter_rate_limit WHERE window_start < NOW() - INTERVAL '2 hours'");
$log("Cleaned $stmt call center rate limit entries");

// 2. Clean old WhatsApp rate limit entries (> 2 hours)
$stmt = $db->exec("DELETE FROM om_whatsapp_rate_limit WHERE created_at < NOW() - INTERVAL '2 hours'");
$log("Cleaned $stmt WhatsApp rate limit entries");

// 3. Mark stale queue entries as abandoned (call ended but queue entry still pending)
$stmt = $db->exec("
    UPDATE om_callcenter_queue q
    SET abandoned_at = NOW()
    FROM om_callcenter_calls c
    WHERE q.call_id = c.id
      AND c.status IN ('completed', 'missed', 'voicemail')
      AND q.picked_at IS NULL
      AND q.abandoned_at IS NULL
");
$log("Marked $stmt stale queue entries as abandoned");

// 4. Clean old AI retry log entries (> 30 days)
$stmt = $db->exec("DELETE FROM om_ai_retry_log WHERE created_at < NOW() - INTERVAL '30 days'");
$log("Cleaned $stmt old AI retry log entries");

$log("=== Done ===");

flock($lockFp, LOCK_UN);
fclose($lockFp);
