-- ═══════════════════════════════════════════════════════════════════════════
-- 052: Enhanced Referral System ("Indique e Ganhe")
-- ═══════════════════════════════════════════════════════════════════════════
-- Upgrades om_referrals with proper columns, indexes, and constraints
-- for the iFood-style referral program.
--
-- Rewards:
--   Referrer: R$10 cashback when referred friend completes first order
--   Referred: R$15 discount on first order (auto-coupon)
--   Bonus: R$5 extra for every 5th completed referral
--
-- Status flow: pending -> completed | expired (30 days no order)
-- ═══════════════════════════════════════════════════════════════════════════

-- Add missing columns to om_referrals if they don't exist
DO $$ BEGIN
    -- completed_at: when the referred friend completed first order
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='om_referrals' AND column_name='completed_at') THEN
        ALTER TABLE om_referrals ADD COLUMN completed_at TIMESTAMP NULL;
    END IF;

    -- bonus_amount: extra bonus earned (e.g. every 5th referral)
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='om_referrals' AND column_name='bonus_amount') THEN
        ALTER TABLE om_referrals ADD COLUMN bonus_amount NUMERIC(10,2) DEFAULT 0;
    END IF;

    -- referred_order_id: the first order made by referred friend
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='om_referrals' AND column_name='referred_order_id') THEN
        ALTER TABLE om_referrals ADD COLUMN referred_order_id INTEGER NULL;
    END IF;

    -- Ensure created_at has a default
    ALTER TABLE om_referrals ALTER COLUMN created_at SET DEFAULT NOW();

    -- Ensure default for status
    ALTER TABLE om_referrals ALTER COLUMN status SET DEFAULT 'pending';

    -- Ensure default for rewards
    ALTER TABLE om_referrals ALTER COLUMN referrer_reward SET DEFAULT 0;
    ALTER TABLE om_referrals ALTER COLUMN referred_reward SET DEFAULT 0;
END $$;

-- Index for looking up by referred_id (check if someone was already referred)
CREATE INDEX IF NOT EXISTS idx_referral_referred_id ON om_referrals(referred_id);

-- Index for code lookup when code has no referred_id yet (available codes)
CREATE INDEX IF NOT EXISTS idx_referral_code_available ON om_referrals(referral_code) WHERE referred_id IS NULL;

-- Index for expiry cron (pending referrals older than 30 days)
CREATE INDEX IF NOT EXISTS idx_referral_pending_expiry ON om_referrals(status, created_at) WHERE status = 'pending' AND referred_id IS NOT NULL;

-- Referral coupons table: auto-generated discount for referred friends
CREATE TABLE IF NOT EXISTS om_referral_coupons (
    id SERIAL PRIMARY KEY,
    referral_id INTEGER NOT NULL,
    referred_id INTEGER NOT NULL,
    coupon_code VARCHAR(30) NOT NULL,
    discount_amount NUMERIC(10,2) DEFAULT 15.00,
    used BOOLEAN DEFAULT false,
    used_at TIMESTAMP NULL,
    order_id INTEGER NULL,
    expires_at TIMESTAMP DEFAULT (NOW() + INTERVAL '30 days'),
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_referral_coupon_code ON om_referral_coupons(coupon_code);
CREATE INDEX IF NOT EXISTS idx_referral_coupon_referred ON om_referral_coupons(referred_id);
CREATE INDEX IF NOT EXISTS idx_referral_coupon_referral ON om_referral_coupons(referral_id);
