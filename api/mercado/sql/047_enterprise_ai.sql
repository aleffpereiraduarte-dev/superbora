-- ═══════════════════════════════════════════════════════════════════════════
-- 047: Enterprise AI Features
-- AI Quality Pipeline, A/B Testing, LGPD Audit, CLV, Monitoring, Multi-lang
-- ═══════════════════════════════════════════════════════════════════════════

-- 1. AI Quality Scoring — every conversation gets auto-scored
CREATE TABLE IF NOT EXISTS om_ai_quality_scores (
    id SERIAL PRIMARY KEY,
    conversation_type VARCHAR(20) NOT NULL, -- 'voice', 'whatsapp'
    conversation_id INT NOT NULL, -- FK to calls or whatsapp table
    overall_score INT NOT NULL DEFAULT 0, -- 0-100
    greeting_score INT DEFAULT 0,         -- 0-100: proper greeting
    understanding_score INT DEFAULT 0,    -- 0-100: understood customer intent
    accuracy_score INT DEFAULT 0,         -- 0-100: correct items/prices
    upsell_score INT DEFAULT 0,           -- 0-100: upsell opportunity used
    tone_score INT DEFAULT 0,             -- 0-100: appropriate tone/sentiment match
    resolution_score INT DEFAULT 0,       -- 0-100: resolved without escalation
    efficiency_score INT DEFAULT 0,       -- 0-100: turns to resolution
    missed_opportunities JSONB DEFAULT '[]', -- [{type, description}]
    issues_detected JSONB DEFAULT '[]',      -- [{type, severity, description}]
    conversion_result VARCHAR(20),        -- 'order_placed', 'abandoned', 'transferred', 'support_only'
    order_value DECIMAL(10,2) DEFAULT 0,
    turns_count INT DEFAULT 0,
    duration_seconds INT DEFAULT 0,
    language_detected VARCHAR(10) DEFAULT 'pt',
    sentiment_flow JSONB DEFAULT '[]',    -- [{turn, sentiment, score}]
    flagged_for_review BOOLEAN DEFAULT FALSE,
    reviewed_by INT,
    review_notes TEXT,
    scored_at TIMESTAMP DEFAULT NOW(),
    created_at TIMESTAMP DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_quality_type_conv ON om_ai_quality_scores(conversation_type, conversation_id);
CREATE INDEX IF NOT EXISTS idx_quality_score ON om_ai_quality_scores(overall_score);
CREATE INDEX IF NOT EXISTS idx_quality_flagged ON om_ai_quality_scores(flagged_for_review) WHERE flagged_for_review = TRUE;
CREATE INDEX IF NOT EXISTS idx_quality_date ON om_ai_quality_scores(created_at);

-- 2. A/B Testing framework
CREATE TABLE IF NOT EXISTS om_ab_tests (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    test_type VARCHAR(30) NOT NULL, -- 'prompt_style', 'upsell_strategy', 'greeting', 'tone'
    channel VARCHAR(20) NOT NULL,   -- 'voice', 'whatsapp', 'both'
    status VARCHAR(20) DEFAULT 'draft', -- 'draft', 'running', 'paused', 'completed'
    variants JSONB NOT NULL,        -- [{id, name, weight, config:{prompt_modifier, tone, ...}}]
    metrics JSONB DEFAULT '{}',     -- {variant_id: {impressions, conversions, revenue, avg_score, ...}}
    winner_variant VARCHAR(50),
    confidence_level DECIMAL(5,2),
    min_sample_size INT DEFAULT 100,
    started_at TIMESTAMP,
    ended_at TIMESTAMP,
    created_by INT,
    created_at TIMESTAMP DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_ab_status ON om_ab_tests(status);

CREATE TABLE IF NOT EXISTS om_ab_assignments (
    id SERIAL PRIMARY KEY,
    test_id INT NOT NULL REFERENCES om_ab_tests(id),
    customer_phone VARCHAR(20),
    customer_id INT,
    variant_id VARCHAR(50) NOT NULL,
    conversation_type VARCHAR(20),
    conversation_id INT,
    converted BOOLEAN DEFAULT FALSE,
    order_value DECIMAL(10,2) DEFAULT 0,
    quality_score INT DEFAULT 0,
    assigned_at TIMESTAMP DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_ab_assign_test ON om_ab_assignments(test_id, variant_id);
CREATE INDEX IF NOT EXISTS idx_ab_assign_phone ON om_ab_assignments(customer_phone);

-- 3. LGPD Audit Trail
CREATE TABLE IF NOT EXISTS om_audit_log (
    id SERIAL PRIMARY KEY,
    event_type VARCHAR(50) NOT NULL, -- 'conversation', 'data_access', 'data_delete', 'consent_change', 'pii_access'
    actor_type VARCHAR(20) NOT NULL, -- 'system', 'ai', 'agent', 'customer', 'admin'
    actor_id VARCHAR(50),
    customer_phone VARCHAR(20),
    customer_id INT,
    resource_type VARCHAR(30),       -- 'call', 'whatsapp', 'order', 'memory', 'address'
    resource_id VARCHAR(50),
    action VARCHAR(30) NOT NULL,     -- 'create', 'read', 'update', 'delete', 'export', 'mask'
    details JSONB DEFAULT '{}',      -- {masked_fields:[], reason:'', ip:''}
    pii_fields_accessed TEXT[],      -- ['phone', 'address', 'cpf', 'name']
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_audit_customer ON om_audit_log(customer_id, created_at);
CREATE INDEX IF NOT EXISTS idx_audit_phone ON om_audit_log(customer_phone, created_at);
CREATE INDEX IF NOT EXISTS idx_audit_type ON om_audit_log(event_type, created_at);
CREATE INDEX IF NOT EXISTS idx_audit_date ON om_audit_log(created_at);

CREATE TABLE IF NOT EXISTS om_customer_consent (
    id SERIAL PRIMARY KEY,
    customer_id INT,
    customer_phone VARCHAR(20),
    consent_type VARCHAR(30) NOT NULL, -- 'proactive_messages', 'data_processing', 'marketing', 'voice_recording', 'ai_training'
    granted BOOLEAN DEFAULT FALSE,
    granted_at TIMESTAMP,
    revoked_at TIMESTAMP,
    source VARCHAR(20), -- 'whatsapp', 'voice', 'app', 'admin'
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    UNIQUE(customer_phone, consent_type)
);
CREATE INDEX IF NOT EXISTS idx_consent_customer ON om_customer_consent(customer_id);

CREATE TABLE IF NOT EXISTS om_data_deletion_requests (
    id SERIAL PRIMARY KEY,
    customer_id INT,
    customer_phone VARCHAR(20) NOT NULL,
    request_source VARCHAR(20) NOT NULL, -- 'whatsapp', 'voice', 'admin', 'api'
    status VARCHAR(20) DEFAULT 'pending', -- 'pending', 'processing', 'completed', 'failed'
    tables_affected JSONB DEFAULT '[]',   -- [{table, rows_deleted}]
    completed_at TIMESTAMP,
    requested_by VARCHAR(50),
    created_at TIMESTAMP DEFAULT NOW()
);

-- 4. Customer Lifetime Value
CREATE TABLE IF NOT EXISTS om_customer_clv (
    customer_id INT PRIMARY KEY,
    phone VARCHAR(20),
    total_orders INT DEFAULT 0,
    total_spent DECIMAL(12,2) DEFAULT 0,
    avg_order_value DECIMAL(10,2) DEFAULT 0,
    first_order_at TIMESTAMP,
    last_order_at TIMESTAMP,
    days_as_customer INT DEFAULT 0,
    order_frequency_days DECIMAL(8,2) DEFAULT 0, -- avg days between orders
    predicted_monthly_value DECIMAL(10,2) DEFAULT 0,
    predicted_annual_value DECIMAL(12,2) DEFAULT 0,
    clv_score INT DEFAULT 0,          -- 0-100
    clv_tier VARCHAR(20) DEFAULT 'new', -- 'new', 'growing', 'stable', 'vip', 'at_risk', 'churned'
    churn_risk DECIMAL(5,2) DEFAULT 0,  -- 0-100%
    days_since_last_order INT DEFAULT 0,
    preferred_channel VARCHAR(20),     -- 'app', 'whatsapp', 'voice'
    preferred_payment VARCHAR(20),
    favorite_partner_id INT,
    favorite_category VARCHAR(50),
    last_calculated_at TIMESTAMP DEFAULT NOW(),
    created_at TIMESTAMP DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_clv_tier ON om_customer_clv(clv_tier);
CREATE INDEX IF NOT EXISTS idx_clv_score ON om_customer_clv(clv_score DESC);
CREATE INDEX IF NOT EXISTS idx_clv_churn ON om_customer_clv(churn_risk DESC);

-- 5. Proactive Resolution — late order tracking
CREATE TABLE IF NOT EXISTS om_proactive_alerts (
    id SERIAL PRIMARY KEY,
    order_id INT NOT NULL,
    alert_type VARCHAR(30) NOT NULL, -- 'late_order', 'no_accept', 'no_preparation', 'delivery_stuck'
    severity VARCHAR(10) DEFAULT 'warning', -- 'info', 'warning', 'critical'
    detected_at TIMESTAMP DEFAULT NOW(),
    expected_time TIMESTAMP,
    actual_delay_minutes INT DEFAULT 0,
    auto_action_taken VARCHAR(50),    -- 'notified_customer', 'notified_partner', 'offered_compensation', 'escalated'
    compensation_type VARCHAR(30),    -- 'cashback', 'coupon', 'refund', 'none'
    compensation_value DECIMAL(10,2) DEFAULT 0,
    customer_notified BOOLEAN DEFAULT FALSE,
    partner_notified BOOLEAN DEFAULT FALSE,
    resolved BOOLEAN DEFAULT FALSE,
    resolved_at TIMESTAMP,
    notes TEXT,
    created_at TIMESTAMP DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_proactive_order ON om_proactive_alerts(order_id);
CREATE INDEX IF NOT EXISTS idx_proactive_unresolved ON om_proactive_alerts(resolved) WHERE resolved = FALSE;
CREATE INDEX IF NOT EXISTS idx_proactive_date ON om_proactive_alerts(created_at);

-- 6. Live monitoring sessions
CREATE TABLE IF NOT EXISTS om_ai_monitor_sessions (
    id SERIAL PRIMARY KEY,
    admin_id INT NOT NULL,
    conversation_type VARCHAR(20) NOT NULL, -- 'voice', 'whatsapp'
    conversation_id INT NOT NULL,
    action VARCHAR(20) DEFAULT 'observe', -- 'observe', 'intervene', 'takeover'
    started_at TIMESTAMP DEFAULT NOW(),
    ended_at TIMESTAMP,
    notes TEXT
);

-- 7. Retry/Fallback tracking
CREATE TABLE IF NOT EXISTS om_ai_retry_log (
    id SERIAL PRIMARY KEY,
    conversation_type VARCHAR(20) NOT NULL,
    conversation_id INT NOT NULL,
    attempt_number INT DEFAULT 1,
    error_type VARCHAR(50),          -- 'timeout', 'api_error', 'invalid_response', 'content_filter'
    error_message TEXT,
    fallback_used VARCHAR(30),       -- 'retry', 'simplified_prompt', 'fallback_message', 'transfer_human'
    success BOOLEAN DEFAULT FALSE,
    response_time_ms INT,
    created_at TIMESTAMP DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_retry_conv ON om_ai_retry_log(conversation_type, conversation_id);
CREATE INDEX IF NOT EXISTS idx_retry_date ON om_ai_retry_log(created_at);

-- 8. Multi-store order groups
CREATE TABLE IF NOT EXISTS om_multi_store_orders (
    id SERIAL PRIMARY KEY,
    group_id VARCHAR(20) NOT NULL UNIQUE, -- 'MSG-XXXXX'
    customer_id INT,
    customer_phone VARCHAR(20),
    order_ids INT[] DEFAULT '{}',
    total_combined DECIMAL(10,2) DEFAULT 0,
    status VARCHAR(20) DEFAULT 'building', -- 'building', 'submitted', 'partial', 'completed'
    source VARCHAR(20), -- 'whatsapp', 'voice'
    created_at TIMESTAMP DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_multi_store_customer ON om_multi_store_orders(customer_id);
