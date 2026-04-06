-- 061: Professional email queue system
-- Separate from om_email_queue (RH/Google Workspace provisioning)

CREATE TABLE IF NOT EXISTS om_market_email_queue (
    id SERIAL PRIMARY KEY,
    to_email VARCHAR(255) NOT NULL,
    to_name VARCHAR(255),
    subject VARCHAR(255) NOT NULL,
    body_html TEXT NOT NULL,
    body_text TEXT,
    template VARCHAR(50) DEFAULT 'generic',
    customer_id INTEGER,
    priority SMALLINT DEFAULT 0,
    status VARCHAR(20) DEFAULT 'pending',
    attempts INTEGER DEFAULT 0,
    max_attempts INTEGER DEFAULT 3,
    last_error TEXT,
    scheduled_at TIMESTAMP DEFAULT NOW(),
    sent_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_market_email_queue_status ON om_market_email_queue(status, scheduled_at);
CREATE INDEX IF NOT EXISTS idx_market_email_queue_customer ON om_market_email_queue(customer_id);

-- Add reply_to and from_name columns to support custom senders
-- (future-proof for partner emails, etc.)
ALTER TABLE om_market_email_queue ADD COLUMN IF NOT EXISTS reply_to VARCHAR(255);
ALTER TABLE om_market_email_queue ADD COLUMN IF NOT EXISTS from_name VARCHAR(255);

-- Extend om_email_logs with more tracking fields
ALTER TABLE om_email_logs ADD COLUMN IF NOT EXISTS queue_id INTEGER;
ALTER TABLE om_email_logs ADD COLUMN IF NOT EXISTS attempts INTEGER DEFAULT 1;
ALTER TABLE om_email_logs ADD COLUMN IF NOT EXISTS send_method VARCHAR(20) DEFAULT 'direct';
