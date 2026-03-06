-- ═══════════════════════════════════════════════════════════════════════════════
-- SuperBora Call Center — Complete Database Schema
-- Run this on production PostgreSQL to create all required tables.
-- Safe to re-run (all CREATE TABLE IF NOT EXISTS).
-- ═══════════════════════════════════════════════════════════════════════════════

-- 1. Call Center Agents
CREATE TABLE IF NOT EXISTS om_callcenter_agents (
    id SERIAL PRIMARY KEY,
    admin_id INT,
    display_name VARCHAR(100) NOT NULL,
    extension VARCHAR(10),
    status VARCHAR(20) DEFAULT 'offline' CHECK (status IN ('online','busy','break','offline')),
    skills TEXT[] DEFAULT '{}',
    max_concurrent INT DEFAULT 3,
    avatar_url VARCHAR(500),
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- 2. Call Records
CREATE TABLE IF NOT EXISTS om_callcenter_calls (
    id SERIAL PRIMARY KEY,
    twilio_call_sid VARCHAR(50),
    customer_phone VARCHAR(30),
    customer_id INT,
    customer_name VARCHAR(200),
    agent_id INT REFERENCES om_callcenter_agents(id),
    direction VARCHAR(10) DEFAULT 'inbound' CHECK (direction IN ('inbound','outbound')),
    status VARCHAR(20) DEFAULT 'queued' CHECK (status IN ('queued','ringing','ai_handling','in_progress','on_hold','completed','missed','voicemail','callback')),
    duration_seconds INT,
    recording_url TEXT,
    recording_duration INT,
    transcription TEXT,
    ai_summary TEXT,
    ai_sentiment VARCHAR(20) CHECK (ai_sentiment IS NULL OR ai_sentiment IN ('positive','neutral','negative','frustrated')),
    ai_tags TEXT[],
    ai_context JSONB,
    notes TEXT,
    order_id INT,
    store_identified VARCHAR(200),
    callback_requested BOOLEAN DEFAULT FALSE,
    callback_completed_at TIMESTAMP,
    wait_time_seconds INT,
    outbound_type VARCHAR(30),
    started_at TIMESTAMP,
    answered_at TIMESTAMP,
    ended_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT NOW()
);

-- Unique constraint on twilio_call_sid (allow re-runs)
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'om_callcenter_calls_twilio_sid_uniq') THEN
        ALTER TABLE om_callcenter_calls ADD CONSTRAINT om_callcenter_calls_twilio_sid_uniq UNIQUE (twilio_call_sid);
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_cc_calls_phone ON om_callcenter_calls(customer_phone);
CREATE INDEX IF NOT EXISTS idx_cc_calls_status ON om_callcenter_calls(status);
CREATE INDEX IF NOT EXISTS idx_cc_calls_created ON om_callcenter_calls(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_cc_calls_agent ON om_callcenter_calls(agent_id) WHERE agent_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_cc_calls_direction ON om_callcenter_calls(direction, created_at DESC);

-- 3. Call Queue
CREATE TABLE IF NOT EXISTS om_callcenter_queue (
    id SERIAL PRIMARY KEY,
    call_id INT REFERENCES om_callcenter_calls(id),
    customer_phone VARCHAR(30),
    customer_name VARCHAR(200),
    customer_id INT,
    priority INT DEFAULT 5 CHECK (priority >= 1 AND priority <= 10),
    skill_required VARCHAR(50),
    estimated_wait_seconds INT,
    position_in_queue INT,
    queued_at TIMESTAMP DEFAULT NOW(),
    picked_at TIMESTAMP,
    picked_by INT REFERENCES om_callcenter_agents(id),
    abandoned_at TIMESTAMP,
    callback_number VARCHAR(30)
);

CREATE INDEX IF NOT EXISTS idx_cc_queue_active ON om_callcenter_queue(queued_at) WHERE picked_at IS NULL AND abandoned_at IS NULL;

-- 4. WhatsApp Conversations
CREATE TABLE IF NOT EXISTS om_callcenter_whatsapp (
    id SERIAL PRIMARY KEY,
    phone VARCHAR(30) NOT NULL,
    customer_id INT,
    customer_name VARCHAR(200),
    agent_id INT REFERENCES om_callcenter_agents(id),
    status VARCHAR(20) DEFAULT 'bot' CHECK (status IN ('bot','waiting','assigned','closed')),
    ai_context JSONB DEFAULT '{}',
    last_message_at TIMESTAMP DEFAULT NOW(),
    unread_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_cc_wa_phone ON om_callcenter_whatsapp(phone);
CREATE INDEX IF NOT EXISTS idx_cc_wa_status ON om_callcenter_whatsapp(status);
CREATE INDEX IF NOT EXISTS idx_cc_wa_agent ON om_callcenter_whatsapp(agent_id) WHERE agent_id IS NOT NULL;

-- 5. WhatsApp Messages
CREATE TABLE IF NOT EXISTS om_callcenter_wa_messages (
    id SERIAL PRIMARY KEY,
    conversation_id INT NOT NULL REFERENCES om_callcenter_whatsapp(id),
    direction VARCHAR(10) DEFAULT 'inbound' CHECK (direction IN ('inbound','outbound')),
    sender_type VARCHAR(10) DEFAULT 'customer' CHECK (sender_type IN ('customer','agent','bot')),
    message TEXT,
    message_type VARCHAR(20) DEFAULT 'text' CHECK (message_type IN ('text','image','audio','document','location','sticker')),
    media_url TEXT,
    ai_suggested BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_cc_wam_conv ON om_callcenter_wa_messages(conversation_id, created_at DESC);

-- 6. Order Drafts
CREATE TABLE IF NOT EXISTS om_callcenter_order_drafts (
    id SERIAL PRIMARY KEY,
    agent_id INT REFERENCES om_callcenter_agents(id),
    call_id INT REFERENCES om_callcenter_calls(id),
    whatsapp_id INT REFERENCES om_callcenter_whatsapp(id),
    source VARCHAR(20) DEFAULT 'phone' CHECK (source IN ('phone','whatsapp','manual')),
    customer_id INT,
    customer_name VARCHAR(200),
    customer_phone VARCHAR(30),
    customer_address_id INT,
    partner_id INT,
    partner_name VARCHAR(200),
    items JSONB DEFAULT '[]',
    address JSONB,
    payment_method VARCHAR(30),
    payment_change DECIMAL(10,2),
    payment_link_url TEXT,
    payment_link_id VARCHAR(100),
    subtotal DECIMAL(10,2) DEFAULT 0,
    delivery_fee DECIMAL(10,2) DEFAULT 0,
    service_fee DECIMAL(10,2) DEFAULT 0,
    tip DECIMAL(10,2) DEFAULT 0,
    discount DECIMAL(10,2) DEFAULT 0,
    coupon_code VARCHAR(50),
    total DECIMAL(10,2) DEFAULT 0,
    notes TEXT,
    status VARCHAR(20) DEFAULT 'building' CHECK (status IN ('building','review','awaiting_payment','submitted','cancelled')),
    sms_sent BOOLEAN DEFAULT FALSE,
    sms_sent_at TIMESTAMP,
    submitted_order_id INT,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_cc_drafts_agent ON om_callcenter_order_drafts(agent_id) WHERE agent_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_cc_drafts_status ON om_callcenter_order_drafts(status);

-- 7. Daily Metrics
CREATE TABLE IF NOT EXISTS om_callcenter_metrics (
    id SERIAL PRIMARY KEY,
    date DATE NOT NULL,
    agent_id INT REFERENCES om_callcenter_agents(id),
    total_calls INT DEFAULT 0,
    answered_calls INT DEFAULT 0,
    missed_calls INT DEFAULT 0,
    ai_handled_calls INT DEFAULT 0,
    ai_orders_placed INT DEFAULT 0,
    agent_orders_placed INT DEFAULT 0,
    avg_handle_time_seconds INT DEFAULT 0,
    avg_wait_time_seconds INT DEFAULT 0,
    orders_total_value DECIMAL(10,2) DEFAULT 0,
    whatsapp_conversations INT DEFAULT 0,
    callbacks_requested INT DEFAULT 0,
    callbacks_completed INT DEFAULT 0,
    csat_sum INT DEFAULT 0,
    csat_count INT DEFAULT 0
);

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'om_callcenter_metrics_date_agent_uniq') THEN
        ALTER TABLE om_callcenter_metrics ADD CONSTRAINT om_callcenter_metrics_date_agent_uniq UNIQUE (date, agent_id);
    END IF;
END $$;

-- 8. Payment Links
CREATE TABLE IF NOT EXISTS om_callcenter_payment_links (
    id SERIAL PRIMARY KEY,
    draft_id INT REFERENCES om_callcenter_order_drafts(id),
    stripe_session_id VARCHAR(200),
    stripe_payment_link_url TEXT,
    amount DECIMAL(10,2) NOT NULL,
    status VARCHAR(20) DEFAULT 'pending' CHECK (status IN ('pending','paid','expired','cancelled')),
    customer_phone VARCHAR(30),
    sms_sent BOOLEAN DEFAULT FALSE,
    paid_at TIMESTAMP,
    expires_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT NOW()
);

-- 9. AI Call Memory (cross-call persistent memory)
CREATE TABLE IF NOT EXISTS om_ai_call_memory (
    id SERIAL PRIMARY KEY,
    customer_phone VARCHAR(20) NOT NULL,
    customer_id INT,
    memory_type VARCHAR(30) NOT NULL,
    memory_key VARCHAR(100) NOT NULL,
    memory_value TEXT NOT NULL,
    confidence FLOAT DEFAULT 1.0,
    times_confirmed INT DEFAULT 1,
    last_used_at TIMESTAMP DEFAULT NOW(),
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    UNIQUE(customer_phone, memory_type, memory_key)
);

CREATE INDEX IF NOT EXISTS idx_ai_call_memory_phone ON om_ai_call_memory(customer_phone);
CREATE INDEX IF NOT EXISTS idx_ai_call_memory_customer ON om_ai_call_memory(customer_id) WHERE customer_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_ai_call_memory_type ON om_ai_call_memory(customer_phone, memory_type);
CREATE INDEX IF NOT EXISTS idx_ai_call_memory_last_used ON om_ai_call_memory(last_used_at DESC);

-- ═══════════════════════════════════════════════════════════════════════════════
-- Add ai_context column to calls table if missing (for older installs)
-- ═══════════════════════════════════════════════════════════════════════════════
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'om_callcenter_calls' AND column_name = 'ai_context') THEN
        ALTER TABLE om_callcenter_calls ADD COLUMN ai_context JSONB;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'om_callcenter_calls' AND column_name = 'outbound_type') THEN
        ALTER TABLE om_callcenter_calls ADD COLUMN outbound_type VARCHAR(30);
    END IF;
END $$;

-- Add source column to market orders if missing
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'om_market_orders' AND column_name = 'source') THEN
        ALTER TABLE om_market_orders ADD COLUMN source VARCHAR(20) DEFAULT 'app';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'om_market_orders' AND column_name = 'created_by_agent_id') THEN
        ALTER TABLE om_market_orders ADD COLUMN created_by_agent_id INT;
    END IF;
END $$;

-- ═══════════════════════════════════════════════════════════════════════════════
-- Done! All call center tables created.
-- ═══════════════════════════════════════════════════════════════════════════════
