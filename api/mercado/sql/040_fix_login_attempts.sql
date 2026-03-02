-- ============================================================================
-- Migration 040: Fix om_login_attempts schema
-- ============================================================================
-- The table was originally created with columns for employee login tracking:
--   id, employee_number, ip_address, user_agent, success, failure_reason, created_at
--
-- All login endpoints (partner, admin, shopper, team, totp) require:
--   id, ip_address, email, user_type, attempted_at
--
-- This migration adds the missing columns without dropping existing ones,
-- so both old and new code continue to work.
-- ============================================================================

-- Ensure table exists (in case it was never created)
CREATE TABLE IF NOT EXISTS om_login_attempts (
    id SERIAL PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    email VARCHAR(255) DEFAULT NULL,
    user_type VARCHAR(30) DEFAULT 'unknown',
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Add columns that may be missing from the original schema
ALTER TABLE om_login_attempts ADD COLUMN IF NOT EXISTS email VARCHAR(255) DEFAULT NULL;
ALTER TABLE om_login_attempts ADD COLUMN IF NOT EXISTS user_type VARCHAR(30) DEFAULT 'unknown';
ALTER TABLE om_login_attempts ADD COLUMN IF NOT EXISTS attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP;

-- Backfill attempted_at from created_at if the old column exists and new column has NULLs
DO $$ BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_name = 'om_login_attempts' AND column_name = 'created_at'
    ) THEN
        UPDATE om_login_attempts SET attempted_at = created_at WHERE attempted_at IS NULL;
    END IF;
END $$;

-- Indexes for rate limiting queries
CREATE INDEX IF NOT EXISTS idx_om_login_attempts_ip_time ON om_login_attempts (ip_address, attempted_at);
CREATE INDEX IF NOT EXISTS idx_om_login_attempts_email_time ON om_login_attempts (email, attempted_at);
CREATE INDEX IF NOT EXISTS idx_om_login_attempts_ip_type_time ON om_login_attempts (ip_address, user_type, attempted_at);

-- Cleanup: remove entries older than 24 hours (rate limit window is 15 min,
-- but keep 24h for forensic analysis)
DELETE FROM om_login_attempts WHERE attempted_at < NOW() - INTERVAL '24 hours';
