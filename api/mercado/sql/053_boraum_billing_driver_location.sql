-- Migration 053: BoraUm billing table + driver location columns on om_entregas
-- Run: psql -U love1 -d love1 < 053_boraum_billing_driver_location.sql

-- ============================================================================
-- 1. om_boraum_billing - Weekly billing reports for BoraUm deliveries
-- ============================================================================
CREATE TABLE IF NOT EXISTS om_boraum_billing (
    id SERIAL PRIMARY KEY,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    total_deliveries INT NOT NULL DEFAULT 0,
    completed_deliveries INT NOT NULL DEFAULT 0,
    cancelled_deliveries INT NOT NULL DEFAULT 0,
    total_boraum_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_superbora_margin DECIMAL(12,2) NOT NULL DEFAULT 0,
    net_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    raw_api_response JSONB,
    local_summary JSONB,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    paid_at TIMESTAMP,
    notes TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_boraum_billing_period ON om_boraum_billing(period_start, period_end);
CREATE INDEX IF NOT EXISTS idx_boraum_billing_status ON om_boraum_billing(status);

-- ============================================================================
-- 2. Add driver lat/lng columns to om_entregas for real-time location tracking
-- ============================================================================
ALTER TABLE om_entregas ADD COLUMN IF NOT EXISTS motorista_lat DECIMAL(10,7);
ALTER TABLE om_entregas ADD COLUMN IF NOT EXISTS motorista_lng DECIMAL(10,7);

-- ============================================================================
-- 3. Ensure om_delivery_tracking table exists (PostgreSQL version)
--    Used by webhook to store location history
-- ============================================================================
CREATE TABLE IF NOT EXISTS om_delivery_tracking (
    id SERIAL PRIMARY KEY,
    delivery_id INT NOT NULL,
    order_id INT NOT NULL,
    latitude DECIMAL(10,7) NOT NULL,
    longitude DECIMAL(10,7) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_delivery_tracking_delivery ON om_delivery_tracking(delivery_id);
CREATE INDEX IF NOT EXISTS idx_delivery_tracking_order ON om_delivery_tracking(order_id);
CREATE INDEX IF NOT EXISTS idx_delivery_tracking_created ON om_delivery_tracking(created_at);
