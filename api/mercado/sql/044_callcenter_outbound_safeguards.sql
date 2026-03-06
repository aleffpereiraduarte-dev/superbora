-- ═══════════════════════════════════════════════════════════════════════════════
-- SuperBora Call Center — Outbound Calls, Safeguards & WhatsApp AI Tables
-- Run AFTER 043_callcenter_complete.sql
-- Safe to re-run (all CREATE TABLE IF NOT EXISTS).
-- ═══════════════════════════════════════════════════════════════════════════════

-- ─── 1. Outbound Calls ──────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS om_outbound_calls (
    id SERIAL PRIMARY KEY,
    twilio_call_sid VARCHAR(50),
    phone VARCHAR(30) NOT NULL,
    customer_id INT,
    customer_name VARCHAR(200),
    call_type VARCHAR(30) NOT NULL CHECK (call_type IN (
        'order_confirm', 'delivery_update', 'reengagement', 'survey', 'promo', 'custom'
    )),
    call_data JSONB DEFAULT '{}',
    status VARCHAR(20) DEFAULT 'initiated' CHECK (status IN (
        'initiated', 'ringing', 'answered', 'completed', 'no_answer', 'busy', 'failed', 'cancelled'
    )),
    outcome VARCHAR(30) CHECK (outcome IS NULL OR outcome IN (
        'confirmed', 'cancelled', 'ordered', 'declined', 'opt_out',
        'voicemail', 'no_interaction', 'feedback', 'rated', 'transferred'
    )),
    outcome_data JSONB,
    duration_seconds INT,
    campaign_id INT,
    last_attempt_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_outbound_calls_phone ON om_outbound_calls(phone);
CREATE INDEX IF NOT EXISTS idx_outbound_calls_type ON om_outbound_calls(call_type, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_outbound_calls_status ON om_outbound_calls(status);
CREATE INDEX IF NOT EXISTS idx_outbound_calls_campaign ON om_outbound_calls(campaign_id) WHERE campaign_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_outbound_calls_sid ON om_outbound_calls(twilio_call_sid) WHERE twilio_call_sid IS NOT NULL;

-- ─── 2. Outbound Call Queue (scheduled calls) ──────────────────────────────

CREATE TABLE IF NOT EXISTS om_outbound_call_queue (
    id SERIAL PRIMARY KEY,
    phone VARCHAR(30) NOT NULL,
    call_type VARCHAR(30) NOT NULL,
    call_data JSONB DEFAULT '{}',
    scheduled_at TIMESTAMP NOT NULL,
    priority INT DEFAULT 5 CHECK (priority >= 1 AND priority <= 10),
    status VARCHAR(20) DEFAULT 'pending' CHECK (status IN ('pending', 'processing', 'completed', 'failed', 'cancelled')),
    campaign_id INT,
    processed_at TIMESTAMP,
    error_message TEXT,
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_outbound_queue_pending ON om_outbound_call_queue(scheduled_at)
    WHERE status = 'pending';
CREATE INDEX IF NOT EXISTS idx_outbound_queue_campaign ON om_outbound_call_queue(campaign_id)
    WHERE campaign_id IS NOT NULL;

-- ─── 3. Outbound Campaigns ─────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS om_outbound_campaigns (
    id SERIAL PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    call_type VARCHAR(30) NOT NULL,
    call_data JSONB DEFAULT '{}',
    total_targets INT DEFAULT 0,
    calls_made INT DEFAULT 0,
    calls_answered INT DEFAULT 0,
    calls_opted_out INT DEFAULT 0,
    calls_ordered INT DEFAULT 0,
    status VARCHAR(20) DEFAULT 'active' CHECK (status IN ('active', 'paused', 'completed', 'cancelled')),
    created_by INT,
    created_at TIMESTAMP DEFAULT NOW()
);

-- ─── 4. Opt-Out Registry (LGPD compliance) ─────────────────────────────────

CREATE TABLE IF NOT EXISTS om_outbound_opt_outs (
    id SERIAL PRIMARY KEY,
    phone VARCHAR(30) NOT NULL,
    reason VARCHAR(200),
    source VARCHAR(30) DEFAULT 'manual' CHECK (source IN ('manual', 'voice', 'whatsapp', 'sms', 'admin')),
    created_at TIMESTAMP DEFAULT NOW()
);

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'om_outbound_opt_outs_phone_uniq') THEN
        ALTER TABLE om_outbound_opt_outs ADD CONSTRAINT om_outbound_opt_outs_phone_uniq UNIQUE (phone);
    END IF;
END $$;

-- ─── 5. AI Safeguards — Rate Limiting ──────────────────────────────────────

CREATE TABLE IF NOT EXISTS om_callcenter_rate_limit (
    id SERIAL PRIMARY KEY,
    phone VARCHAR(30) NOT NULL,
    action VARCHAR(30) NOT NULL DEFAULT 'message',
    window_start TIMESTAMP NOT NULL DEFAULT NOW(),
    count INT DEFAULT 1
);

CREATE INDEX IF NOT EXISTS idx_cc_rate_limit_phone ON om_callcenter_rate_limit(phone, action, window_start DESC);

-- ─── 6. AI Safeguards — Blacklist ──────────────────────────────────────────

CREATE TABLE IF NOT EXISTS om_callcenter_blacklist (
    id SERIAL PRIMARY KEY,
    phone VARCHAR(30) NOT NULL,
    reason VARCHAR(200),
    source VARCHAR(30) DEFAULT 'auto' CHECK (source IN ('auto', 'manual', 'abuse')),
    expires_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT NOW()
);

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'om_callcenter_blacklist_phone_uniq') THEN
        ALTER TABLE om_callcenter_blacklist ADD CONSTRAINT om_callcenter_blacklist_phone_uniq UNIQUE (phone);
    END IF;
END $$;

-- ─── 7. WhatsApp Rate Limiting ─────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS om_whatsapp_rate_limit (
    id SERIAL PRIMARY KEY,
    phone VARCHAR(30) NOT NULL,
    minute_key VARCHAR(20) NOT NULL,
    count INT DEFAULT 1,
    created_at TIMESTAMP DEFAULT NOW()
);

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'om_wa_rate_limit_phone_min_uniq') THEN
        ALTER TABLE om_whatsapp_rate_limit ADD CONSTRAINT om_wa_rate_limit_phone_min_uniq UNIQUE (phone, minute_key);
    END IF;
END $$;

-- ─── 8. Add ai_context to WhatsApp conversations if missing ────────────────

DO $$ BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_name = 'om_callcenter_whatsapp' AND column_name = 'ai_context'
    ) THEN
        ALTER TABLE om_callcenter_whatsapp ADD COLUMN ai_context JSONB DEFAULT '{}';
    END IF;
END $$;

-- ─── 9. Cleanup old rate limit entries (can be run by cron) ────────────────
-- DELETE FROM om_callcenter_rate_limit WHERE window_start < NOW() - INTERVAL '2 hours';
-- DELETE FROM om_whatsapp_rate_limit WHERE created_at < NOW() - INTERVAL '2 hours';

-- ═══════════════════════════════════════════════════════════════════════════════
-- Done! All outbound, safeguards, and WhatsApp AI tables created.
-- ═══════════════════════════════════════════════════════════════════════════════
