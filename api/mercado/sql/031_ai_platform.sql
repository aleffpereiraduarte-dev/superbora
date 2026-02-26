-- 031_ai_platform.sql
-- SuperBora AI Platform — All tables for AI features (inspired by iFood)

-- =====================================================================
-- CUSTOMER AI FEATURES
-- =====================================================================

-- Customer AI conversations (ordering assistant)
CREATE TABLE IF NOT EXISTS om_ai_customer_conversations (
    conversation_id VARCHAR(32) PRIMARY KEY DEFAULT md5(random()::text || now()::text),
    customer_id INT NOT NULL,
    channel VARCHAR(20) DEFAULT 'app',
    status VARCHAR(20) DEFAULT 'active',
    context JSONB DEFAULT '{}',
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_ai_cust_conv_customer ON om_ai_customer_conversations(customer_id, status);

-- Customer AI conversation messages
CREATE TABLE IF NOT EXISTS om_ai_customer_messages (
    message_id SERIAL PRIMARY KEY,
    conversation_id VARCHAR(32) NOT NULL,
    role VARCHAR(10) NOT NULL,
    content TEXT NOT NULL,
    suggestions JSONB,
    tokens_used INT DEFAULT 0,
    model VARCHAR(50),
    created_at TIMESTAMP DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_ai_cust_msg_conv ON om_ai_customer_messages(conversation_id, created_at);

-- User behavior events (for recommendations)
CREATE TABLE IF NOT EXISTS om_user_events (
    event_id BIGSERIAL PRIMARY KEY,
    customer_id INT,
    session_id VARCHAR(64),
    event_type VARCHAR(30) NOT NULL,
    entity_type VARCHAR(20),
    entity_id INT,
    metadata JSONB DEFAULT '{}',
    created_at TIMESTAMP DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_user_events_customer ON om_user_events(customer_id, event_type, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_user_events_entity ON om_user_events(entity_type, entity_id, event_type);

-- Smart push notification log
CREATE TABLE IF NOT EXISTS om_smart_push_log (
    id SERIAL PRIMARY KEY,
    customer_id INT NOT NULL,
    push_type VARCHAR(40) NOT NULL,
    title VARCHAR(200),
    body TEXT,
    metadata JSONB DEFAULT '{}',
    sent_at TIMESTAMP DEFAULT NOW(),
    opened_at TIMESTAMP,
    clicked_at TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_smart_push_customer ON om_smart_push_log(customer_id, push_type, sent_at DESC);
CREATE INDEX IF NOT EXISTS idx_smart_push_rate ON om_smart_push_log(customer_id, sent_at DESC);

-- Notification engagement tracking
CREATE TABLE IF NOT EXISTS om_notification_engagement (
    id SERIAL PRIMARY KEY,
    notification_id INT,
    customer_id INT NOT NULL,
    action VARCHAR(20) NOT NULL,
    metadata JSONB DEFAULT '{}',
    created_at TIMESTAMP DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_notif_engagement ON om_notification_engagement(customer_id, action, created_at DESC);

-- AI Support tickets
CREATE TABLE IF NOT EXISTS om_ai_support_tickets (
    ticket_id SERIAL PRIMARY KEY,
    customer_id INT NOT NULL,
    order_id INT,
    status VARCHAR(20) DEFAULT 'open',
    category VARCHAR(40),
    priority VARCHAR(10) DEFAULT 'normal',
    ai_confidence DECIMAL(3,2),
    resolved_by VARCHAR(20),
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    closed_at TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_ai_support_customer ON om_ai_support_tickets(customer_id, status);
CREATE INDEX IF NOT EXISTS idx_ai_support_status ON om_ai_support_tickets(status, priority);

-- AI Support ticket messages
CREATE TABLE IF NOT EXISTS om_ai_support_messages (
    message_id SERIAL PRIMARY KEY,
    ticket_id INT NOT NULL,
    role VARCHAR(10) NOT NULL,
    content TEXT NOT NULL,
    metadata JSONB DEFAULT '{}',
    created_at TIMESTAMP DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_ai_support_msg ON om_ai_support_messages(ticket_id, created_at);

-- Fraud detection signals
CREATE TABLE IF NOT EXISTS om_fraud_signals (
    id SERIAL PRIMARY KEY,
    customer_id INT NOT NULL,
    order_id INT,
    score INT NOT NULL,
    signals JSONB NOT NULL,
    action VARCHAR(20) NOT NULL,
    ip_address VARCHAR(45),
    reviewed_by INT,
    reviewed_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_fraud_customer ON om_fraud_signals(customer_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_fraud_action ON om_fraud_signals(action, created_at DESC);

-- =====================================================================
-- PARTNER AI FEATURES
-- =====================================================================

-- Partner computed metrics cache
CREATE TABLE IF NOT EXISTS om_partner_metrics (
    partner_id INT PRIMARY KEY,
    avg_prep_minutes DECIMAL(6,1),
    avg_delivery_minutes DECIMAL(6,1),
    order_count_30d INT DEFAULT 0,
    avg_rating DECIMAL(3,2),
    avg_ticket DECIMAL(10,2),
    peak_hours JSONB DEFAULT '[]',
    calculated_at TIMESTAMP DEFAULT NOW()
);

-- Partner daily digest log
CREATE TABLE IF NOT EXISTS om_partner_digest_log (
    id SERIAL PRIMARY KEY,
    partner_id INT NOT NULL,
    digest_date DATE NOT NULL,
    digest_type VARCHAR(30) DEFAULT 'daily',
    metrics JSONB NOT NULL,
    alerts JSONB DEFAULT '[]',
    sent_whatsapp BOOLEAN DEFAULT FALSE,
    sent_push BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT NOW(),
    UNIQUE(partner_id, digest_date, digest_type)
);

-- Partner notification preferences
CREATE TABLE IF NOT EXISTS om_partner_notification_prefs (
    partner_id INT PRIMARY KEY,
    daily_digest_whatsapp BOOLEAN DEFAULT TRUE,
    daily_digest_push BOOLEAN DEFAULT TRUE,
    negative_review_alert BOOLEAN DEFAULT TRUE,
    weekly_report BOOLEAN DEFAULT TRUE,
    ai_suggestions BOOLEAN DEFAULT TRUE,
    quiet_hours_start TIME DEFAULT '22:00',
    quiet_hours_end TIME DEFAULT '08:00',
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Partner review auto-response config
CREATE TABLE IF NOT EXISTS om_partner_review_config (
    partner_id INT PRIMARY KEY,
    auto_respond_positive BOOLEAN DEFAULT FALSE,
    auto_respond_neutral BOOLEAN DEFAULT FALSE,
    min_rating_auto INT DEFAULT 4,
    response_style VARCHAR(50) DEFAULT 'professional',
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Demand forecast per partner
CREATE TABLE IF NOT EXISTS om_partner_demand_forecast (
    id SERIAL PRIMARY KEY,
    partner_id INT NOT NULL,
    forecast_date DATE NOT NULL,
    predicted_orders INT,
    predicted_revenue NUMERIC(10,2),
    confidence NUMERIC(3,2),
    day_of_week INT,
    factors JSONB,
    created_at TIMESTAMP DEFAULT NOW(),
    UNIQUE(partner_id, forecast_date)
);

-- AI menu processing sessions (multi-page, compare, sync)
CREATE TABLE IF NOT EXISTS om_ai_menu_sessions (
    id SERIAL PRIMARY KEY,
    partner_id INT NOT NULL,
    session_type VARCHAR(30) NOT NULL,
    input_data JSONB,
    result_data JSONB,
    status VARCHAR(20) DEFAULT 'pending',
    tokens_used INT DEFAULT 0,
    page_count INT DEFAULT 1,
    photos_extracted INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_ai_menu_sessions_partner ON om_ai_menu_sessions(partner_id, created_at DESC);

-- Partner WhatsApp conversation log
CREATE TABLE IF NOT EXISTS om_partner_whatsapp_log (
    id SERIAL PRIMARY KEY,
    partner_id INT NOT NULL,
    direction VARCHAR(10) NOT NULL,
    message TEXT NOT NULL,
    phone VARCHAR(20),
    created_at TIMESTAMP DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_partner_wa_log ON om_partner_whatsapp_log(partner_id, created_at DESC);

-- =====================================================================
-- AUTO-PRUNE: cleanup events older than 90 days (handled by cron)
-- =====================================================================
