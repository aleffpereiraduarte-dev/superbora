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

/**
 * Invalidate cache keys matching one or more raw (un-prefixed) glob patterns.
 *
 * Used by listing-invalidation helpers below. Bypasses OPT_PREFIX so we don't
 * hit the double-prefix bug described in cacheInvalidateCart().
 *
 * @param string[] $patterns  List of glob patterns WITHOUT the 'sbcache:' prefix.
 * @return int  Total keys deleted.
 */
function cacheInvalidatePatterns(array $patterns): int
{
    $redis = getRedisCache();
    if (!$redis) return 0;

    $total = 0;
    try {
        $prefix = $redis->getOption(Redis::OPT_PREFIX) ?: '';
        $redis->setOption(Redis::OPT_PREFIX, '');
        $redis->setOption(Redis::OPT_SCAN, Redis::SCAN_RETRY);

        foreach ($patterns as $pattern) {
            $full = $prefix . $pattern;
            $iterator = null;
            $safety = 0;
            while (($keys = $redis->scan($iterator, $full, 200)) !== false) {
                if (!empty($keys)) $total += $redis->del($keys);
                if (++$safety > 100) break;
            }
        }

        $redis->setOption(Redis::OPT_PREFIX, $prefix);
    } catch (Exception $e) {
        error_log("[cache] invalidate patterns error: " . $e->getMessage());
    }
    return $total;
}

/**
 * Invalidate cached partner detail + vitrine entries for a single partner.
 * Called when admin/partner mutates store-level data (status, hours, info).
 */
function cacheInvalidatePartner(int $partnerId): void
{
    if ($partnerId <= 0) return;
    cacheInvalidatePatterns([
        "partner:{$partnerId}",
        "partner:{$partnerId}:*",
        // vitrine listings depend on partner status/info — drop them all
        "vitrine:*",
        // home.php cached responses
        "home:*",
    ]);

    // Bridge to the legacy CacheHelper (DB 0) where parceiro_detalhes_X + home_*
    // live. Kept best-effort so a CacheHelper outage doesn't block writes.
    try {
        $cacheHelperPath = dirname(__DIR__, 2) . '/cache/CacheHelper.php';
        if (file_exists($cacheHelperPath)) {
            require_once $cacheHelperPath;
            if (class_exists('CacheHelper')) {
                CacheHelper::forget("parceiro_detalhes_{$partnerId}");
                CacheHelper::forgetPattern("home_");
            }
        }
    } catch (\Throwable $e) { /* non-critical */ }
}

/**
 * Invalidate cached product listings for a partner. Called when products
 * change (price/status/stock/create/delete). Also clears partner detail
 * because detalhes.php returns the categories list which depends on products.
 */
function cacheInvalidateProducts(int $partnerId): void
{
    if ($partnerId <= 0) return;
    cacheInvalidatePatterns([
        "products:{$partnerId}:*",
        "partner:{$partnerId}",
        "partner:{$partnerId}:*",
        "home:*", // home includes destaques + populares for the matched partner
    ]);

    try {
        $cacheHelperPath = dirname(__DIR__, 2) . '/cache/CacheHelper.php';
        if (file_exists($cacheHelperPath)) {
            require_once $cacheHelperPath;
            if (class_exists('CacheHelper')) {
                CacheHelper::forget("parceiro_detalhes_{$partnerId}");
                CacheHelper::forgetPattern("mercado_produtos_");
                CacheHelper::forgetPattern("home_");
            }
        }
    } catch (\Throwable $e) { /* non-critical */ }
}

/**
 * Drop ALL vitrine listings — used when admin suspends/activates a store
 * since the store list changes for everyone in that city.
 */
function cacheInvalidateVitrine(): void
{
    cacheInvalidatePatterns(["vitrine:*", "home:*"]);
    try {
        $cacheHelperPath = dirname(__DIR__, 2) . '/cache/CacheHelper.php';
        if (file_exists($cacheHelperPath)) {
            require_once $cacheHelperPath;
            if (class_exists('CacheHelper')) {
                CacheHelper::forgetPattern("home_");
            }
        }
    } catch (\Throwable $e) { /* non-critical */ }
}

/**
 * Invalidate all cart listar.php cache entries for a given owner.
 *
 * listar.php keys include route_mode + primary_partner_id so the same cart
 * may have up to ~4 cached variants (rm=0/1 × pp=0/specific). We use SCAN
 * against the raw-prefixed keyspace so we don't depend on phpredis version-
 * specific prefix-stripping behavior (which was the bug in v1).
 *
 * Called by every cart mutation endpoint (adicionar/remover/limpar/cupom)
 * AFTER commit and BEFORE response, so the next listar.php call always hits
 * the DB once and repopulates the cache.
 *
 * @param int    $customerId  0 for anonymous
 * @param string $sessionId   empty for authenticated
 */
function cacheInvalidateCart(int $customerId, string $sessionId = ''): void
{
    $redis = getRedisCache();
    if (!$redis) return;

    try {
        if ($customerId > 0) {
            $basePattern = "cart_listar:customer_{$customerId}:*";
        } elseif ($sessionId) {
            $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId);
            if ($safe === '') return;
            $basePattern = "cart_listar:sess_{$safe}:*";
        } else {
            return;
        }

        // Temporarily clear the prefix option for scan+del so we work with the
        // fully-qualified keys. This avoids the "scan returns prefixed keys but
        // del re-applies prefix" double-prefix bug in some phpredis versions.
        $prefix = $redis->getOption(Redis::OPT_PREFIX) ?: '';
        $fullPattern = $prefix . $basePattern;
        $redis->setOption(Redis::OPT_PREFIX, '');

        $iterator = null;
        $redis->setOption(Redis::OPT_SCAN, Redis::SCAN_RETRY);
        $safety = 0;
        while (($keys = $redis->scan($iterator, $fullPattern, 100)) !== false) {
            if (!empty($keys)) $redis->del($keys);
            if (++$safety > 50) break;
        }

        $redis->setOption(Redis::OPT_PREFIX, $prefix);
    } catch (Exception $e) {
        error_log("[cache] cart invalidate error: " . $e->getMessage());
    }
}
