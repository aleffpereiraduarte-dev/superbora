<?php
/**
 * CRON: Batch generate AI smart tags for products
 * Run every 30 minutes:
 *   crontab: STAR/30 * * * * php /var/www/html/api/mercado/cron/smart-tags-batch.php
 *   (onde STAR = asterisco)
 *
 * Processes 20 products per run to stay within API rate limits.
 * Products that failed are retried after 24 hours.
 */

// Prevent web access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/helpers/claude-client.php';
require_once dirname(__DIR__) . '/helpers/smart-tags-functions.php';

// ─── Concurrent execution guard ─────────────────────────────
$lockFile = '/tmp/superbora_cron_smart_tags.lock';
$lockFp = fopen($lockFile, 'w');
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    echo "[" . date('H:i:s') . "] Another smart-tags cron instance is running. Exiting.\n";
    exit(0);
}

echo "[" . date('Y-m-d H:i:s') . "] Starting smart tags batch generation...\n";

try {
    $db = getDB();
    $limit = (int)($argv[1] ?? 20); // Allow overriding via CLI arg
    $limit = min(50, max(1, $limit));

    $result = batchGenerateTags($db, $limit);

    echo "  Processed: {$result['processed']}\n";
    echo "  Failed: " . ($result['failed'] ?? 0) . "\n";
    echo "  Tokens used: {$result['total_tokens']}\n";
    echo "  Products remaining: " . ($result['products_remaining'] ?? '?') . "\n";
    echo "[" . date('H:i:s') . "] Done.\n";

} catch (Exception $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
    error_log("[SmartTags Cron] Fatal: " . $e->getMessage());
    exit(1);
}

flock($lockFp, LOCK_UN);
fclose($lockFp);
