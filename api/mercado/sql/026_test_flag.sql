-- ====================================================================
-- Migration 026: Add is_test flag for automated testing isolation
-- ====================================================================
-- Rows with is_test=1 are created by the test seed endpoint and can
-- be safely deleted without affecting production data.

ALTER TABLE om_customers ADD COLUMN IF NOT EXISTS is_test SMALLINT DEFAULT 0;
ALTER TABLE om_market_partners ADD COLUMN IF NOT EXISTS is_test SMALLINT DEFAULT 0;
ALTER TABLE om_market_products ADD COLUMN IF NOT EXISTS is_test SMALLINT DEFAULT 0;

-- Partial indexes for fast cleanup (only index rows where is_test=1)
CREATE INDEX IF NOT EXISTS idx_customers_test ON om_customers(is_test) WHERE is_test = 1;
CREATE INDEX IF NOT EXISTS idx_partners_test ON om_market_partners(is_test) WHERE is_test = 1;
CREATE INDEX IF NOT EXISTS idx_products_test ON om_market_products(is_test) WHERE is_test = 1;
