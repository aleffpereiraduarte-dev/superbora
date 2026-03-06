<?php
header('Content-Type: text/plain; charset=utf-8');
if (($_GET['key'] ?? '') !== 'abc123') { die("no"); }

echo "=== WhatsApp AI Debug ===\n\n";

// 1. Check PHP error log
echo "--- Recent PHP errors (whatsapp-ai) ---\n";
$logFile = ini_get('error_log') ?: '/var/log/php_errors.log';
$altLogs = [$logFile, '/var/log/php-fpm/error.log', '/var/log/php8.2-fpm.log', '/var/log/nginx/error.log', '/var/log/apache2/error.log', '/tmp/php_errors.log'];

foreach ($altLogs as $log) {
    if (file_exists($log) && filesize($log) > 0) {
        echo "\nLog: {$log} (" . filesize($log) . " bytes)\n";
        $lines = file($log, FILE_IGNORE_NEW_LINES);
        $recent = array_filter($lines, function($l) {
            return stripos($l, 'whatsapp-ai') !== false || stripos($l, 'wabot') !== false || stripos($l, 'WABOT') !== false;
        });
        $recent = array_slice($recent, -20);
        foreach ($recent as $l) echo $l . "\n";
        if (empty($recent)) {
            // Show last 20 lines with any error
            $lastErrors = array_filter($lines, function($l) {
                return stripos($l, 'error') !== false || stripos($l, 'fatal') !== false || stripos($l, 'warning') !== false;
            });
            $lastErrors = array_slice($lastErrors, -15);
            echo "(no whatsapp-ai entries, showing recent errors:)\n";
            foreach ($lastErrors as $l) echo $l . "\n";
        }
    }
}

// 2. Check syslog
echo "\n--- Syslog (recent PHP) ---\n";
if (file_exists('/var/log/syslog')) {
    $lines = file('/var/log/syslog', FILE_IGNORE_NEW_LINES);
    $recent = array_filter($lines, function($l) {
        return stripos($l, 'php') !== false && (stripos($l, 'whatsapp') !== false || stripos($l, 'error') !== false || stripos($l, 'fatal') !== false);
    });
    $recent = array_slice($recent, -10);
    foreach ($recent as $l) echo $l . "\n";
    if (empty($recent)) echo "(nothing found)\n";
} else {
    echo "(no syslog)\n";
}

// 3. Try to load the file and check for syntax issues
echo "\n--- Require test ---\n";
try {
    // Check if required files exist
    $deps = [
        'config/database.php',
        'helpers/claude-client.php',
        'helpers/zapi-whatsapp.php',
        'helpers/ai-memory.php',
        'helpers/ai-safeguards.php',
        'helpers/callcenter-sms.php',
        'helpers/ws-callcenter-broadcast.php',
    ];
    foreach ($deps as $d) {
        $path = __DIR__ . '/' . $d;
        echo "{$d}: " . (file_exists($path) ? "OK (" . filesize($path) . "b)" : "MISSING!") . "\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// 4. Check DB tables
echo "\n--- DB Tables ---\n";
try {
    require_once __DIR__ . '/config/database.php';
    $db = getDB();
    $tables = ['om_callcenter_whatsapp', 'om_callcenter_wa_messages', 'om_callcenter_calls', 'om_ai_call_memory'];
    foreach ($tables as $t) {
        try {
            $count = $db->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
            echo "{$t}: OK ({$count} rows)\n";
        } catch (Exception $e) {
            echo "{$t}: ERROR — " . $e->getMessage() . "\n";
        }
    }
} catch (Exception $e) {
    echo "DB connection error: " . $e->getMessage() . "\n";
}

// 5. Check error_log location
echo "\n--- PHP config ---\n";
echo "error_log: " . ini_get('error_log') . "\n";
echo "display_errors: " . ini_get('display_errors') . "\n";
echo "log_errors: " . ini_get('log_errors') . "\n";
echo "error_reporting: " . ini_get('error_reporting') . "\n";
