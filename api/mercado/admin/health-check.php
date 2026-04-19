<?php
require_once __DIR__ . '/../config/database.php';
setCorsHeaders();
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// System health check endpoint for admin dashboard
$checks = [];

// Database check
try {
    $db = getDB();
    $stmt = $db->query('SELECT 1');
    $checks['database'] = ['status' => 'ok', 'latency_ms' => 0];
} catch (Exception $e) {
    $checks['database'] = ['status' => 'error', 'message' => $e->getMessage()];
}

// Redis check
try {
    $redis = new Redis();
    $redis->connect('127.0.0.1', 6379);
    $redisPass = $_ENV['REDIS_PASSWORD'] ?? '';
    if ($redisPass) $redis->auth($redisPass);
    $redis->ping();
    $checks['redis'] = ['status' => 'ok'];
    $redis->close();
} catch (Exception $e) {
    $checks['redis'] = ['status' => 'error', 'message' => $e->getMessage()];
}

// Disk space
$freeBytes = disk_free_space('/');
$totalBytes = disk_total_space('/');
$checks['disk'] = [
    'status' => ($freeBytes / $totalBytes > 0.1) ? 'ok' : 'warning',
    'free_gb' => round($freeBytes / 1073741824, 2),
    'total_gb' => round($totalBytes / 1073741824, 2),
    'used_pct' => round((1 - $freeBytes / $totalBytes) * 100, 1),
];

// PHP-FPM
$checks['php'] = [
    'status' => 'ok',
    'version' => phpversion(),
    'memory_limit' => ini_get('memory_limit'),
];

$allOk = true;
foreach ($checks as $c) {
    if (($c['status'] ?? '') !== 'ok') $allOk = false;
}

response(true, [
    'overall' => $allOk ? 'healthy' : 'degraded',
    'checks' => $checks,
    'timestamp' => date('c'),
]);
