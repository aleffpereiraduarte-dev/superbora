<?php
/**
 * GET /api/mercado/metrics.php
 *
 * Prometheus-format endpoint exposing request counts + latency histograms.
 * Scraped by Prometheus on us-server every 15s.
 *
 * Auth: restrict to internal networks (10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16,
 * 127.0.0.1, and Cloudflare IPs if needed via X-Forwarded-For checking).
 * For simplicity, accept internal IPs or a token.
 */

require_once __DIR__ . '/config/database.php';

$remote = $_SERVER['REMOTE_ADDR'] ?? '';
$isInternal = (
    str_starts_with($remote, '10.') ||
    str_starts_with($remote, '172.16.') ||
    str_starts_with($remote, '172.17.') ||
    str_starts_with($remote, '172.18.') ||
    str_starts_with($remote, '172.19.') ||
    str_starts_with($remote, '172.20.') ||
    str_starts_with($remote, '192.168.') ||
    $remote === '127.0.0.1' ||
    $remote === '::1'
);

// Allow token-based access too (Prometheus can set bearer)
$token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$expected = getenv('METRICS_TOKEN') ?: ($_ENV['METRICS_TOKEN'] ?? '');
$tokenOk = $expected && hash_equals('Bearer ' . $expected, $token);

if (!$isInternal && !$tokenOk) {
    http_response_code(403);
    exit("forbidden\n");
}

header('Content-Type: text/plain; charset=utf-8');

try {
    $r = new Redis();
    $ok = @$r->connect($_ENV['REDIS_HOST'] ?? '127.0.0.1', (int)($_ENV['REDIS_PORT'] ?? 6379), 0.5);
    if (!$ok) { echo "# redis unavailable\n"; exit; }
    $pwd = $_ENV['REDIS_PASSWORD'] ?? getenv('REDIS_PASSWORD') ?: '';
    if ($pwd) $r->auth($pwd);
    $r->setOption(Redis::OPT_SCAN, Redis::SCAN_RETRY);

    // Helper: collect all keys matching pattern via scan iterator
    $scanAll = function(string $pattern) use ($r): array {
        $out = [];
        $cursor = null;
        while (($batch = $r->scan($cursor, $pattern, 500)) !== false) {
            if (is_array($batch)) $out = array_merge($out, $batch);
            if ($cursor === 0) break;
        }
        return array_unique($out);
    };

    // ─── Request counters ────────────────────────────────────
    echo "# HELP superbora_requests_total Total HTTP requests handled\n";
    echo "# TYPE superbora_requests_total counter\n";
    foreach ($scanAll('metrics:req_count:*') as $key) {
        $val = (int)$r->get($key);
        $raw = substr($key, strlen('metrics:req_count:'));
        $lastColon = strrpos($raw, ':');
        if ($lastColon === false) continue;
        $label = substr($raw, 0, $lastColon);
        $status = substr($raw, $lastColon + 1);
        $space = strpos($label, ' ');
        $method = $space !== false ? substr($label, 0, $space) : 'GET';
        $path = $space !== false ? substr($label, $space + 1) : $label;

        $methodEsc = addcslashes($method, '"\\');
        $pathEsc = addcslashes($path, '"\\');
        echo "superbora_requests_total{method=\"{$methodEsc}\",path=\"{$pathEsc}\",status=\"{$status}\"} {$val}\n";
    }

    // ─── Latency summary (p50, p95, p99 from rolling 1000-sample window) ───
    echo "\n# HELP superbora_request_duration_ms Request duration in ms\n";
    echo "# TYPE superbora_request_duration_ms summary\n";
    foreach ($scanAll('metrics:req_dur_ms:*') as $key) {
        $label = substr($key, strlen('metrics:req_dur_ms:'));
        $space = strpos($label, ' ');
        $method = $space !== false ? substr($label, 0, $space) : 'GET';
        $path = $space !== false ? substr($label, $space + 1) : $label;

        $samples = $r->lRange($key, 0, 999);
        if (empty($samples)) continue;
        $samples = array_map('floatval', $samples);
        sort($samples);
        $n = count($samples);
        $p50 = $samples[(int)floor($n * 0.5)] ?? 0;
        $p95 = $samples[(int)floor($n * 0.95)] ?? 0;
        $p99 = $samples[min($n - 1, (int)floor($n * 0.99))] ?? 0;
        $sum = array_sum($samples);

        $methodEsc = addcslashes($method, '"\\');
        $pathEsc = addcslashes($path, '"\\');
        $labels = "method=\"{$methodEsc}\",path=\"{$pathEsc}\"";
        echo "superbora_request_duration_ms{{$labels},quantile=\"0.5\"} {$p50}\n";
        echo "superbora_request_duration_ms{{$labels},quantile=\"0.95\"} {$p95}\n";
        echo "superbora_request_duration_ms{{$labels},quantile=\"0.99\"} {$p99}\n";
        echo "superbora_request_duration_ms_sum{{$labels}} {$sum}\n";
        echo "superbora_request_duration_ms_count{{$labels}} {$n}\n";
    }

    $r->set('metrics:last_scrape_at', (string)time());
} catch (Exception $e) {
    echo "# error: " . $e->getMessage() . "\n";
}
