-- Migration 033: Disputes system + Express delivery config (PostgreSQL)
-- Creates tables required by partner/disputes.php and partner/express-config.php

-- ═══════════════════════════════════════════
-- 1. ORDER DISPUTES
-- ═══════════════════════════════════════════

CREATE TABLE IF NOT EXISTS om_order_disputes (
    dispute_id SERIAL PRIMARY KEY,
    order_id INT NOT NULL,
    customer_id INT NOT NULL,
    partner_id INT NOT NULL,
    category VARCHAR(50) NOT NULL,
    subcategory VARCHAR(50),
    severity VARCHAR(20) DEFAULT 'medium',
    description TEXT,
    photo_urls TEXT,
    affected_items TEXT,
    status VARCHAR(30) DEFAULT 'open',
    auto_resolved SMALLINT DEFAULT 0,
    requested_amount NUMERIC(10,2) DEFAULT 0,
    approved_amount NUMERIC(10,2) DEFAULT 0,
    credit_amount NUMERIC(10,2) DEFAULT 0,
    compensation_type VARCHAR(30),
    resolution_type VARCHAR(50),
    partner_response TEXT,
    resolution_note TEXT,
    order_total NUMERIC(10,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    resolved_at TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_disputes_partner ON om_order_disputes(partner_id);
CREATE INDEX IF NOT EXISTS idx_disputes_customer ON om_order_disputes(customer_id);
CREATE INDEX IF NOT EXISTS idx_disputes_order ON om_order_disputes(order_id);
CREATE INDEX IF NOT EXISTS idx_disputes_status ON om_order_disputes(status);

-- ═══════════════════════════════════════════
-- 2. DISPUTE TIMELINE
-- ═══════════════════════════════════════════

CREATE TABLE IF NOT EXISTS om_dispute_timeline (
    timeline_id SERIAL PRIMARY KEY,
    dispute_id INT NOT NULL REFERENCES om_order_disputes(dispute_id) ON DELETE CASCADE,
    action VARCHAR(50) NOT NULL,
    actor_type VARCHAR(20) NOT NULL,
    actor_id INT,
    description TEXT,
    created_at TIMESTAMP DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_timeline_dispute ON om_dispute_timeline(dispute_id);

-- ═══════════════════════════════════════════
-- 3. DISPUTE EVIDENCE
-- ═══════════════════════════════════════════

CREATE TABLE IF NOT EXISTS om_dispute_evidence (
    evidence_id SERIAL PRIMARY KEY,
    dispute_id INT NOT NULL REFERENCES om_order_disputes(dispute_id) ON DELETE CASCADE,
    order_id INT DEFAULT 0,
    customer_id INT DEFAULT 0,
    photo_url VARCHAR(500) NOT NULL,
    file_size INT DEFAULT 0,
    caption VARCHAR(255),
    created_at TIMESTAMP DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_evidence_dispute ON om_dispute_evidence(dispute_id);

-- ═══════════════════════════════════════════
-- 4. EXPRESS DELIVERY CONFIG
-- ═══════════════════════════════════════════

CREATE TABLE IF NOT EXISTS om_express_delivery_config (
    id SERIAL PRIMARY KEY,
    partner_id INT UNIQUE,
    enabled SMALLINT DEFAULT 1,
    express_fee NUMERIC(10,2) DEFAULT 9.90,
    express_time_minutes INT DEFAULT 20,
    normal_time_minutes INT DEFAULT 45,
    max_distance_km NUMERIC(5,2) DEFAULT 5.00,
    available_from TIME DEFAULT '10:00:00',
    available_until TIME DEFAULT '22:00:00',
    max_orders_per_hour INT DEFAULT 10,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Insert global default config
INSERT INTO om_express_delivery_config (partner_id, enabled, express_fee, express_time_minutes)
VALUES (NULL, 1, 9.90, 20)
ON CONFLICT DO NOTHING;
