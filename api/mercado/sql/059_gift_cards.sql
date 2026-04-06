-- ══════════════════════════════════════════════════════════════════════════════
-- Gift Cards System - Migration
-- Adds missing columns to existing om_gift_cards table
-- ══════════════════════════════════════════════════════════════════════════════

-- Add purchaser_id (maps from purchased_by)
ALTER TABLE om_gift_cards ADD COLUMN IF NOT EXISTS purchaser_id INT;
UPDATE om_gift_cards SET purchaser_id = purchased_by WHERE purchaser_id IS NULL AND purchased_by IS NOT NULL;

-- Add purchaser_name
ALTER TABLE om_gift_cards ADD COLUMN IF NOT EXISTS purchaser_name VARCHAR(255);

-- Add recipient_name
ALTER TABLE om_gift_cards ADD COLUMN IF NOT EXISTS recipient_name VARCHAR(255);

-- Add amount (maps from value)
ALTER TABLE om_gift_cards ADD COLUMN IF NOT EXISTS amount NUMERIC(10,2);
UPDATE om_gift_cards SET amount = value WHERE amount IS NULL AND value IS NOT NULL;

-- Add status column (active/redeemed/expired) to replace is_active boolean
ALTER TABLE om_gift_cards ADD COLUMN IF NOT EXISTS status VARCHAR(20) DEFAULT 'active';
UPDATE om_gift_cards SET status = CASE
    WHEN redeemed_by IS NOT NULL THEN 'redeemed'
    WHEN is_active = false THEN 'expired'
    ELSE 'active'
END WHERE status IS NULL OR status = 'active';

-- Add redeemer_id (maps from redeemed_by)
ALTER TABLE om_gift_cards ADD COLUMN IF NOT EXISTS redeemer_id INT;
UPDATE om_gift_cards SET redeemer_id = redeemed_by WHERE redeemer_id IS NULL AND redeemed_by IS NOT NULL;

-- Add redeemed_at
ALTER TABLE om_gift_cards ADD COLUMN IF NOT EXISTS redeemed_at TIMESTAMP;

-- Index for purchaser listing
CREATE INDEX IF NOT EXISTS idx_gift_cards_purchaser ON om_gift_cards(purchaser_id);

-- Index for redeemer listing
CREATE INDEX IF NOT EXISTS idx_gift_cards_redeemer ON om_gift_cards(redeemer_id);

-- Index for expiry cron
CREATE INDEX IF NOT EXISTS idx_gift_cards_active_expires ON om_gift_cards(expires_at) WHERE status = 'active';
