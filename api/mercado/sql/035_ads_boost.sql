-- ══════════════════════════════════════════════════════════════════════════════
-- 035_ads_boost.sql — Boost/Ads System (iFood Ads-inspired)
-- Partner store boost for increased visibility in the marketplace
-- ══════════════════════════════════════════════════════════════════════════════

-- Main boosts table
CREATE TABLE IF NOT EXISTS om_partner_boosts (
    boost_id SERIAL PRIMARY KEY,
    partner_id INT NOT NULL,
    boost_type VARCHAR(30) NOT NULL, -- 'destaque', 'topo', 'banner', 'busca'
    status VARCHAR(20) DEFAULT 'active', -- 'active', 'paused', 'expired', 'cancelled'
    budget_daily DECIMAL(10,2) NOT NULL, -- daily budget in BRL
    budget_spent DECIMAL(10,2) DEFAULT 0, -- spent today
    budget_total DECIMAL(10,2) DEFAULT 0, -- total spent all time
    bid_amount DECIMAL(10,2) DEFAULT 0, -- cost per click/impression
    impressions INT DEFAULT 0,
    clicks INT DEFAULT 0,
    orders_from_boost INT DEFAULT 0,
    revenue_from_boost DECIMAL(10,2) DEFAULT 0,
    target_cities TEXT, -- JSON array of cities, null = all
    target_categories TEXT, -- JSON array of categories, null = all
    start_date DATE NOT NULL,
    end_date DATE,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_boosts_partner ON om_partner_boosts(partner_id, status);
CREATE INDEX IF NOT EXISTS idx_boosts_active ON om_partner_boosts(status, start_date, end_date);

-- Boost transactions (charges/credits/refunds)
CREATE TABLE IF NOT EXISTS om_boost_transactions (
    transaction_id SERIAL PRIMARY KEY,
    boost_id INT NOT NULL REFERENCES om_partner_boosts(boost_id),
    partner_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    transaction_type VARCHAR(20) NOT NULL, -- 'charge', 'refund', 'credit'
    description TEXT,
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_boost_tx_partner ON om_boost_transactions(partner_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_boost_tx_boost ON om_boost_transactions(boost_id);
