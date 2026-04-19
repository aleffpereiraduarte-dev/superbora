<?php
/**
 * Simple request metrics collector.
 * Records each request's endpoint + status + duration into Redis sorted sets
 * for later aggregation by /metrics endpoint (Prometheus format).
 *
 * Storage keys (Redis):
 *   metrics:req_count:{endpoint}:{status}  — INCR counter
 *   metrics:req_dur_ms:{endpoint}          — LPUSH of last 1000 durations (ms)
 *   metrics:last_scrape_at                 — timestamp of last /metrics scrape
 *
 * Auto-hooks at shutdown — zero call overhead for endpoint code.
 */

if (!function_exists('_metricsStart')) {

    $__METRICS_START = microtime(true);

    function _metricsGetRedis() {
        static $r = null;
        if ($r === false) return null; // previously failed
        if ($r) return $r;
        try {
            $r = new Redis();
            $ok = @$r->connect($_ENV['REDIS_HOST'] ?? '127.0.0.1', (int)($_ENV['REDIS_PORT'] ?? 6379), 0.3);
            if (!$ok) { $r = false; return null; }
            $pwd = $_ENV['REDIS_PASSWORD'] ?? getenv('REDIS_PASSWORD') ?: '';
            if ($pwd) $r->auth($pwd);
            return $r;
        } catch (Exception $e) {
            $r = false;
            return null;
        }
    }

    function _metricsEndpointLabel(): string {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        // Normalize dynamic segments (numeric IDs) to :id
        $uri = preg_replace('#/\d+(/|$)#', '/:id$1', $uri);
        // Strip /api/mercado prefix for cleaner labels
        $uri = preg_replace('#^/api/mercado#', '', $uri);
        return substr($uri, 0, 120);
    }

    function _metricsShutdown() {
        global $__METRICS_START;
        try {
            $r = _metricsGetRedis();
            if (!$r) return;

            $dur_ms = max(0.1, (microtime(true) - $__METRICS_START) * 1000);
            $endpoint = _metricsEndpointLabel();
            $status = http_response_code() ?: 200;
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

            $label = "{$method} {$endpoint}";

            // Count requests by endpoint + status
            $r->incr("metrics:req_count:{$label}:{$status}");

            // Rolling window of last 1000 durations per endpoint
            $durKey = "metrics:req_dur_ms:{$label}";
            $r->lPush($durKey, (string)round($dur_ms, 1));
            $r->lTrim($durKey, 0, 999);
            $r->expire($durKey, 3600); // keep last hour

            // Global: total bytes served (content-length)
            $r->incrBy('metrics:bytes_out_total', (int)(ob_get_length() ?: 0));
        } catch (Exception $e) { /* never break the response */ }
    }

    function _metricsStart() {
        register_shutdown_function('_metricsShutdown');
    }

    // Auto-start when this file is required
    _metricsStart();
}
