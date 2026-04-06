<?php
/**
 * /api/mercado/admin/rate-limits.php
 *
 * Rate limiting dashboard — view live stats, blocked IPs, and manage limits.
 *
 * GET:                     Overview: config, live stats, top IPs, blocked IPs
 * GET ?action=blocked:     List currently blocked IPs with TTL
 * POST ?action=unblock&ip=X:  Manually unblock an IP
 * POST ?action=update:     Update rate limit for an endpoint {endpoint, requests_per_minute, burst_limit}
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();
    $admin_id = (int)$payload['uid'];

    $method = $_SERVER['REQUEST_METHOD'];
    $action = trim($_GET['action'] ?? '');

    // ── Redis connection for rate limit data (DB 2 = rate limiting) ──
    require_once __DIR__ . "/../config/redis.php";
    $redis = RedisService::getInstance();

    // =================== GET ===================
    if ($method === 'GET') {

        // ── List blocked IPs ──
        if ($action === 'blocked') {
            $blocked = getBlockedIps($redis);
            response(true, ['blocked_ips' => $blocked, 'count' => count($blocked)], "IPs bloqueados");
        }

        // ── Full dashboard overview ──

        // 1. Current rate limit configuration
        $config = getRateLimitConfig($db);

        // 2. Live stats from Redis
        $liveStats = getLiveStats($redis);

        // 3. Currently blocked IPs
        $blockedIps = getBlockedIps($redis);

        // 4. Top 10 IPs by request count (last hour)
        $topIps = getTopIps($redis);

        // 5. Endpoints with most 429 responses (from log table if available)
        $top429 = getTop429Endpoints($db);

        // 6. Try to get stats from sb-rate-limiter microservice (port 8679)
        $microserviceStats = getMicroserviceStats();

        response(true, [
            'config' => $config,
            'live_stats' => $liveStats,
            'blocked_ips' => $blockedIps,
            'blocked_count' => count($blockedIps),
            'top_ips' => $topIps,
            'top_429_endpoints' => $top429,
            'microservice' => $microserviceStats,
            'redis_available' => $redis->isAvailable(),
        ], "Rate limiting dashboard");
    }

    // =================== POST ===================
    if ($method === 'POST') {

        // ── Unblock an IP ──
        if ($action === 'unblock') {
            $ip = trim($_GET['ip'] ?? '');
            if (!$ip) {
                $input = getInput();
                $ip = trim($input['ip'] ?? '');
            }
            if (!$ip) {
                response(false, null, "IP obrigatorio", 400);
            }
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                response(false, null, "IP invalido", 400);
            }

            $removed = unblockIp($redis, $ip);
            om_audit()->log('rate_limit_unblock', null, null, null, ['ip' => $ip], $admin_id);

            response(true, ['ip' => $ip, 'removed_keys' => $removed], $removed > 0 ? "IP desbloqueado" : "IP nao estava bloqueado");
        }

        // ── Update rate limit config ──
        if ($action === 'update') {
            $input = getInput();
            $endpoint = trim($input['endpoint'] ?? '');
            $requestsPerMinute = isset($input['requests_per_minute']) ? (int)$input['requests_per_minute'] : null;
            $burstLimit = isset($input['burst_limit']) ? (int)$input['burst_limit'] : null;

            if (!$endpoint) {
                response(false, null, "endpoint obrigatorio", 400);
            }

            // Validate endpoint pattern
            $allowedEndpoints = ['login', 'checkout', 'api', 'public', 'webhook', 'admin', 'partner'];
            if (!in_array($endpoint, $allowedEndpoints, true)) {
                response(false, null, "Endpoint invalido. Use: " . implode(', ', $allowedEndpoints), 400);
            }

            if ($requestsPerMinute !== null && ($requestsPerMinute < 1 || $requestsPerMinute > 10000)) {
                response(false, null, "requests_per_minute deve estar entre 1 e 10000", 400);
            }
            if ($burstLimit !== null && ($burstLimit < 1 || $burstLimit > 10000)) {
                response(false, null, "burst_limit deve estar entre 1 e 10000", 400);
            }

            // Store in om_config
            $configKey = 'rate_limit_' . $endpoint;
            $configValue = json_encode([
                'requests_per_minute' => $requestsPerMinute ?? 60,
                'burst_limit' => $burstLimit ?? ($requestsPerMinute ?? 60) * 2,
                'updated_at' => date('c'),
                'updated_by' => $admin_id,
            ]);

            $stmt = $db->prepare("SELECT id FROM om_config WHERE chave = ?");
            $stmt->execute([$configKey]);
            if ($stmt->fetch()) {
                $stmt = $db->prepare("UPDATE om_config SET valor = ?, updated_by = ?, updated_at = NOW() WHERE chave = ?");
                $stmt->execute([$configValue, $admin_id, $configKey]);
            } else {
                $stmt = $db->prepare("INSERT INTO om_config (chave, valor, updated_by, updated_at) VALUES (?, ?, ?, NOW())");
                $stmt->execute([$configKey, $configValue, $admin_id]);
            }

            // Also push to microservice if available
            pushConfigToMicroservice($endpoint, $requestsPerMinute ?? 60, $burstLimit ?? ($requestsPerMinute ?? 60) * 2);

            om_audit()->log('rate_limit_updated', 'om_config', null, null, [
                'endpoint' => $endpoint,
                'requests_per_minute' => $requestsPerMinute,
                'burst_limit' => $burstLimit,
            ], $admin_id);

            response(true, [
                'endpoint' => $endpoint,
                'requests_per_minute' => $requestsPerMinute ?? 60,
                'burst_limit' => $burstLimit ?? ($requestsPerMinute ?? 60) * 2,
            ], "Rate limit atualizado");
        }

        response(false, null, "action obrigatoria (unblock ou update)", 400);
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[admin/rate-limits] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}

// ══════════════════════════════════════════════════════════════
// Helper functions
// ══════════════════════════════════════════════════════════════

/**
 * Get current rate limit configuration from om_config + defaults.
 */
function getRateLimitConfig(PDO $db): array {
    $defaults = [
        'login'    => ['requests_per_minute' => 5,   'burst_limit' => 10,  'window_seconds' => 60],
        'checkout' => ['requests_per_minute' => 10,  'burst_limit' => 20,  'window_seconds' => 60],
        'api'      => ['requests_per_minute' => 120, 'burst_limit' => 240, 'window_seconds' => 60],
        'public'   => ['requests_per_minute' => 30,  'burst_limit' => 60,  'window_seconds' => 60],
        'webhook'  => ['requests_per_minute' => 60,  'burst_limit' => 120, 'window_seconds' => 60],
        'admin'    => ['requests_per_minute' => 120, 'burst_limit' => 240, 'window_seconds' => 60],
        'partner'  => ['requests_per_minute' => 60,  'burst_limit' => 120, 'window_seconds' => 60],
    ];

    // Load overrides from om_config
    $stmt = $db->prepare("SELECT chave, valor FROM om_config WHERE chave LIKE 'rate_limit_%'");
    $stmt->execute();
    $overrides = $stmt->fetchAll();

    foreach ($overrides as $row) {
        $endpoint = str_replace('rate_limit_', '', $row['chave']);
        $data = json_decode($row['valor'], true);
        if ($data && isset($defaults[$endpoint])) {
            $defaults[$endpoint]['requests_per_minute'] = (int)($data['requests_per_minute'] ?? $defaults[$endpoint]['requests_per_minute']);
            $defaults[$endpoint]['burst_limit'] = (int)($data['burst_limit'] ?? $defaults[$endpoint]['burst_limit']);
            $defaults[$endpoint]['updated_at'] = $data['updated_at'] ?? null;
            $defaults[$endpoint]['updated_by'] = $data['updated_by'] ?? null;
        }
    }

    return $defaults;
}

/**
 * Get live rate limit stats from Redis (DB 2).
 */
function getLiveStats(RedisService $redis): array {
    if (!$redis->isAvailable()) {
        return ['error' => 'Redis nao disponivel', 'total_keys' => 0];
    }

    try {
        $raw = $redis->getRawConnection(2);
        if (!$raw) {
            return ['error' => 'Nao foi possivel conectar ao Redis DB 2', 'total_keys' => 0];
        }

        // Count rate limit keys
        $keys = $raw->keys('rl:*');
        $totalKeys = count($keys);

        // Sample some keys to show activity
        $activeLimits = [];
        $count = 0;
        foreach ($keys as $key) {
            if ($count >= 20) break;
            $val = $raw->get($key);
            $ttl = $raw->ttl($key);
            if ($val !== false) {
                $activeLimits[] = [
                    'key' => $key,
                    'requests' => (int)$val,
                    'ttl_seconds' => $ttl,
                ];
            }
            $count++;
        }

        // Sort by requests descending
        usort($activeLimits, function($a, $b) {
            return $b['requests'] - $a['requests'];
        });

        $raw->close();

        return [
            'total_active_keys' => $totalKeys,
            'sample_limits' => $activeLimits,
        ];
    } catch (\RedisException $e) {
        error_log("[rate-limits] Redis stats error: " . $e->getMessage());
        return ['error' => 'Erro ao consultar Redis', 'total_keys' => 0];
    }
}

/**
 * Get currently blocked IPs (those with rate limit counts exceeding the limit).
 */
function getBlockedIps(RedisService $redis): array {
    if (!$redis->isAvailable()) {
        return [];
    }

    try {
        $raw = $redis->getRawConnection(2);
        if (!$raw) return [];

        $blocked = [];
        $keys = $raw->keys('rl:*');

        // Thresholds per prefix
        $thresholds = [
            'login' => 5,
            'checkout' => 10,
            'api' => 120,
            'pub' => 30,
        ];

        foreach ($keys as $key) {
            $val = (int)$raw->get($key);
            $ttl = $raw->ttl($key);

            // Determine threshold from key pattern
            $exceeded = false;
            $category = 'unknown';
            foreach ($thresholds as $prefix => $threshold) {
                // Keys are hashed, but we check the original key prefix stored
                // Since keys are rl:<sha256>, we check the count vs lowest threshold
                if ($val > $threshold) {
                    $exceeded = true;
                    $category = $prefix;
                    break;
                }
            }

            // Only show truly blocked (count way over any threshold)
            if ($val > 120) {
                $blocked[] = [
                    'key' => $key,
                    'request_count' => $val,
                    'ttl_seconds' => max(0, $ttl),
                    'unblock_at' => $ttl > 0 ? date('c', time() + $ttl) : null,
                ];
            }
        }

        // Sort by request count descending
        usort($blocked, function($a, $b) {
            return $b['request_count'] - $a['request_count'];
        });

        $raw->close();
        return array_slice($blocked, 0, 50);
    } catch (\RedisException $e) {
        error_log("[rate-limits] Blocked IPs error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get top 10 IPs by request count in the last hour.
 */
function getTopIps(RedisService $redis): array {
    if (!$redis->isAvailable()) {
        return [];
    }

    try {
        $raw = $redis->getRawConnection(2);
        if (!$raw) return [];

        $ipCounts = [];
        $keys = $raw->keys('rl:*');

        foreach ($keys as $key) {
            $val = (int)$raw->get($key);
            $ipCounts[] = [
                'key' => $key,
                'requests' => $val,
                'ttl_seconds' => max(0, $raw->ttl($key)),
            ];
        }

        // Sort by requests descending
        usort($ipCounts, function($a, $b) {
            return $b['requests'] - $a['requests'];
        });

        $raw->close();
        return array_slice($ipCounts, 0, 10);
    } catch (\RedisException $e) {
        error_log("[rate-limits] Top IPs error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get endpoints with most 429 responses from rate limit log table.
 */
function getTop429Endpoints(PDO $db): array {
    try {
        // Check if sb_rate_limit_log table exists
        $stmt = $db->query("
            SELECT EXISTS (
                SELECT FROM information_schema.tables
                WHERE table_name = 'sb_rate_limit_log'
            )
        ");
        $exists = $stmt->fetchColumn();

        if (!$exists) {
            // Create the table for future logging
            $db->exec("
                CREATE TABLE IF NOT EXISTS sb_rate_limit_log (
                    id SERIAL PRIMARY KEY,
                    ip VARCHAR(45) NOT NULL,
                    endpoint VARCHAR(255),
                    blocked_at TIMESTAMP NOT NULL DEFAULT NOW(),
                    category VARCHAR(50)
                )
            ");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_sb_rl_log_blocked ON sb_rate_limit_log(blocked_at DESC)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_sb_rl_log_ip ON sb_rate_limit_log(ip)");
            return [];
        }

        $stmt = $db->query("
            SELECT endpoint, COUNT(*) as block_count, MAX(blocked_at) as last_blocked
            FROM sb_rate_limit_log
            WHERE blocked_at > NOW() - INTERVAL '1 hour'
            GROUP BY endpoint
            ORDER BY block_count DESC
            LIMIT 10
        ");
        return $stmt->fetchAll();
    } catch (Exception $e) {
        // Table might not exist yet
        return [];
    }
}

/**
 * Remove all rate limit keys for an IP from Redis.
 */
function unblockIp(RedisService $redis, string $ip): int {
    if (!$redis->isAvailable()) {
        return 0;
    }

    try {
        $raw = $redis->getRawConnection(2);
        if (!$raw) return 0;

        $removed = 0;

        // Remove known key patterns for this IP
        $prefixes = ['login', 'checkout', 'pub', 'api'];
        foreach ($prefixes as $prefix) {
            $key = 'rl:' . hash('sha256', "{$prefix}:{$ip}");
            if ($raw->del($key)) {
                $removed++;
            }
        }

        $raw->close();
        return $removed;
    } catch (\RedisException $e) {
        error_log("[rate-limits] Unblock error: " . $e->getMessage());
        return 0;
    }
}

/**
 * Get stats from sb-rate-limiter Go microservice (port 8679).
 */
function getMicroserviceStats(): ?array {
    try {
        $ch = curl_init('http://127.0.0.1:8679/stats');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 2,
            CURLOPT_CONNECTTIMEOUT => 1,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            return $data ?: null;
        }
        return null;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Push rate limit config update to Go microservice.
 */
function pushConfigToMicroservice(string $endpoint, int $requestsPerMinute, int $burstLimit): bool {
    try {
        $payload = json_encode([
            'endpoint' => $endpoint,
            'requests_per_minute' => $requestsPerMinute,
            'burst_limit' => $burstLimit,
        ]);

        $ch = curl_init('http://127.0.0.1:8679/config');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 2,
            CURLOPT_CONNECTTIMEOUT => 1,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode >= 200 && $httpCode < 300;
    } catch (Exception $e) {
        error_log("[rate-limits] Microservice config push failed: " . $e->getMessage());
        return false;
    }
}
