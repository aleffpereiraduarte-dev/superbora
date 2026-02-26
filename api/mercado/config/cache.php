<?php
/**
 * OmCache - Redis-first cache with file fallback
 * Uses Redis when available, falls back to file-based cache
 */

require_once __DIR__ . '/redis.php';

class OmCache {
    private static $instance = null;
    private $cacheDir;
    private $redis;

    private function __construct() {
        $this->cacheDir = '/var/lib/superbora/cache/';
        // Fallback to /tmp if preferred directory is not writable
        if (!is_dir($this->cacheDir) && !@mkdir($this->cacheDir, 0700, true)) {
            $this->cacheDir = '/tmp/om_cache/';
        }
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0700, true);
        }
        @chmod($this->cacheDir, 0700);
        $this->redis = RedisService::getInstance();
    }

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Obter valor do cache
     */
    public function get(string $key) {
        // Try Redis first
        if ($this->redis->isAvailable()) {
            $val = $this->redis->get("cache:$key");
            if ($val !== null) return $val;
            return null;
        }

        // File fallback
        return $this->fileGet($key);
    }

    /**
     * Armazenar valor no cache
     */
    public function set(string $key, $value, int $ttl = 60): bool {
        // Try Redis first
        if ($this->redis->isAvailable()) {
            return $this->redis->set("cache:$key", $value, $ttl);
        }

        // File fallback
        return $this->fileSet($key, $value, $ttl);
    }

    /**
     * Remover chave do cache
     */
    public function delete(string $key): bool {
        if ($this->redis->isAvailable()) {
            return $this->redis->delete("cache:$key");
        }
        return $this->fileDelete($key);
    }

    /**
     * Limpar chaves por prefixo
     */
    public function flush(string $prefix = ''): int {
        if ($this->redis->isAvailable()) {
            $pattern = $prefix ? "cache:$prefix*" : "cache:*";
            return $this->redis->flush($pattern);
        }
        return $this->fileFlush($prefix);
    }

    /**
     * Get or set: retorna cache se existir, senao executa callback
     */
    public function remember(string $key, int $ttl, callable $callback) {
        $cached = $this->get($key);
        if ($cached !== null) return $cached;

        $value = $callback();
        $this->set($key, $value, $ttl);
        return $value;
    }

    // ── File fallback methods ──

    private function fileGet(string $key) {
        $file = $this->getFilePath($key);
        if (!file_exists($file)) return null;

        $data = @file_get_contents($file);
        if ($data === false) return null;

        $entry = @json_decode($data, true);
        if (!$entry || !isset($entry['expires_at'])) return null;

        if (time() > $entry['expires_at']) {
            @unlink($file);
            return null;
        }

        return $entry['value'];
    }

    private function fileSet(string $key, $value, int $ttl): bool {
        $file = $this->getFilePath($key);
        $entry = json_encode([
            'key' => $key,
            'value' => $value,
            'expires_at' => time() + $ttl,
            'created_at' => time()
        ], JSON_UNESCAPED_UNICODE);

        return @file_put_contents($file, $entry, LOCK_EX) !== false;
    }

    private function fileDelete(string $key): bool {
        $file = $this->getFilePath($key);
        if (file_exists($file)) {
            return @unlink($file);
        }
        return true;
    }

    private function fileFlush(string $prefix = ''): int {
        $count = 0;
        if ($prefix) {
            $files = glob($this->cacheDir . '*.cache');
            foreach ($files as $file) {
                $data = @file_get_contents($file);
                if ($data) {
                    $entry = @json_decode($data, true);
                    if ($entry && isset($entry['key']) && strpos($entry['key'], $prefix) === 0) {
                        @unlink($file);
                        $count++;
                    }
                }
            }
        } else {
            $files = glob($this->cacheDir . '*.cache');
            foreach ($files as $file) {
                @unlink($file);
                $count++;
            }
        }
        return $count;
    }

    private function getFilePath(string $key): string {
        return $this->cacheDir . hash('sha256', $key) . '.cache';
    }
}

/**
 * Helper global
 */
function om_cache(): OmCache {
    return OmCache::getInstance();
}
