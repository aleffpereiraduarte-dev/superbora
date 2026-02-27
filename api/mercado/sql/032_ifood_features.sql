-- 032_ifood_features.sql
-- SuperBora iFood Parity Phase 1 & 2 tables
-- Feature flags, favorites, search history, email logs, push campaigns, SMS codes

-- ═══════════════════════════════════════════
-- 1. FEATURE FLAGS
-- ═══════════════════════════════════════════

CREATE TABLE IF NOT EXISTS om_feature_flags (
    id SERIAL PRIMARY KEY,
    flag_key VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(255),
    enabled BOOLEAN DEFAULT FALSE,
    rollout_percent INT DEFAULT 100,          -- 0-100, for gradual rollout
    user_segment VARCHAR(50),                  -- NULL=all, 'new_users', 'premium', 'city:campinas'
    metadata JSONB DEFAULT '{}',               -- extra config (min_version, platforms, etc)
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_ff_key ON om_feature_flags(flag_key);
CREATE INDEX IF NOT EXISTS idx_ff_enabled ON om_feature_flags(enabled) WHERE enabled = TRUE;

-- ═══════════════════════════════════════════
-- 2. CUSTOMER FAVORITES (lojas favoritas)
-- ═══════════════════════════════════════════

CREATE TABLE IF NOT EXISTS om_customer_favorites (
    id SERIAL PRIMARY KEY,
    customer_id INT NOT NULL,
    partner_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT NOW(),
    UNIQUE(customer_id, partner_id)
);
CREATE INDEX IF NOT EXISTS idx_fav_customer ON om_customer_favorites(customer_id);
CREATE INDEX IF NOT EXISTS idx_fav_partner ON om_customer_favorites(partner_id);

-- ═══════════════════════════════════════════
-- 3. SEARCH HISTORY
-- ═══════════════════════════════════════════

CREATE TABLE IF NOT EXISTS om_search_history (
    id SERIAL PRIMARY KEY,
    customer_id INT NOT NULL,
    query VARCHAR(255) NOT NULL,
    results_count INT DEFAULT 0,
    city VARCHAR(100),
    created_at TIMESTAMP DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_search_customer ON om_search_history(customer_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_search_city ON om_search_history(city, created_at DESC);

-- Trending searches (aggregated, updated by cron)
CREATE TABLE IF NOT EXISTS om_search_trending (
    id SERIAL PRIMARY KEY,
    query VARCHAR(255) NOT NULL,
    city VARCHAR(100),
    search_count INT DEFAULT 1,
    period DATE NOT NULL DEFAULT CURRENT_DATE,
    UNIQUE(query, city, period)
);
CREATE INDEX IF NOT EXISTS idx_trending_city ON om_search_trending(city, period DESC, search_count DESC);

-- ═══════════════════════════════════════════
-- 4. EMAIL LOGS
-- ═══════════════════════════════════════════

CREATE TABLE IF NOT EXISTS om_email_logs (
    id SERIAL PRIMARY KEY,
    customer_id INT,
    email_to VARCHAR(255) NOT NULL,
    template VARCHAR(50) NOT NULL,            -- 'order_confirmed', 'status_update', 'welcome', 'receipt'
    subject VARCHAR(255),
    status VARCHAR(20) DEFAULT 'queued',      -- queued, sent, failed, bounced
    metadata JSONB DEFAULT '{}',               -- order_id, error message, etc
    sent_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_email_customer ON om_email_logs(customer_id);
CREATE INDEX IF NOT EXISTS idx_email_status ON om_email_logs(status, created_at DESC);

-- ═══════════════════════════════════════════
-- 5. PUSH CAMPAIGNS
-- ═══════════════════════════════════════════

CREATE TABLE IF NOT EXISTS om_push_campaigns (
    id SERIAL PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    body TEXT NOT NULL,
    image_url VARCHAR(500),
    data JSONB DEFAULT '{}',                   -- deep link, extra params
    segment_type VARCHAR(50) NOT NULL,         -- 'all', 'city', 'inactive', 'high_value', 'custom'
    segment_config JSONB DEFAULT '{}',         -- {"city":"campinas"}, {"days_inactive":30}, {"min_spent":100}
    status VARCHAR(20) DEFAULT 'draft',        -- draft, scheduled, sending, sent, cancelled
    scheduled_at TIMESTAMP,
    sent_at TIMESTAMP,
    total_sent INT DEFAULT 0,
    total_opened INT DEFAULT 0,
    total_clicked INT DEFAULT 0,
    created_by INT,                            -- admin user id
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_campaign_status ON om_push_campaigns(status, scheduled_at);

-- Individual send log per campaign
CREATE TABLE IF NOT EXISTS om_push_campaign_sends (
    id SERIAL PRIMARY KEY,
    campaign_id INT NOT NULL REFERENCES om_push_campaigns(id) ON DELETE CASCADE,
    customer_id INT NOT NULL,
    push_token TEXT,
    status VARCHAR(20) DEFAULT 'pending',     -- pending, sent, failed, opened, clicked
    sent_at TIMESTAMP,
    opened_at TIMESTAMP,
    clicked_at TIMESTAMP,
    error_message TEXT,
    created_at TIMESTAMP DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_camp_sends_campaign ON om_push_campaign_sends(campaign_id, status);
CREATE INDEX IF NOT EXISTS idx_camp_sends_customer ON om_push_campaign_sends(customer_id);

-- ═══════════════════════════════════════════
-- 6. SMS VERIFICATION CODES
-- ═══════════════════════════════════════════

CREATE TABLE IF NOT EXISTS om_sms_codes (
    id SERIAL PRIMARY KEY,
    phone VARCHAR(20) NOT NULL,
    code VARCHAR(6) NOT NULL,
    purpose VARCHAR(30) DEFAULT 'login',      -- 'login', 'register', 'reset_password'
    attempts INT DEFAULT 0,
    verified BOOLEAN DEFAULT FALSE,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_sms_phone ON om_sms_codes(phone, expires_at DESC);
CREATE INDEX IF NOT EXISTS idx_sms_cleanup ON om_sms_codes(expires_at) WHERE verified = FALSE;

-- ═══════════════════════════════════════════
-- 7. CUSTOMER SAVED CARDS (metadata only — tokens in Stripe)
-- ═══════════════════════════════════════════

CREATE TABLE IF NOT EXISTS om_customer_cards (
    id SERIAL PRIMARY KEY,
    customer_id INT NOT NULL,
    stripe_payment_method_id VARCHAR(100) NOT NULL UNIQUE,
    brand VARCHAR(20),                         -- visa, mastercard, elo, amex
    last4 VARCHAR(4),
    exp_month INT,
    exp_year INT,
    is_default BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_cards_customer ON om_customer_cards(customer_id);

-- ═══════════════════════════════════════════
-- 8. CHURN PREDICTION (Phase 2)
-- ═══════════════════════════════════════════

CREATE TABLE IF NOT EXISTS om_churn_scores (
    id SERIAL PRIMARY KEY,
    customer_id INT NOT NULL,
    score NUMERIC(5,2) NOT NULL,              -- 0.00 to 100.00 (higher = more likely to churn)
    risk_level VARCHAR(20),                    -- 'low', 'medium', 'high', 'critical'
    last_order_days INT,                       -- days since last order
    order_frequency NUMERIC(5,2),              -- orders per month
    avg_ticket NUMERIC(10,2),
    ai_analysis TEXT,                          -- Claude's recommendation
    action_taken VARCHAR(50),                  -- 'none', 'coupon_sent', 'push_sent', 'email_sent'
    action_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_churn_customer ON om_churn_scores(customer_id);
CREATE INDEX IF NOT EXISTS idx_churn_risk ON om_churn_scores(risk_level, score DESC);
