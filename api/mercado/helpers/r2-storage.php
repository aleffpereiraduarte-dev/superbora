<?php
/**
 * R2 Storage Helper - upload to Cloudflare R2 (S3-compatible).
 *
 * Uses AWS Signature V4 with PHP curl. No SDK dependency, works out of the box.
 *
 * Configuration (.env):
 *   STORAGE_DRIVER     = r2 | local       (default: local)
 *   R2_ACCOUNT_ID      = ...
 *   R2_ACCESS_KEY      = ...
 *   R2_SECRET_KEY      = ...
 *   R2_BUCKET          = chatyy-media
 *   R2_PUBLIC_URL      = https://cdn.superbora.com.br      (custom domain after configuring)
 *
 * Usage:
 *   require_once 'helpers/r2-storage.php';
 *   $url = r2Upload('/tmp/foo.jpg', 'superbora/uploads/products/123.jpg', 'image/jpeg');
 *   if ($url) { /* save $url to DB *\/ }
 */

if (!function_exists('r2Config')) {

    function r2Config(): ?array {
        static $cfg = null;
        if ($cfg !== null) return $cfg ?: null;

        $cfg = [
            'driver'      => $_ENV['STORAGE_DRIVER'] ?? getenv('STORAGE_DRIVER') ?: 'local',
            'account_id'  => $_ENV['R2_ACCOUNT_ID'] ?? getenv('R2_ACCOUNT_ID') ?: '',
            'access_key'  => $_ENV['R2_ACCESS_KEY'] ?? getenv('R2_ACCESS_KEY') ?: '',
            'secret_key'  => $_ENV['R2_SECRET_KEY'] ?? getenv('R2_SECRET_KEY') ?: '',
            'bucket'      => $_ENV['R2_BUCKET'] ?? getenv('R2_BUCKET') ?: '',
            'public_url'  => rtrim($_ENV['R2_PUBLIC_URL'] ?? getenv('R2_PUBLIC_URL') ?: '', '/'),
        ];

        if ($cfg['driver'] !== 'r2') {
            return $cfg; // local mode is fine — no R2 creds needed
        }
        if (!$cfg['account_id'] || !$cfg['access_key'] || !$cfg['secret_key'] || !$cfg['bucket']) {
            error_log('[r2-storage] STORAGE_DRIVER=r2 but R2_* env vars missing');
            $cfg = false;
            return null;
        }
        return $cfg;
    }

    function r2IsEnabled(): bool {
        $cfg = r2Config();
        return $cfg && $cfg['driver'] === 'r2';
    }

    /**
     * Upload a local file to R2. Returns the public URL on success, null on failure.
     *
     * @param string $localPath  Path to the file on disk
     * @param string $key        Object key inside the bucket (e.g. "superbora/uploads/products/123.jpg")
     * @param string $mimeType   Content-Type
     */
    function r2Upload(string $localPath, string $key, string $mimeType = 'application/octet-stream'): ?string {
        if (!is_readable($localPath)) {
            error_log('[r2-storage] file not readable: ' . $localPath);
            return null;
        }
        $cfg = r2Config();
        if (!$cfg || $cfg['driver'] !== 'r2') {
            return null;
        }

        $body = file_get_contents($localPath);
        if ($body === false) return null;

        return r2PutObject($key, $body, $mimeType, $cfg);
    }

    /**
     * Upload raw bytes to R2.
     */
    function r2UploadBytes(string $key, string $body, string $mimeType = 'application/octet-stream'): ?string {
        $cfg = r2Config();
        if (!$cfg || $cfg['driver'] !== 'r2') {
            return null;
        }
        return r2PutObject($key, $body, $mimeType, $cfg);
    }

    /**
     * Returns the public URL for an existing object.
     */
    function r2PublicUrl(string $key): string {
        $cfg = r2Config();
        if (!$cfg) return '';
        if ($cfg['public_url']) {
            return $cfg['public_url'] . '/' . ltrim($key, '/');
        }
        // Fallback to the R2.dev subdomain (works only if the bucket is set to public)
        return "https://pub-{$cfg['account_id']}.r2.dev/" . ltrim($key, '/');
    }

    /**
     * Internal: AWS SigV4 PUT to R2.
     */
    function r2PutObject(string $key, string $body, string $mimeType, array $cfg): ?string {
        $host    = "{$cfg['account_id']}.r2.cloudflarestorage.com";
        $service = 's3';
        $region  = 'auto';
        $method  = 'PUT';
        $key     = ltrim($key, '/');
        $uri     = '/' . $cfg['bucket'] . '/' . str_replace('%2F', '/', rawurlencode($key));

        $now      = gmdate('Ymd\THis\Z');
        $dateOnly = substr($now, 0, 8);

        $payloadHash = hash('sha256', $body);

        // Canonical headers (must be sorted alphabetically by lower-case header name)
        $headers = [
            'content-type'         => $mimeType,
            'host'                 => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date'           => $now,
        ];
        ksort($headers);
        $canonicalHeaders = '';
        $signedHeaders = [];
        foreach ($headers as $k => $v) {
            $canonicalHeaders .= $k . ':' . trim($v) . "\n";
            $signedHeaders[] = $k;
        }
        $signedHeadersStr = implode(';', $signedHeaders);

        $canonicalRequest = $method . "\n" .
                            $uri . "\n" .
                            "" . "\n" . // empty query string
                            $canonicalHeaders . "\n" .
                            $signedHeadersStr . "\n" .
                            $payloadHash;

        $credentialScope = $dateOnly . '/' . $region . '/' . $service . '/aws4_request';
        $stringToSign = "AWS4-HMAC-SHA256\n" .
                        $now . "\n" .
                        $credentialScope . "\n" .
                        hash('sha256', $canonicalRequest);

        // Derive signing key
        $kDate    = hash_hmac('sha256', $dateOnly, 'AWS4' . $cfg['secret_key'], true);
        $kRegion  = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authHeader = "AWS4-HMAC-SHA256 " .
                      "Credential={$cfg['access_key']}/{$credentialScope}, " .
                      "SignedHeaders={$signedHeadersStr}, " .
                      "Signature={$signature}";

        $url = 'https://' . $host . $uri;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Host: ' . $host,
                'Content-Type: ' . $mimeType,
                'Content-Length: ' . strlen($body),
                'x-amz-content-sha256: ' . $payloadHash,
                'x-amz-date: ' . $now,
                'Authorization: ' . $authHeader,
            ],
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($code !== 200 && $code !== 201 && $code !== 204) {
            error_log("[r2-storage] PUT failed code={$code} err={$err} resp=" . substr((string)$resp, 0, 300));
            return null;
        }

        return r2PublicUrl($key);
    }

    /**
     * Build a sane object key for a file.
     * Example: r2KeyForUpload('products', '123.jpg')
     *   -> "superbora/uploads/products/123.jpg"
     */
    function r2KeyForUpload(string $folder, string $filename): string {
        $folder = trim($folder, '/');
        $filename = ltrim($filename, '/');
        return "superbora/uploads/{$folder}/{$filename}";
    }
}
