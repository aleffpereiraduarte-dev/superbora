-- ============================================================================
-- 102_auth_tokens_perf.sql
-- Performance indexes for om_auth_tokens — JWT validation hot path
-- ----------------------------------------------------------------------------
-- Context: Every authenticated request hits validateToken() which queries:
--   (1) OmAuth::isTokenValid()         WHERE user_type=? AND user_id=? AND jti=?
--   (2) getCustomerIdFromToken()       WHERE jti=?
--   (3) revokeAllTokens()              WHERE user_type=? AND user_id=?
--   (4) cron/cleanup.php               WHERE expires_at < NOW() (cleanup)
--
-- Before this migration: Seq Scan on every request (no indexes besides PK).
-- With ~1k active tokens today, growth is linear; by 100k this breaks.
--
-- Not using CREATE INDEX CONCURRENTLY because pgbouncer transaction pooling
-- rejects it (must be outside a transaction; CONCURRENTLY also locks pool).
-- Run this manually via direct psql connection (port 5432) if needed, OR
-- with pgbouncer in statement/session mode. Our load is low so a brief
-- AccessExclusiveLock is acceptable.
-- ============================================================================

-- (1) Primary index for validation: jti lookup. Unique because jti is
-- cryptographically random (16 bytes) and used as the canonical token id
-- in both validation paths. UNIQUE also prevents duplicate insertions.
CREATE UNIQUE INDEX IF NOT EXISTS idx_auth_tokens_jti
    ON om_auth_tokens (jti);

-- (2) Composite for (user_type, user_id) lookups — used by revokeAllTokens
-- during change-password, delete-account and admin customer-actions, and
-- also by the 3-column WHERE in OmAuth::isTokenValid (leading columns match).
CREATE INDEX IF NOT EXISTS idx_auth_tokens_user
    ON om_auth_tokens (user_type, user_id);

-- (3) Cleanup cron index — WHERE expires_at < NOW().
-- Partial: only track still-active rows (revoked=0 is the live set).
CREATE INDEX IF NOT EXISTS idx_auth_tokens_expires
    ON om_auth_tokens (expires_at)
    WHERE revoked = 0;

-- Refresh planner stats so the new indexes are used immediately.
ANALYZE om_auth_tokens;
