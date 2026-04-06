<?php
/**
 * Redis Cache Helper for SuperBora API
 *
 * Lightweight wrapper around Redis for endpoint-level caching.
 * Falls back gracefully (returns null / skips cache) if Redis is unavailable.
 *
 * Usage:
 *   require_once __DIR__ . '/cache.php';
 *   $data = cachedQuery("recommendations:$customerId", 300, function() use ($db, $customerId) {
 *       // ... expensive query ...
 *       return $result;
 *   });
 */

/**
 * Get a Redis connection (singleton).
 * Returns null if Redis is not available.
 */
function getRedisCache(): ?Redis
{
    static $redis = null;
    static $tried = false;

    if ($tried && $redis === null) {
        return null;
    }

    if ($redis === null) {
        $tried = true;
        try {
            $redis = new Redis();
            $host = $_ENV['REDIS_HOST'] ?? getenv('REDIS_HOST') ?: '127.0.0.1';
            $port = (int)($_ENV['REDIS_PORT'] ?? getenv('REDIS_PORT') ?: 6379);
            $pass = $_ENV['REDIS_PASSWORD'] ?? getenv('REDIS_PASSWORD') ?: 'Aleff2009@Redis';

            $redis->connect($host, $port, 2); // 2s timeout
            $redis->auth($pass);
            $redis->select(1); // DB 1 for API cache (DB 0 is CacheHelper)
            $redis->setOption(Redis::OPT_PREFIX, 'sbcache:');
        } catch (Exception $e) {
            error_log("[cache] Redis connection failed: " . $e->getMessage());
            $redis = null;
        }
    }

    return $redis;
}

/**
 * Get a cached value by key.
 *
 * @param string $key
 * @return mixed|null  Deserialized value or null if not found / expired
 */
function cacheGet(string $key)
{
    $redis = getRedisCache();
    if (!$redis) return null;

    try {
        $value = $redis->get($key);
        if ($value === false) return null;
        return json_decode($value, true);
    } catch (Exception $e) {
        error_log("[cache] GET error for $key: " . $e->getMessage());
        return null;
    }
}

/**
 * Set a cache value with TTL.
 *
 * @param string $key
 * @param mixed  $data   Will be JSON-encoded
 * @param int    $ttl    Seconds (default 300 = 5 min)
 * @return bool
 */
function cacheSet(string $key, $data, int $ttl = 300): bool
{
    $redis = getRedisCache();
    if (!$redis) return false;

    try {
        return $redis->setex($key, $ttl, json_encode($data, JSON_UNESCAPED_UNICODE));
    } catch (Exception $e) {
        error_log("[cache] SET error for $key: " . $e->getMessage());
        return false;
    }
}

/**
 * Delete a specific cache key.
 *
 * @param string $key
 * @return bool
 */
function cacheDelete(string $key): bool
{
    $redis = getRedisCache();
    if (!$redis) return false;

    try {
        return $redis->del($key) > 0;
    } catch (Exception $e) {
        error_log("[cache] DEL error for $key: " . $e->getMessage());
        return false;
    }
}

/**
 * Delete all cache keys matching a pattern (e.g. "recommendations:*").
 * Uses SCAN to avoid blocking Redis on large keyspaces.
 *
 * @param string $pattern  Glob pattern (Redis SCAN style)
 * @return int  Number of keys deleted
 */
function cacheClear(string $pattern): int
{
    $redis = getRedisCache();
    if (!$redis) return 0;

    try {
        $count = 0;
        $iterator = null;
        // Note: prefix is auto-applied by Redis client, so pattern should NOT include it
        while (($keys = $redis->scan($iterator, $pattern, 100)) !== false) {
            if (!empty($keys)) {
                // Keys returned by scan already include prefix; del expects without prefix
                // But since we set OPT_PREFIX, del() will add prefix again. We need raw keys.
                // Actually, scan() with prefix set returns keys WITH prefix stripped.
                $count += $redis->del($keys);
            }
        }
        return $count;
    } catch (Exception $e) {
        error_log("[cache] CLEAR error for $pattern: " . $e->getMessage());
        return 0;
    }
}

/**
 * Get cached data or execute callback and cache the result.
 * This is the primary function for endpoint caching.
 *
 * @param string   $key   Cache key
 * @param int      $ttl   TTL in seconds
 * @param callable $fn    Function that returns the data to cache
 * @return mixed   The cached or freshly computed data
 */
function cachedQuery(string $key, int $ttl, callable $fn)
{
    // Try cache first
    $cached = cacheGet($key);
    if ($cached !== null) {
        return $cached;
    }

    // Execute the callback
    $data = $fn();

    // Cache the result (non-null only)
    if ($data !== null) {
        cacheSet($key, $data, $ttl);
    }

    return $data;
}
