<?php
/**
 * Cron Watchdog — runs every 30min
 *
 * Checks heartbeat files of critical crons. If a cron hasn't run in
 * 2x its expected interval, fires an alert via $WATCHDOG_WEBHOOK.
 *
 * Each cron writes its timestamp to /var/log/cron-heartbeat-<name>.txt
 * on every successful run.
 */
require_once __DIR__ . '/../config/database.php';

$crons = [
    'proactive-support' => 600,   // 10 min interval, alert after 20 min stale
    'ai-log-cleanup' => 86400,    // daily
    'cleanup' => 300,             // 5 min interval
];

$alerts = [];
$now = time();

foreach ($crons as $name => $interval) {
    $hbFile = "/var/log/cron-heartbeat-{$name}.txt";
    if (!file_exists($hbFile)) {
        // First run — give it grace period (1 day)
        if (filemtime(__DIR__ . '/cron-watchdog.php') < $now - 86400) {
            $alerts[] = "[{$name}] heartbeat file missing";
        }
        continue;
    }
    $lastRun = (int)trim(file_get_contents($hbFile));
    $age = $now - $lastRun;
    if ($age > $interval * 2) {
        $alerts[] = "[{$name}] last run " . round($age / 60) . " min ago (expected every " . round($interval / 60) . " min)";
    }
}

if ($alerts) {
    $msg = "⚠️ Cron alerts:\n" . implode("\n", $alerts);
    error_log("[cron-watchdog] " . str_replace("\n", " | ", $msg));

    // Fire webhook if configured
    $webhook = $_ENV['WATCHDOG_WEBHOOK'] ?? getenv('WATCHDOG_WEBHOOK') ?? '';
    if ($webhook) {
        $ch = curl_init($webhook);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['text' => $msg, 'alerts' => $alerts]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 3,
        ]);
        @curl_exec($ch);
        curl_close($ch);
    }
    echo $msg . "\n";
} else {
    echo "[cron-watchdog " . date('Y-m-d H:i:s') . "] all crons healthy\n";
}
