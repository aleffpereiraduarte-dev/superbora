-- =====================================================
-- 064: Review Photos with Cashback Credits
-- Add missing columns to om_review_photos for photo
-- review feature with cashback incentive
-- =====================================================

-- Add columns if they don't exist (idempotent)
ALTER TABLE om_review_photos ADD COLUMN IF NOT EXISTS order_id INTEGER;
ALTER TABLE om_review_photos ADD COLUMN IF NOT EXISTS customer_id INTEGER;
ALTER TABLE om_review_photos ADD COLUMN IF NOT EXISTS photo_path VARCHAR(500);
ALTER TABLE om_review_photos ADD COLUMN IF NOT EXISTS thumbnail_url VARCHAR(500);
ALTER TABLE om_review_photos ADD COLUMN IF NOT EXISTS caption VARCHAR(255);
ALTER TABLE om_review_photos ADD COLUMN IF NOT EXISTS status VARCHAR(20) DEFAULT 'pending';
ALTER TABLE om_review_photos ADD COLUMN IF NOT EXISTS credit_given BOOLEAN DEFAULT false;

-- Ensure created_at has a default
ALTER TABLE om_review_photos ALTER COLUMN created_at SET DEFAULT NOW();

-- Indexes for common queries
CREATE INDEX IF NOT EXISTS idx_review_photos_order ON om_review_photos(order_id);
CREATE INDEX IF NOT EXISTS idx_review_photos_status ON om_review_photos(status);
CREATE INDEX IF NOT EXISTS idx_review_photos_customer ON om_review_photos(customer_id);

-- Composite index for approved photos per partner (used by public listing)
CREATE INDEX IF NOT EXISTS idx_review_photos_status_order ON om_review_photos(status, order_id) WHERE status = 'approved';
