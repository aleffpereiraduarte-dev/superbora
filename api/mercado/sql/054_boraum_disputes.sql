-- 054_boraum_disputes.sql
-- Dispute/ticket system for BoraUm delivery issues
-- Tracks damaged orders, driver cancellations, late deliveries, overcharges, etc.

CREATE TABLE IF NOT EXISTS om_boraum_disputes (
    id SERIAL PRIMARY KEY,
    order_id INTEGER NOT NULL,
    entrega_id INTEGER,
    boraum_delivery_id VARCHAR(100),

    -- Who opened
    opened_by VARCHAR(50) NOT NULL, -- 'customer', 'store', 'admin', 'system'
    opened_by_id INTEGER,

    -- Issue details
    category VARCHAR(50) NOT NULL, -- 'damaged', 'lost', 'late', 'wrong_delivery', 'driver_behavior', 'cancelled_by_driver', 'food_spilled', 'not_delivered', 'overcharged', 'other'
    severity VARCHAR(20) DEFAULT 'medium', -- 'low', 'medium', 'high', 'critical'
    title VARCHAR(255) NOT NULL,
    description TEXT,

    -- Evidence
    photos JSONB DEFAULT '[]', -- array of photo URLs

    -- Financial
    order_total NUMERIC(10,2),
    delivery_cost NUMERIC(10,2),
    refund_requested NUMERIC(10,2),
    refund_approved NUMERIC(10,2),
    refund_paid NUMERIC(10,2),
    who_pays VARCHAR(30), -- 'boraum', 'superbora', 'split', 'customer', 'none'

    -- Status flow: opened -> investigating -> resolved/rejected/escalated
    status VARCHAR(30) DEFAULT 'opened',
    resolution TEXT,
    resolved_at TIMESTAMP,
    resolved_by INTEGER,

    -- BoraUm response
    boraum_ticket_id VARCHAR(100),
    boraum_response TEXT,
    boraum_accepted BOOLEAN,

    -- Timestamps
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_disputes_order ON om_boraum_disputes(order_id);
CREATE INDEX IF NOT EXISTS idx_disputes_status ON om_boraum_disputes(status);
CREATE INDEX IF NOT EXISTS idx_disputes_category ON om_boraum_disputes(category);
CREATE INDEX IF NOT EXISTS idx_disputes_created ON om_boraum_disputes(created_at DESC);

-- Dispute comments/timeline
CREATE TABLE IF NOT EXISTS om_boraum_dispute_comments (
    id SERIAL PRIMARY KEY,
    dispute_id INTEGER NOT NULL REFERENCES om_boraum_disputes(id),
    author_type VARCHAR(30) NOT NULL, -- 'admin', 'customer', 'store', 'boraum', 'system'
    author_id INTEGER,
    author_name VARCHAR(100),
    comment TEXT NOT NULL,
    attachments JSONB DEFAULT '[]',
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_dispute_comments_dispute ON om_boraum_dispute_comments(dispute_id);
