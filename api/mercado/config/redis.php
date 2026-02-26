<?php
/**
 * RedisService - Singleton Redis connection manager
 * Used by: OmCache, RateLimiter, JobQueue
 *
 * Falls back gracefully if Redis is unavailable.
 */

class RedisService {
    private static ?self $instance = null;
    private ?Redis $redis = null;
    private bool $available = false;

    private function __construct() {
        $this->connect();
    }

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function connect(): void {
        if (!class_exists('Redis')) {
            $this->available = false;
            return;
        }

        try {
            $this->redis = new Redis();
            $host = $_ENV['REDIS_HOST'] ?? '127.0.0.1';
            $port = (int)($_ENV['REDIS_PORT'] ?? 6379);

            // Connect with timeout
            $this->redis->connect($host, $port, 2.0);

            // Authenticate if password is configured
            $redisPassword = $_ENV['REDIS_PASSWORD'] ?? '';
            if (!empty($redisPassword)) {
                $this->redis->auth($redisPassword);
            }

            // Use database 0 for cache, 1 for queue, 2 for rate limiting
            $this->redis->select(0);
            $this->redis->setOption(Redis::OPT_PREFIX, 'sb:');
            $this->redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_JSON);

            $this->available = true;
        } catch (\RedisException $e) {
            error_log("[Redis] Connection failed: " . $e->getMessage());
            $this->available = false;
            $this->redis = null;
        }
    }

    public function isAvailable(): bool {
        return $this->available;
    }

    public function getConnection(): ?Redis {
        return $this->redis;
    }

    /**
     * Get a raw Redis connection for a specific database (no prefix/serializer changes)
     */
    public function getRawConnection(int $db = 0): ?Redis {
        if (!$this->available) return null;

        try {
            $raw = new Redis();
            $host = $_ENV['REDIS_HOST'] ?? '127.0.0.1';
            $port = (int)($_ENV['REDIS_PORT'] ?? 6379);
            $raw->connect($host, $port, 2.0);
            $password = $_ENV["REDIS_PASSWORD"] ?? getenv("REDIS_PASSWORD") ?: "Aleff2009@Redis";
            if (!empty($password)) { $raw->auth($password); }
            $raw->select($db);
            return $raw;
        } catch (\RedisException $e) {
            error_log("[Redis] Raw connection failed: " . $e->getMessage());
            return null;
        }
    }

    // Shortcut methods
    public function get(string $key) {
        if (!$this->available) return null;
        try {
            $val = $this->redis->get($key);
            return $val === false ? null : $val;
        } catch (\RedisException $e) {
            return null;
        }
    }

    public function set(string $key, $value, int $ttl = 0): bool {
        if (!$this->available) return false;
        try {
            if ($ttl > 0) {
                return $this->redis->setex($key, $ttl, $value);
            }
            return $this->redis->set($key, $value);
        } catch (\RedisException $e) {
            return false;
        }
    }

    public function delete(string $key): bool {
        if (!$this->available) return false;
        try {
            return $this->redis->del($key) > 0;
        } catch (\RedisException $e) {
            return false;
        }
    }

    public function incr(string $key): int {
        if (!$this->available) return 0;
        try {
            return $this->redis->incr($key);
        } catch (\RedisException $e) {
            return 0;
        }
    }

    public function expire(string $key, int $ttl): bool {
        if (!$this->available) return false;
        try {
            return $this->redis->expire($key, $ttl);
        } catch (\RedisException $e) {
            return false;
        }
    }

    public function ttl(string $key): int {
        if (!$this->available) return -2;
        try {
            return $this->redis->ttl($key);
        } catch (\RedisException $e) {
            return -2;
        }
    }

    public function flush(string $pattern = '*'): int {
        if (!$this->available) return 0;
        try {
            $keys = $this->redis->keys($pattern);
            if (empty($keys)) return 0;
            return $this->redis->del($keys);
        } catch (\RedisException $e) {
            return 0;
        }
    }
}

/**
 * Helper global
 */
function om_redis(): RedisService {
    return RedisService::getInstance();
}
