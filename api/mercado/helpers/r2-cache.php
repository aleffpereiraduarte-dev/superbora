<?php
/**
 * R2 JSON Cache - Global edge-cached JSON storage on Cloudflare R2
 *
 * Uses the same SigV4 helper from r2-storage.php. Stores JSON blobs at
 * superbora/cache/<key>.json with a sidecar superbora/cache/<key>.meta
 * containing the TTL/created_at metadata.
 *
 * Latency: ~30ms US/EU/Asia (R2 has global POPs) vs ~150-300ms hitting our PHP in SP
 *
 * Usage:
 *   require_once 'helpers/r2-cache.php';
 *
 *   // Try cache first
 *   $cached = r2CacheGet('store:123');
 *   if ($cached !== null) {
 *       header('X-Cache: HIT-R2');
 *       echo $cached; exit;
 *   }
 *
 *   // Cache miss — compute fresh
 *   $data = computeStorePage(123);
 *   r2CachePut('store:123', json_encode($data), 300); // 5 min
 *
 * Invalidation:
 *   r2CacheInvalidate('store:123');                  // single key
 *   r2CacheInvalidatePrefix('store:');                // batch (slow)
 *
 * Local in-memory cache also kept per-request for repeated lookups.
 */
require_once __DIR__ . '/r2-storage.php';

if (!function_exists('r2CacheGet')) {

    function r2CacheKey(string $key): string {
        // Sanitize: only [a-z0-9._-/] allowed (NO colons — they conflict with URL encoding)
        $clean = preg_replace('/[^a-z0-9._\/-]/i', '_', $key);
        if (strlen($clean) > 100) $clean = substr($clean, 0, 80) . '_' . substr(md5($key), 0, 16);
        return 'superbora/cache/' . $clean . '.json';
    }

    function r2CacheMetaKey(string $key): string {
        return str_replace('.json', '.meta', r2CacheKey($key));
    }

    function r2CacheGet(string $key): ?string {
        // Process-local memo
        static $memo = [];
        if (isset($memo[$key])) return $memo[$key];

        if (!r2IsEnabled()) return null;

        $cfg = r2Config();
        $objectKey = r2CacheKey($key);
        $url = "https://{$cfg['account_id']}.r2.cloudflarestorage.com/{$cfg['bucket']}/" . $objectKey;

        // SigV4 GET
        $r = r2SigV4Request('GET', $url, '', $cfg);
        if (!$r['ok']) {
            $memo[$key] = null;
            return null;
        }

        // Check TTL via meta sidecar (if present)
        $metaUrl = "https://{$cfg['account_id']}.r2.cloudflarestorage.com/{$cfg['bucket']}/" . r2CacheMetaKey($key);
        $metaR = r2SigV4Request('GET', $metaUrl, '', $cfg);
        if ($metaR['ok']) {
            $meta = json_decode($metaR['body'], true);
            $expiresAt = $meta['expires_at'] ?? 0;
            if ($expiresAt && time() > $expiresAt) {
                $memo[$key] = null;
                return null; // expired
            }
        }

        $memo[$key] = $r['body'];
        return $r['body'];
    }

    function r2CachePut(string $key, string $jsonBody, int $ttlSeconds = 300): bool {
        if (!r2IsEnabled()) return false;
        $cfg = r2Config();

        // Write the body
        $put = r2PutObject(r2CacheKey($key), $jsonBody, 'application/json', $cfg);
        if (!$put) return false;

        // Write the meta sidecar
        $meta = json_encode([
            'created_at' => time(),
            'expires_at' => time() + $ttlSeconds,
            'ttl' => $ttlSeconds,
            'size' => strlen($jsonBody),
        ]);
        r2PutObject(r2CacheMetaKey($key), $meta, 'application/json', $cfg);

        return true;
    }

    function r2CacheInvalidate(string $key): bool {
        if (!r2IsEnabled()) return false;
        $cfg = r2Config();

        $url = "https://{$cfg['account_id']}.r2.cloudflarestorage.com/{$cfg['bucket']}/" . r2CacheKey($key);
        $r = r2SigV4Request('DELETE', $url, '', $cfg);
        // Best-effort delete the meta as well
        $metaUrl = "https://{$cfg['account_id']}.r2.cloudflarestorage.com/{$cfg['bucket']}/" . r2CacheMetaKey($key);
        r2SigV4Request('DELETE', $metaUrl, '', $cfg);

        return $r['ok'];
    }

    /**
     * Stub: prefix invalidation. R2/S3 has no native "delete by prefix" — list+delete.
     * This is intentionally a no-op cheap version. Real impl would use rclone purge.
     */
    function r2CacheInvalidatePrefix(string $prefix): int {
        // For now, just return 0. Real implementation would call rclone purge.
        // Use specific keys for invalidation instead of prefix when possible.
        return 0;
    }

    /**
     * Wrap a callable in cache. If the cached value exists, return it. Otherwise
     * call $producer, cache the result, and return it.
     *
     * @param callable $producer Callable that returns the JSON string (or array - will be encoded)
     */
    function r2CacheRemember(string $key, int $ttl, callable $producer): string {
        $cached = r2CacheGet($key);
        if ($cached !== null) {
            if (PHP_SAPI !== 'cli' && !headers_sent()) {
                header('X-Cache: HIT-R2');
                header('X-Cache-Key: ' . $key);
            }
            return $cached;
        }

        $fresh = $producer();
        if (is_array($fresh)) $fresh = json_encode($fresh, JSON_UNESCAPED_UNICODE);

        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            header('X-Cache: MISS');
            header('X-Cache-Key: ' . $key);
        }

        // Fire-and-forget cache write to avoid blocking the response
        r2CachePut($key, $fresh, $ttl);
        return $fresh;
    }

    /**
     * Invalidate ALL cached variants for a given partner_id.
     * Best-effort, never throws.
     */
    function r2CacheInvalidatePartner(int $partnerId): void {
        if (!r2IsEnabled() || $partnerId < 1) return;
        try {
            $variants = [
                // produtos/listar most common combos
                "listar/p{$partnerId}_c0_l50_odef",
                "listar/p{$partnerId}_c0_l20_odef",
                "listar/p{$partnerId}_c0_l50_opreco_asc",
                "listar/p{$partnerId}_c0_l50_opreco_desc",
                // store/* endpoints
                "featured/p{$partnerId}",
                "popular/p{$partnerId}_l10",
                "popular/p{$partnerId}_l20",
                "banners/p{$partnerId}",
            ];
            foreach ($variants as $k) r2CacheInvalidate($k);
        } catch (Exception $e) {
            error_log("[r2-cache] invalidatePartner failed: " . $e->getMessage());
        }
    }

    /**
     * Invalidate global feeds (hits, descontos, home) used by all customers.
     * Best-effort, never throws.
     */
    function r2CacheInvalidateGlobal(): void {
        if (!r2IsEnabled()) return;
        try {
            $variants = [
                "hits/mp20_l10_o0_call",
                "hits/mp20_l20_o0_call",
                "hits/mp50_l20_o0_call",
                "descontos/m10_l20_o0_sdiscount_rall",
                "descontos/m20_l20_o0_sdiscount_rall",
            ];
            foreach ($variants as $k) r2CacheInvalidate($k);
        } catch (Exception $e) {
            error_log("[r2-cache] invalidateGlobal failed: " . $e->getMessage());
        }
    }

    /**
     * SigV4 helper for arbitrary methods (GET, DELETE, PUT).
     * Returns ['ok'=>bool, 'code'=>int, 'body'=>string]
     */
    function r2SigV4Request(string $method, string $url, string $body, array $cfg): array {
        $parts = parse_url($url);
        $host = $parts['host'];
        $uri = $parts['path'];

        $now = gmdate('Ymd\THis\Z');
        $dateOnly = substr($now, 0, 8);
        $payloadHash = hash('sha256', $body);

        $headers = [
            'host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $now,
        ];
        if ($body !== '') {
            $headers['content-type'] = 'application/octet-stream';
        }
        ksort($headers);

        $canonicalHeaders = '';
        $signedHeadersList = [];
        foreach ($headers as $k => $v) {
            $canonicalHeaders .= $k . ':' . trim($v) . "\n";
            $signedHeadersList[] = $k;
        }
        $signedHeaders = implode(';', $signedHeadersList);

        $canonicalRequest = $method . "\n" . $uri . "\n\n" . $canonicalHeaders . "\n" . $signedHeaders . "\n" . $payloadHash;

        $credentialScope = $dateOnly . '/auto/s3/aws4_request';
        $stringToSign = "AWS4-HMAC-SHA256\n{$now}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

        $kDate = hash_hmac('sha256', $dateOnly, 'AWS4' . $cfg['secret_key'], true);
        $kRegion = hash_hmac('sha256', 'auto', $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authHeader = "AWS4-HMAC-SHA256 Credential={$cfg['access_key']}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

        $curlHeaders = [
            'Host: ' . $host,
            'x-amz-content-sha256: ' . $payloadHash,
            'x-amz-date: ' . $now,
            'Authorization: ' . $authHeader,
        ];
        if ($body !== '') {
            $curlHeaders[] = 'Content-Type: application/octet-stream';
            $curlHeaders[] = 'Content-Length: ' . strlen($body);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        if ($body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'ok' => $code >= 200 && $code < 300,
            'code' => $code,
            'body' => $resp ?: '',
        ];
    }
}
