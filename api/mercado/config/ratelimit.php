<?php
/**
 * Rate Limiting - Redis-first with APCu/File fallback
 * Protege APIs contra abuso com contadores por IP/token
 *
 * Priority: Redis > APCu > File
 */

require_once __DIR__ . '/redis.php';

function checkRateLimit(string $key, int $maxRequests = 60, int $windowSeconds = 60): bool {
    // 1. Try Redis (fastest, shared across workers)
    $redis = RedisService::getInstance();
    if ($redis->isAvailable()) {
        return checkRateLimitRedis($redis, $key, $maxRequests, $windowSeconds);
    }

    // 2. Try APCu (in-memory, per-worker)
    if (function_exists('apcu_fetch') && apcu_enabled()) {
        return checkRateLimitApcu($key, $maxRequests, $windowSeconds);
    }

    // 3. Fallback to file-based
    return checkRateLimitFile($key, $maxRequests, $windowSeconds);
}

/**
 * Redis-based rate limiting (preferred — atomic, shared across workers)
 */
function checkRateLimitRedis(RedisService $redis, string $key, int $maxRequests, int $windowSeconds): bool {
    $raw = $redis->getRawConnection(2); // DB 2 for rate limiting
    if (!$raw) {
        // Redis connection failed, fall through to APCu/file
        if (function_exists('apcu_fetch') && apcu_enabled()) {
            return checkRateLimitApcu($key, $maxRequests, $windowSeconds);
        }
        return checkRateLimitFile($key, $maxRequests, $windowSeconds);
    }

    try {
        $redisKey = 'rl:' . hash('sha256', $key);

        // Atomic increment + expire using MULTI
        $count = $raw->incr($redisKey);

        if ($count === 1) {
            // First request in window — set expiry
            $raw->expire($redisKey, $windowSeconds);
        }

        if ($count > $maxRequests) {
            $ttl = $raw->ttl($redisKey);
            $retryAfter = max(1, $ttl);
            header('Retry-After: ' . $retryAfter);
            header('X-RateLimit-Limit: ' . $maxRequests);
            header('X-RateLimit-Remaining: 0');
            http_response_code(429);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => 'Muitas requisicoes. Tente novamente em ' . $retryAfter . ' segundos.',
                'data' => null,
                'timestamp' => date('c')
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        setRateLimitHeaders($maxRequests, $maxRequests - $count);
        $raw->close();
        return true;
    } catch (\RedisException $e) {
        error_log("[RateLimit] Redis error: " . $e->getMessage());
        // Fall through to APCu/file
        if (function_exists('apcu_fetch') && apcu_enabled()) {
            return checkRateLimitApcu($key, $maxRequests, $windowSeconds);
        }
        return checkRateLimitFile($key, $maxRequests, $windowSeconds);
    }
}

/**
 * APCu-based rate limiting
 */
function checkRateLimitApcu(string $key, int $maxRequests, int $windowSeconds): bool {
    $cacheKey = 'ratelimit:' . hash('sha256', $key);

    $data = apcu_fetch($cacheKey);
    if ($data === false) {
        $data = ['count' => 1, 'window_start' => time()];
        apcu_store($cacheKey, $data, $windowSeconds);
        setRateLimitHeaders($maxRequests, $maxRequests - 1);
        return true;
    }

    if (time() - $data['window_start'] >= $windowSeconds) {
        $data = ['count' => 1, 'window_start' => time()];
        apcu_store($cacheKey, $data, $windowSeconds);
        setRateLimitHeaders($maxRequests, $maxRequests - 1);
        return true;
    }

    $data['count']++;

    if ($data['count'] > $maxRequests) {
        return rateLimitExceeded($maxRequests, $windowSeconds, $data['window_start']);
    }

    apcu_store($cacheKey, $data, $windowSeconds);
    setRateLimitHeaders($maxRequests, $maxRequests - $data['count']);
    return true;
}

/**
 * File-based rate limiting (last resort)
 */
function checkRateLimitFile(string $key, int $maxRequests, int $windowSeconds): bool {
    $dir = '/var/lib/superbora/ratelimit/';
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0700, true)) {
            $dir = '/tmp/.om_rl_' . substr(hash('sha256', __DIR__), 0, 16) . '/';
            if (!is_dir($dir)) {
                @mkdir($dir, 0700, true);
            }
        }
    }

    $file = $dir . hash('sha256', $key) . '.dat';

    $data = ['count' => 0, 'window_start' => time()];
    if (file_exists($file)) {
        $content = @file_get_contents($file);
        if ($content) {
            $decoded = json_decode($content, true);
            if (is_array($decoded) && isset($decoded['count']) && isset($decoded['window_start'])) {
                $data = $decoded;
            }
        }
    }

    if (time() - $data['window_start'] >= $windowSeconds) {
        $data = ['count' => 1, 'window_start' => time()];
        @file_put_contents($file, json_encode($data), LOCK_EX);
        @chmod($file, 0600);
        setRateLimitHeaders($maxRequests, $maxRequests - 1);
        return true;
    }

    $data['count']++;

    if ($data['count'] > $maxRequests) {
        return rateLimitExceeded($maxRequests, $windowSeconds, $data['window_start']);
    }

    @file_put_contents($file, json_encode($data), LOCK_EX);
    @chmod($file, 0600);

    setRateLimitHeaders($maxRequests, $maxRequests - $data['count']);
    return true;
}

function setRateLimitHeaders(int $limit, int $remaining): void {
    header('X-RateLimit-Limit: ' . $limit);
    header('X-RateLimit-Remaining: ' . max(0, $remaining));
}

function rateLimitExceeded(int $maxRequests, int $windowSeconds, int $windowStart): bool {
    $retryAfter = $windowSeconds - (time() - $windowStart);
    header('Retry-After: ' . max(1, $retryAfter));
    header('X-RateLimit-Limit: ' . $maxRequests);
    header('X-RateLimit-Remaining: 0');
    http_response_code(429);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => 'Muitas requisicoes. Tente novamente em ' . max(1, $retryAfter) . ' segundos.',
        'data' => null,
        'timestamp' => date('c')
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Check if an IP is within a CIDR range (IPv4 only)
 */
function ipInCidr(string $ip, string $cidr): bool {
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return false;
    list($subnet, $bits) = explode('/', $cidr, 2);
    $ipLong = ip2long($ip);
    $subnetLong = ip2long($subnet);
    $mask = -1 << (32 - (int)$bits);
    return ($ipLong & $mask) === ($subnetLong & $mask);
}

function applyRateLimit(): void {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $trustedProxies = ['127.0.0.1', '::1'];
    if (in_array($ip, $trustedProxies, true)) {
        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($forwarded) {
            $ips = array_map('trim', explode(',', $forwarded));
            $candidate = $ips[0]; // SECURITY: Use first (leftmost/client) IP, not last
            if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                $ip = $candidate;
            }
        }
    }

    // Cloudflare real IP - only trust when behind Cloudflare or explicitly configured
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $trustProxy = !empty(getenv('TRUST_PROXY_HEADERS'));
        $cloudflareRanges = [
            '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
            '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
            '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
            '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        ];
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
        $isCfProxy = false;
        foreach ($cloudflareRanges as $range) {
            if (ipInCidr($remoteAddr, $range)) {
                $isCfProxy = true;
                break;
            }
        }
        if ($isCfProxy || $trustProxy) {
            $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
        }
    }

    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = '';
    if (preg_match('/Bearer\s+(.+)/i', $authHeader, $m)) {
        $token = $m[1];
    }

    // Login: 5/min per IP
    if (preg_match('/(login|auth|register)/', $uri)) {
        checkRateLimit("login:$ip", 5, 60);
        return;
    }

    // Checkout: 10/min per token/IP
    if (strpos($uri, 'checkout') !== false) {
        $key = $token ? "checkout:$token" : "checkout:$ip";
        checkRateLimit($key, 10, 60);
        return;
    }

    // Authenticated APIs: 120/min per token
    if ($token) {
        checkRateLimit("api:$token", 120, 60);
        return;
    }

    // Public APIs: 30/min per IP
    checkRateLimit("pub:$ip", 30, 60);
}
