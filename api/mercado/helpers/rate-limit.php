<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * Rate Limiting Helper
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Lightweight rate limiter using PostgreSQL.
 * Auto-creates the om_rate_limits table on first use.
 *
 * Usage:
 *   require_once __DIR__ . '/../helpers/rate-limit.php';
 *   $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'];
 *   if (!checkRateLimit("endpoint_{$ip}", 10, 15)) {
 *       response(false, null, "Muitas requisicoes. Tente novamente em 15 minutos.", 429);
 *   }
 */

/**
 * Check if a request should be allowed under rate limits.
 *
 * @param PDO    $db           Database connection
 * @param string $key          Unique key (e.g. "login_192.168.1.1" or "ai_partner_42")
 * @param int    $maxAttempts  Maximum attempts allowed in the window
 * @param int    $windowMinutes  Time window in minutes
 * @return bool  true = allowed, false = rate limited
 */
if (!function_exists('checkRateLimit')) {
function checkRateLimit(PDO $db, string $key, int $maxAttempts = 10, int $windowMinutes = 15): bool {
    try {
        // Table om_rate_limits created via migration

        // Probabilistic cleanup: 1% chance per request to avoid overhead on every call
        if (random_int(1, 100) === 1) {
            $db->exec("DELETE FROM om_rate_limits WHERE created_at < NOW() - INTERVAL '1 hour'");
        }

        // Count attempts in current window
        $windowMinutes = (int)$windowMinutes;
        $cutoff = date('Y-m-d H:i:s', time() - $windowMinutes * 60);
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM om_rate_limits WHERE rate_key = ? AND created_at > ?"
        );
        $stmt->execute([$key, $cutoff]);
        $count = (int)$stmt->fetchColumn();

        if ($count >= $maxAttempts) {
            return false; // Rate limited
        }

        // Record this attempt
        $stmt = $db->prepare("INSERT INTO om_rate_limits (rate_key, created_at) VALUES (?, NOW())");
        $stmt->execute([$key]);

        return true; // Allowed
    } catch (Exception $e) {
        // If rate limiting fails for any reason, allow the request through
        // (fail-open: don't block legitimate users due to rate-limit infrastructure issues)
        error_log("[rate-limit] Error: " . $e->getMessage());
        return true;
    }
} // end if !function_exists
}

/**
 * Get the client IP address, handling Cloudflare and proxy headers.
 *
 * @return string
 */
function getRateLimitIP(): string {
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'];
    // Handle comma-separated IPs (multiple proxies)
    if (strpos($ip, ',') !== false) {
        $ip = trim(explode(',', $ip)[0]);
    }
    return $ip;
}
