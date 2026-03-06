-- OneMundo Mail - Core email system tables
-- Run: psql -U postgres -d love1 -f 042_email_system.sql

BEGIN;

-- Email accounts (virtual mailbox users)
CREATE TABLE IF NOT EXISTS om_email_accounts (
    id SERIAL PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    username VARCHAR(64) NOT NULL,
    domain VARCHAR(128) NOT NULL DEFAULT 'onemundo.com.br',
    password_hash VARCHAR(255) NOT NULL,
    display_name VARCHAR(255),
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    phone VARCHAR(20),
    phone_verified BOOLEAN DEFAULT false,
    recovery_email VARCHAR(255),
    birthday DATE,
    gender VARCHAR(10),
    avatar_url TEXT,
    quota_mb INT DEFAULT 1024,
    quota_used_mb INT DEFAULT 0,
    is_active BOOLEAN DEFAULT true,
    last_login TIMESTAMP,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    UNIQUE(username, domain)
);

-- Email sessions (for web cookie auth)
CREATE TABLE IF NOT EXISTS om_email_sessions (
    id SERIAL PRIMARY KEY,
    session_id VARCHAR(128) NOT NULL UNIQUE,
    account_id INT NOT NULL REFERENCES om_email_accounts(id) ON DELETE CASCADE,
    ip_address VARCHAR(45),
    user_agent TEXT,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT NOW()
);

-- Auth tokens (for mobile bearer token auth)
CREATE TABLE IF NOT EXISTS om_email_tokens (
    id SERIAL PRIMARY KEY,
    token_hash VARCHAR(128) NOT NULL UNIQUE,
    account_id INT NOT NULL REFERENCES om_email_accounts(id) ON DELETE CASCADE,
    device_info TEXT,
    revoked BOOLEAN DEFAULT false,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT NOW()
);

-- Contacts (autocomplete, frequency-based)
CREATE TABLE IF NOT EXISTS om_email_contacts (
    id SERIAL PRIMARY KEY,
    account_id INT NOT NULL REFERENCES om_email_accounts(id) ON DELETE CASCADE,
    email VARCHAR(255) NOT NULL,
    display_name VARCHAR(255),
    frequency INT DEFAULT 1,
    last_used TIMESTAMP DEFAULT NOW(),
    created_at TIMESTAMP DEFAULT NOW(),
    UNIQUE(account_id, email)
);

-- User settings (JSONB for flexibility)
CREATE TABLE IF NOT EXISTS om_email_settings (
    account_id INT PRIMARY KEY REFERENCES om_email_accounts(id) ON DELETE CASCADE,
    settings JSONB DEFAULT '{}',
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Indexes
CREATE INDEX IF NOT EXISTS idx_email_accounts_domain ON om_email_accounts(domain);
CREATE INDEX IF NOT EXISTS idx_email_accounts_active ON om_email_accounts(is_active) WHERE is_active = true;
CREATE INDEX IF NOT EXISTS idx_email_sessions_account ON om_email_sessions(account_id);
CREATE INDEX IF NOT EXISTS idx_email_sessions_expires ON om_email_sessions(expires_at);
CREATE INDEX IF NOT EXISTS idx_email_tokens_account ON om_email_tokens(account_id);
CREATE INDEX IF NOT EXISTS idx_email_tokens_expires ON om_email_tokens(expires_at);
CREATE INDEX IF NOT EXISTS idx_email_contacts_account ON om_email_contacts(account_id, frequency DESC);
CREATE INDEX IF NOT EXISTS idx_email_contacts_search ON om_email_contacts(account_id, email varchar_pattern_ops);

-- Cleanup old sessions (run periodically)
-- DELETE FROM om_email_sessions WHERE expires_at < NOW();
-- DELETE FROM om_email_tokens WHERE expires_at < NOW() AND revoked = true;

COMMIT;
