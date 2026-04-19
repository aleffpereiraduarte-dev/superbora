<?php
/**
 * AI Log Cleanup Cron — runs daily at 4am
 *
 * Retention policy:
 *   - om_ai_action_log: keep 90 days (audit + analytics window)
 *   - om_ai_support_messages: keep 180 days (conversation history)
 *   - om_ai_support_tickets closed > 365d: delete
 *
 * Usage (crontab):
 *   0 4 * * * php /var/www/html/api/mercado/cron/ai-log-cleanup.php
 */
require_once __DIR__ . '/../config/database.php';

$db = getDB();
$now = date('Y-m-d H:i:s');
$stats = ['action_log' => 0, 'messages' => 0, 'tickets' => 0];

try {
    $stmt = $db->exec("DELETE FROM om_ai_action_log WHERE created_at < NOW() - INTERVAL '90 days'");
    $stats['action_log'] = $stmt;
} catch (\Throwable $e) {
    error_log('[ai-log-cleanup] action_log: ' . $e->getMessage());
}

try {
    $stmt = $db->exec("
        DELETE FROM om_ai_support_messages
        WHERE created_at < NOW() - INTERVAL '180 days'
    ");
    $stats['messages'] = $stmt;
} catch (\Throwable $e) {
    error_log('[ai-log-cleanup] messages: ' . $e->getMessage());
}

try {
    $stmt = $db->exec("
        DELETE FROM om_ai_support_tickets
        WHERE status IN ('closed', 'resolved')
          AND updated_at < NOW() - INTERVAL '365 days'
    ");
    $stats['tickets'] = $stmt;
} catch (\Throwable $e) {
    error_log('[ai-log-cleanup] tickets: ' . $e->getMessage());
}

echo "[ai-log-cleanup {$now}] deleted: action_log={$stats['action_log']}, messages={$stats['messages']}, tickets={$stats['tickets']}\n";

// Heartbeat for cron-watchdog
@file_put_contents('/var/log/cron-heartbeat-ai-log-cleanup.txt', time());
