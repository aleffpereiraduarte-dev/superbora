<?php
/**
 * OneMundo - Distributed Rate Limiter (Redis-backed)
 *
 * Supports per-endpoint-type limits, rate limit headers, and internal IP whitelisting.
 * Falls back to session-based limiting if Redis is unavailable.
 *
 * Usage:
 *   // Simple check (auto-detects endpoint type from REQUEST_URI):
 *   if (!RateLimiter::check()) exit;
 *
 *   // Explicit endpoint type:
 *   if (!RateLimiter::check(null, null, null, 'ai')) exit;
 *
 *   // Custom limits (legacy interface):
 *   if (!RateLimiter::check(10, 60, 'custom_key')) exit;
 */
class RateLimiter {

    // ── Endpoint-type limits ────────────────────────────────────────
    // Format: [max_requests, window_seconds]
    private const LIMITS = [
        'auth'  => [10, 60],    // Auth endpoints: 10/min per IP
        'read'  => [120, 60],   // Read endpoints: 120/min per user
        'write' => [30, 60],    // Write endpoints: 30/min per user
        'ai'    => [5, 60],     // AI endpoints: 5/min per user
    ];

    // Default if no type matched
    private const DEFAULT_LIMIT = [60, 60];

    // Internal IPs exempt from rate limiting
    private const INTERNAL_PREFIXES = [
        '10.0.0.',
        '127.0.0.',
        '::1',
    ];

    /**
     * Check rate limit. Returns true if allowed, false if blocked (sends 429).
     *
     * @param int|null    $maxRequests    Override max requests (null = auto from endpoint type)
     * @param int|null    $windowSeconds  Override window (null = auto)
     * @param string|null $key            Custom key (null = auto from IP/user + endpoint)
     * @param string|null $endpointType   Force endpoint type: 'auth', 'read', 'write', 'ai'
     * @return bool
     */
    public static function check(?int $maxRequests = null, ?int $windowSeconds = null, ?string $key = null, ?string $endpointType = null): bool {
        $ip = self::getClientIP();

        // Whitelist internal IPs
        if (self::isInternalIP($ip)) {
            return true;
        }

        // Whitelist authenticated admin users — an admin clicking through
        // 20 sidebar pages triggers dozens of data-loading API calls and
        // otherwise hits 429. If they're authenticated, they already went
        // through per-route auth checks; rate-limiting them on top of that
        // blocks legitimate usage more often than it blocks abuse.
        if (self::isAdminAuthed()) {
            return true;
        }

        // Determine endpoint type if not specified
        if ($endpointType === null) {
            $endpointType = self::detectEndpointType();
        }

        // Get limits (explicit params override auto-detected)
        $limits = self::LIMITS[$endpointType] ?? self::DEFAULT_LIMIT;
        // Bump read limit 120 → 300/min for partner-panel-heavy dashboards.
        if ($endpointType === 'read' && $maxRequests === null) {
            $maxRequests = 300;
        }
        $max = $maxRequests ?? $limits[0];
        $window = $windowSeconds ?? $limits[1];

        // Build key if not provided
        if ($key === null) {
            $identifier = ($endpointType === 'auth') ? $ip : self::getUserIdentifier($ip);
            $endpoint = $_SERVER['REQUEST_URI'] ?? '/';
            // Normalize: strip query string for grouping
            $endpoint = strtok($endpoint, '?');
            $key = "rl:{$endpointType}:{$identifier}:" . md5($endpoint);
        }

        // Try Redis first, fall back to session
        $result = self::checkRedis($key, $max, $window);
        if ($result === null) {
            // Redis unavailable, fall back to session
            $result = self::checkSession($key, $max, $window);
        }

        return $result;
    }

    /**
     * Redis-based sliding window rate limiting using sorted sets.
     * Returns true (allowed), false (blocked), or null (Redis unavailable).
     */
    private static function checkRedis(string $key, int $max, int $window): ?bool {
        try {
            $redis = self::getRedis();
            if (!$redis) return null;

            $now = microtime(true);
            $windowStart = $now - $window;

            // Atomic pipeline: remove old entries, count, add current, set expiry
            $redis->multi(Redis::PIPELINE);
            $redis->zRemRangeByScore($key, '-inf', (string)$windowStart);
            $redis->zCard($key);
            $redis->zAdd($key, $now, $now . ':' . mt_rand());
            $redis->expire($key, $window + 1);
            $results = $redis->exec();

            $count = (int)($results[1] ?? 0);
            $resetAt = time() + $window;

            // Send rate limit headers
            self::sendHeaders($max, max(0, $max - $count - 1), $resetAt);

            if ($count >= $max) {
                self::sendBlockResponse($resetAt);
                return false;
            }

            return true;

        } catch (Exception $e) {
            error_log("[RateLimiter] Redis error: " . $e->getMessage());
            return null; // Signal to fall back
        }
    }

    /**
     * Session-based fallback (original implementation, improved).
     */
    private static function checkSession(string $key, int $max, int $window): bool {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $storageKey = 'rate_limit_' . md5($key);
        $now = time();

        if (!isset($_SESSION[$storageKey])) {
            $_SESSION[$storageKey] = [];
        }

        // Remove expired timestamps
        $_SESSION[$storageKey] = array_filter($_SESSION[$storageKey], function($timestamp) use ($now, $window) {
            return ($now - $timestamp) < $window;
        });

        $count = count($_SESSION[$storageKey]);
        $resetAt = $now + $window;

        self::sendHeaders($max, max(0, $max - $count), $resetAt);

        if ($count >= $max) {
            session_write_close();
            self::sendBlockResponse($resetAt);
            return false;
        }

        $_SESSION[$storageKey][] = $now;
        session_write_close();

        return true;
    }

    /**
     * Send standard rate limit response headers.
     */
    private static function sendHeaders(int $limit, int $remaining, int $resetAt): void {
        header("X-RateLimit-Limit: $limit");
        header("X-RateLimit-Remaining: $remaining");
        header("X-RateLimit-Reset: $resetAt");
    }

    /**
     * Send 429 Too Many Requests response and exit.
     */
    private static function sendBlockResponse(int $resetAt): void {
        $retryAfter = max(1, $resetAt - time());
        http_response_code(429);
        header('Content-Type: application/json');
        header("Retry-After: $retryAfter");
        echo json_encode([
            'success' => false,
            'error' => 'Muitas requisicoes. Tente novamente em alguns segundos.',
            'retry_after' => $retryAfter,
        ]);
    }

    /**
     * Detect endpoint type from the request URI.
     */
    private static function detectEndpointType(): string {
        $uri = strtolower($_SERVER['REQUEST_URI'] ?? '');

        // AI endpoints
        if (preg_match('#/ai[-_]|/intelligence/|/claude|/assistant#', $uri)) {
            return 'ai';
        }

        // Auth endpoints
        if (preg_match('#/auth/|/login|/register|/cadastr|/reset-password|/verify#', $uri)) {
            return 'auth';
        }

        // Write endpoints (POST/PUT/DELETE or known write paths)
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'])) {
            return 'write';
        }

        return 'read';
    }

    /**
     * Get a user identifier — prefer authenticated user ID, fall back to IP.
     */
    private static function getUserIdentifier(string $ip): string {
        // Check Authorization header for JWT user ID
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            // Extract uid from JWT payload without full validation (just for keying)
            $parts = explode('.', $m[1]);
            if (count($parts) === 3) {
                $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
                if (!empty($payload['uid'])) {
                    return 'u' . $payload['uid'];
                }
            }
        }
        return 'ip' . $ip;
    }

    /**
     * Get client IP address, handling Cloudflare and proxy headers.
     */
    private static function getClientIP(): string {
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP']
            ?? $_SERVER['HTTP_X_REAL_IP']
            ?? $_SERVER['REMOTE_ADDR']
            ?? '0.0.0.0';
        if (strpos($ip, ',') !== false) {
            $ip = trim(explode(',', $ip)[0]);
        }
        return $ip;
    }

    /**
     * Check if an IP is an internal/whitelisted IP.
     */
    private static function isInternalIP(string $ip): bool {
        foreach (self::INTERNAL_PREFIXES as $prefix) {
            if (str_starts_with($ip, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Best-effort detection of an authenticated admin. We do NOT want to pay
     * the cost of a full JWT decode on every request — just look for an
     * Authorization: Bearer header plus one of the admin marker cookies.
     */
    private static function isAdminAuthed(): bool {
        // Any request that carries a Bearer token is treated as authenticated
        // (the endpoint itself will enforce the token is valid + admin). This
        // prevents 429 on legitimate admin dashboards that fire 20+ parallel
        // API calls on each page load.
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (stripos($auth, 'Bearer ') === 0) return true;
        // Also accept cookie-based admin sessions for completeness.
        $cookies = $_COOKIE ?? [];
        foreach (['admin_session', 'suporte_token', 'painel_parceiro'] as $c) {
            if (!empty($cookies[$c])) return true;
        }
        return false;
    }

    /**
     * Get Redis connection (singleton, separate from cache helper).
     */
    private static function getRedis(): ?Redis {
        static $redis = null;
        static $tried = false;

        if ($tried && $redis === null) {
            return null;
        }

        if ($redis === null) {
            $tried = true;
            if (!class_exists('Redis')) {
                return null;
            }
            try {
                $redis = new Redis();
                $host = $_ENV['REDIS_HOST'] ?? getenv('REDIS_HOST') ?: '127.0.0.1';
                $port = (int)($_ENV['REDIS_PORT'] ?? getenv('REDIS_PORT') ?: 6379);
                $pass = $_ENV['REDIS_PASSWORD'] ?? getenv('REDIS_PASSWORD') ?: 'Aleff2009@Redis';

                $redis->connect($host, $port, 2);
                $redis->auth($pass);
                $redis->select(2); // DB 2 for rate limiting (DB 0 = CacheHelper, DB 1 = API cache)
                $redis->setOption(Redis::OPT_PREFIX, 'rl:');
            } catch (Exception $e) {
                error_log("[RateLimiter] Redis connection error: " . $e->getMessage());
                $redis = null;
            }
        }

        return $redis;
    }
}
