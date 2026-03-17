<?php
/**
 * Health Check Endpoint
 * GET /api/mercado/health.php
 *
 * No auth required. No rate limiting. Fast execution.
 * Returns overall status + per-component health.
 */

date_default_timezone_set('America/Sao_Paulo');

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-cache, no-store, must-revalidate");
header("X-Content-Type-Options: nosniff");

// Load .env (same logic as database.php, but without rate limiting/sentry)
if (file_exists(__DIR__ . '/../../.env')) {
    $envFile = file(__DIR__ . '/../../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($envFile as $line) {
        if (strpos($line, '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $_ENV[trim($key)] = trim(trim($value), '"\'');
        }
    }
}

$result = [
    'status'     => 'ok',
    'timestamp'  => date('c'),
    'components' => [],
];

$degraded = false;
$down     = false;

// ── Database ────────────────────────────────────────────────────────
try {
    $dbHost = $_ENV['DB_HOSTNAME'] ?? getenv('DB_HOSTNAME') ?: 'localhost';
    $dbPort = $_ENV['DB_PORT']     ?? getenv('DB_PORT')     ?: '5432';
    $dbName = $_ENV['DB_NAME']     ?? getenv('DB_NAME')     ?: 'love1';
    $dbUser = $_ENV['DB_USERNAME'] ?? getenv('DB_USERNAME') ?: '';
    $dbPass = $_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: '';

    $dsn = "pgsql:host={$dbHost};port={$dbPort};dbname={$dbName}";
    $isLocalOrVPN = in_array($dbHost, ['localhost', '127.0.0.1', '::1'], true)
        || strpos($dbHost, '10.0.0.') === 0;
    if (!$isLocalOrVPN) {
        $dsn .= ';sslmode=require';
    }

    $t0 = hrtime(true);
    $db = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $db->query('SELECT 1');
    $latencyMs = round((hrtime(true) - $t0) / 1e6, 2);

    // Active connections count
    $connCount = null;
    try {
        $stmt = $db->query("SELECT count(*) AS cnt FROM pg_stat_activity WHERE datname = current_database()");
        $row = $stmt->fetch();
        $connCount = (int)($row['cnt'] ?? 0);
    } catch (Exception $e) {
        // non-critical, skip
    }

    $dbComponent = [
        'status'     => 'ok',
        'latency_ms' => $latencyMs,
    ];
    if ($connCount !== null) {
        $dbComponent['active_connections'] = $connCount;
    }
    $result['components']['database'] = $dbComponent;
} catch (Exception $e) {
    $result['components']['database'] = [
        'status' => 'down',
        'error'  => 'Connection failed',
    ];
    $down = true;
}

// ── Redis ───────────────────────────────────────────────────────────
try {
    if (!class_exists('Redis')) {
        $result['components']['redis'] = [
            'status'    => 'unavailable',
            'connected' => false,
            'error'     => 'PHP Redis extension not loaded',
        ];
        $degraded = true;
    } else {
        $redis = new Redis();
        $host = $_ENV['REDIS_HOST'] ?? '127.0.0.1';
        $port = (int)($_ENV['REDIS_PORT'] ?? 6379);

        $redis->connect($host, $port, 2.0);

        $redisPassword = $_ENV['REDIS_PASSWORD'] ?? '';
        if (!empty($redisPassword)) {
            $redis->auth($redisPassword);
        }

        $pong = $redis->ping();
        $connected = ($pong === '+PONG' || $pong === true);

        $redisComponent = [
            'status'    => $connected ? 'ok' : 'degraded',
            'connected' => $connected,
        ];

        // Memory usage
        try {
            $info = $redis->info('memory');
            if (isset($info['used_memory_human'])) {
                $redisComponent['memory_used'] = $info['used_memory_human'];
            }
            if (isset($info['used_memory_peak_human'])) {
                $redisComponent['memory_peak'] = $info['used_memory_peak_human'];
            }
        } catch (Exception $e) {
            // non-critical
        }

        $result['components']['redis'] = $redisComponent;

        if (!$connected) {
            $degraded = true;
        }
    }
} catch (Exception $e) {
    $result['components']['redis'] = [
        'status'    => 'down',
        'connected' => false,
        'error'     => 'Connection failed',
    ];
    $degraded = true;
}

// ── PHP ─────────────────────────────────────────────────────────────
$result['components']['php'] = [
    'version'    => PHP_VERSION,
    'sapi'       => PHP_SAPI,
    'extensions' => [
        'pdo_pgsql' => extension_loaded('pdo_pgsql'),
        'redis'     => extension_loaded('redis'),
        'mbstring'  => extension_loaded('mbstring'),
        'curl'      => extension_loaded('curl'),
    ],
];

// ── System ──────────────────────────────────────────────────────────
$uptime = null;
if (is_readable('/proc/uptime')) {
    $raw = file_get_contents('/proc/uptime');
    if ($raw !== false) {
        $seconds = (int)explode(' ', trim($raw))[0];
        $days    = intdiv($seconds, 86400);
        $hours   = intdiv($seconds % 86400, 3600);
        $mins    = intdiv($seconds % 3600, 60);
        $uptime  = "{$days}d {$hours}h {$mins}m";
    }
}
if ($uptime) {
    $result['components']['system'] = ['uptime' => $uptime];
}

// ── Overall status ──────────────────────────────────────────────────
if ($down) {
    $result['status'] = 'down';
    http_response_code(503);
} elseif ($degraded) {
    $result['status'] = 'degraded';
    http_response_code(200);
} else {
    $result['status'] = 'ok';
    http_response_code(200);
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
