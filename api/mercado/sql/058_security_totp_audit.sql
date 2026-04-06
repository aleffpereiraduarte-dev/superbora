-- 058: Security improvements — TOTP 2FA + Admin audit log
-- Run: psql -h 127.0.0.1 -U love1 -d love1 -f 058_security_totp_audit.sql

-- TOTP 2FA column on customers
ALTER TABLE om_customers ADD COLUMN IF NOT EXISTS totp_secret VARCHAR(64) DEFAULT NULL;
ALTER TABLE om_customers ADD COLUMN IF NOT EXISTS totp_enabled BOOLEAN DEFAULT FALSE;
ALTER TABLE om_customers ADD COLUMN IF NOT EXISTS totp_enabled_at TIMESTAMP DEFAULT NULL;

-- Admin audit log
CREATE TABLE IF NOT EXISTS sb_admin_audit_log (
    id BIGSERIAL PRIMARY KEY,
    admin_id INTEGER NOT NULL,
    action VARCHAR(100) NOT NULL,
    resource_type VARCHAR(100) NOT NULL,
    resource_id VARCHAR(100) DEFAULT NULL,
    details JSONB DEFAULT '{}'::jsonb,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_admin_audit_admin_id ON sb_admin_audit_log(admin_id);
CREATE INDEX IF NOT EXISTS idx_admin_audit_action ON sb_admin_audit_log(action);
CREATE INDEX IF NOT EXISTS idx_admin_audit_resource ON sb_admin_audit_log(resource_type, resource_id);
CREATE INDEX IF NOT EXISTS idx_admin_audit_created ON sb_admin_audit_log(created_at DESC);
