-- ═══════════════════════════════════════════════════════════════════════════
-- 046: Referral System for SuperBora WhatsApp Bot
-- ═══════════════════════════════════════════════════════════════════════════
--
-- Uses existing tables: referral_codes, referral_invites
-- This migration adds missing indexes and ensures constraints.
--
-- referral_codes: stores unique referral codes per customer (user_id = customer_id)
-- referral_invites: tracks referral conversions (referrer_id -> referred_id)
--

-- Ensure referral_codes table exists with proper structure
CREATE TABLE IF NOT EXISTS referral_codes (
    id SERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL,
    code VARCHAR(30) NOT NULL,
    uses_count INTEGER DEFAULT 0,
    max_uses INTEGER DEFAULT 0,  -- 0 = unlimited
    active BOOLEAN DEFAULT true,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- Ensure unique constraints
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'referral_codes_user_id_key') THEN
        ALTER TABLE referral_codes ADD CONSTRAINT referral_codes_user_id_key UNIQUE (user_id);
    END IF;
EXCEPTION WHEN others THEN NULL;
END $$;

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'referral_codes_code_key') THEN
        ALTER TABLE referral_codes ADD CONSTRAINT referral_codes_code_key UNIQUE (code);
    END IF;
EXCEPTION WHEN others THEN NULL;
END $$;

-- Ensure referral_invites table exists
CREATE TABLE IF NOT EXISTS referral_invites (
    id SERIAL PRIMARY KEY,
    referrer_id BIGINT NOT NULL,
    referred_id BIGINT,
    code VARCHAR(30) NOT NULL,
    status VARCHAR(20) DEFAULT 'pending',  -- pending, completed, expired
    referrer_reward_brl NUMERIC(10,2) DEFAULT 10.00,
    referred_reward_brl NUMERIC(10,2) DEFAULT 10.00,
    completed_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- Indexes for performance
CREATE INDEX IF NOT EXISTS idx_referral_codes_user_id ON referral_codes(user_id);
CREATE INDEX IF NOT EXISTS idx_referral_codes_code ON referral_codes(code);
CREATE INDEX IF NOT EXISTS idx_referral_codes_active ON referral_codes(active) WHERE active = true;
CREATE INDEX IF NOT EXISTS idx_referral_invites_referrer ON referral_invites(referrer_id);
CREATE INDEX IF NOT EXISTS idx_referral_invites_referred ON referral_invites(referred_id);
CREATE INDEX IF NOT EXISTS idx_referral_invites_code ON referral_invites(code);
CREATE INDEX IF NOT EXISTS idx_referral_invites_status ON referral_invites(status);
