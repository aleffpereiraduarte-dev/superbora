<?php
/**
 * Feature Flags — simple server-side flag resolution with %-rollout.
 *
 * Table: om_feature_flags
 *   - key           TEXT PK
 *   - enabled       BOOLEAN — master switch
 *   - rollout_pct   INT 0..100 — % of users who see the flag (deterministic by customer_id hash)
 *   - allowed_cids  INT[] — explicit allowlist (always see flag, ignores pct)
 *   - description   TEXT
 *   - created_at    TIMESTAMPTZ
 *   - updated_at    TIMESTAMPTZ
 *
 * Usage:
 *   require_once 'config/feature-flags.php';
 *   if (featureFlag('new_checkout', $customerId)) { ... }
 *
 * Mobile reads /customer/feature-flags.php to get the flag set.
 * Resolution is fully deterministic: same cid + same pct always gets same answer.
 */

if (!function_exists('featureFlag')) {

    function _featureFlagEnsureTable(PDO $db): void {
        static $ensured = false;
        if ($ensured) return;
        try {
            // Uses the canonical existing table schema
            $db->exec("CREATE TABLE IF NOT EXISTS om_feature_flags (
                id SERIAL PRIMARY KEY,
                flag_key TEXT UNIQUE NOT NULL,
                description TEXT,
                enabled BOOLEAN NOT NULL DEFAULT FALSE,
                rollout_percent INT NOT NULL DEFAULT 0 CHECK (rollout_percent BETWEEN 0 AND 100),
                user_segment JSONB DEFAULT '{}',
                metadata JSONB DEFAULT '{}',
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )");
            $ensured = true;
        } catch (Exception $e) { /* ignore */ }
    }

    /**
     * Resolve a feature flag for a given customer.
     * Returns: true if the flag is enabled for this customer.
     */
    function featureFlag(string $key, int $customerId = 0): bool {
        global $_db;
        $db = $_db ?? (function_exists('getDB') ? getDB() : null);
        if (!$db) return false;

        _featureFlagEnsureTable($db);

        // In-request cache to avoid re-query when same flag checked multiple times
        static $cache = [];
        $ckey = "{$key}:{$customerId}";
        if (isset($cache[$ckey])) return $cache[$ckey];

        try {
            $stmt = $db->prepare("SELECT enabled, rollout_percent, user_segment FROM om_feature_flags WHERE flag_key = ?");
            $stmt->execute([$key]);
            $flag = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return $cache[$ckey] = false;
        }

        if (!$flag || !$flag['enabled']) {
            return $cache[$ckey] = false;
        }

        // Explicit allowlist in user_segment.allowed_cids: [123, 456]
        $segment = is_string($flag['user_segment']) ? json_decode($flag['user_segment'], true) : ($flag['user_segment'] ?? []);
        $allowed = $segment['allowed_cids'] ?? [];
        if ($customerId > 0 && is_array($allowed) && in_array($customerId, $allowed, true)) {
            return $cache[$ckey] = true;
        }

        $pct = max(0, min(100, (int)$flag['rollout_percent']));
        if ($pct >= 100) return $cache[$ckey] = true;
        if ($pct <= 0)   return $cache[$ckey] = false;

        // Deterministic bucket: hash(flag_key + customer_id) % 100 < pct
        // Anonymous users (cid=0) use a random bucket (not sticky, but fair)
        if ($customerId <= 0) {
            return $cache[$ckey] = (mt_rand(0, 99) < $pct);
        }
        $hash = hexdec(substr(md5($key . ':' . $customerId), 0, 8));
        $bucket = $hash % 100;
        return $cache[$ckey] = ($bucket < $pct);
    }

    /**
     * Return all flags enabled for this customer (for mobile bootstrap).
     */
    function featureFlagAll(int $customerId = 0): array {
        global $_db;
        $db = $_db ?? (function_exists('getDB') ? getDB() : null);
        if (!$db) return [];
        _featureFlagEnsureTable($db);

        try {
            $stmt = $db->query("SELECT flag_key FROM om_feature_flags WHERE enabled = TRUE");
            $keys = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            return [];
        }

        $out = [];
        foreach ($keys as $k) {
            $out[$k] = featureFlag($k, $customerId);
        }
        return $out;
    }
}
