<?php
/**
 * SUPERBORA - Cache System
 * Cache em arquivo com TTL (sem Redis)
 */

class OmCache {
    private string $cache_dir;
    private static ?OmCache $instance = null;

    public function __construct() {
        $this->cache_dir = __DIR__ . '/../../cache/';
        if (!is_dir($this->cache_dir)) {
            mkdir($this->cache_dir, 0755, true);
        }
    }

    public static function getInstance(): OmCache {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Salvar no cache
     */
    public function set(string $key, mixed $value, int $ttl = 3600): bool {
        $file = $this->getFilePath($key);
        $data = [
            'expires' => time() + $ttl,
            'value' => $value
        ];
        return file_put_contents($file, serialize($data), LOCK_EX) !== false;
    }

    /**
     * Buscar do cache
     */
    public function get(string $key, mixed $default = null): mixed {
        $file = $this->getFilePath($key);

        if (!file_exists($file)) {
            return $default;
        }

        $data = unserialize(file_get_contents($file));

        if (!$data || $data['expires'] < time()) {
            $this->delete($key);
            return $default;
        }

        return $data['value'];
    }

    /**
     * Verificar se existe no cache
     */
    public function has(string $key): bool {
        return $this->get($key) !== null;
    }

    /**
     * Deletar do cache
     */
    public function delete(string $key): bool {
        $file = $this->getFilePath($key);
        if (file_exists($file)) {
            return unlink($file);
        }
        return true;
    }

    /**
     * Limpar cache expirado
     */
    public function cleanup(): int {
        $count = 0;
        $files = glob($this->cache_dir . '*.cache');

        foreach ($files as $file) {
            $data = @unserialize(file_get_contents($file));
            if (!$data || $data['expires'] < time()) {
                unlink($file);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Limpar todo o cache
     */
    public function flush(): bool {
        $files = glob($this->cache_dir . '*.cache');
        foreach ($files as $file) {
            unlink($file);
        }
        return true;
    }

    /**
     * Cache com callback (get or set)
     */
    public function remember(string $key, int $ttl, callable $callback): mixed {
        $value = $this->get($key);

        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }

    private function getFilePath(string $key): string {
        return $this->cache_dir . md5($key) . '.cache';
    }
}

// Helper function
function om_cache(): OmCache {
    return OmCache::getInstance();
}
