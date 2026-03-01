-- 037: Pickup-only delivery hours + PIX intents table
-- Adds delivery time window to partners and PIX payment-first flow

-- A) Delivery hours: restrict delivery to specific time windows
-- NULL = delivery available whenever store is open (backward-compatible)
ALTER TABLE om_market_partners
  ADD COLUMN IF NOT EXISTS delivery_start_time VARCHAR(5) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS delivery_end_time VARCHAR(5) DEFAULT NULL;

COMMENT ON COLUMN om_market_partners.delivery_start_time IS 'Delivery window start HH:MM (e.g. 08:00). NULL = no restriction.';
COMMENT ON COLUMN om_market_partners.delivery_end_time IS 'Delivery window end HH:MM (e.g. 20:00). NULL = no restriction.';

-- B) PIX intents: payment-first flow (no order created until PIX is paid)
CREATE TABLE IF NOT EXISTS om_pix_intents (
  intent_id SERIAL PRIMARY KEY,
  customer_id INT NOT NULL,
  amount_cents INT NOT NULL,
  cart_snapshot JSONB NOT NULL,
  correlation_id VARCHAR(100) UNIQUE NOT NULL,
  pix_code TEXT,
  pix_qr_url TEXT,
  status VARCHAR(20) DEFAULT 'pending',
  order_id INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT NOW(),
  paid_at TIMESTAMP DEFAULT NULL,
  expires_at TIMESTAMP DEFAULT (NOW() + INTERVAL '10 minutes')
);

CREATE INDEX IF NOT EXISTS idx_pix_intents_correlation ON om_pix_intents(correlation_id);
CREATE INDEX IF NOT EXISTS idx_pix_intents_customer ON om_pix_intents(customer_id, status);
CREATE INDEX IF NOT EXISTS idx_pix_intents_expires ON om_pix_intents(expires_at) WHERE status = 'pending';
