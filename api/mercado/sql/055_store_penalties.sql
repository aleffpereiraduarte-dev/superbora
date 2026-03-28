-- Store penalty/dispute system
-- Tracks penalties charged to partners for order issues (wrong items, missing items, etc.)
-- Penalties are deducted from weekly commission repasse

CREATE TABLE IF NOT EXISTS om_store_penalties (
    id SERIAL PRIMARY KEY,
    order_id INTEGER NOT NULL,
    partner_id INTEGER NOT NULL,

    -- Who reported
    reported_by VARCHAR(50) NOT NULL, -- 'customer', 'driver', 'admin', 'system'
    reported_by_id INTEGER,

    -- Issue
    category VARCHAR(50) NOT NULL,
    -- Categories: 'wrong_items', 'missing_items', 'bad_quality', 'late_preparation',
    -- 'wrong_order', 'expired_food', 'unhygienic', 'store_cancelled', 'false_stock', 'other'

    severity VARCHAR(20) DEFAULT 'medium', -- 'low', 'medium', 'high', 'critical'
    title VARCHAR(255) NOT NULL,
    description TEXT,
    photos JSONB DEFAULT '[]',

    -- Financial
    order_total NUMERIC(10,2),
    penalty_amount NUMERIC(10,2) NOT NULL, -- What we charge the store
    refund_to_customer NUMERIC(10,2) DEFAULT 0, -- What we refund the customer
    deducted_from_repasse BOOLEAN DEFAULT false, -- Already deducted from weekly repasse?
    deducted_at TIMESTAMP,
    repasse_period VARCHAR(50), -- e.g. '2026-03-24/2026-03-30'

    -- Status: opened -> confirmed -> deducted / disputed -> resolved
    status VARCHAR(30) DEFAULT 'opened',
    store_response TEXT, -- Store can dispute
    store_disputed BOOLEAN DEFAULT false,
    resolution TEXT,
    resolved_by INTEGER,
    resolved_at TIMESTAMP,

    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_store_penalties_partner ON om_store_penalties(partner_id);
CREATE INDEX IF NOT EXISTS idx_store_penalties_order ON om_store_penalties(order_id);
CREATE INDEX IF NOT EXISTS idx_store_penalties_status ON om_store_penalties(status);
CREATE INDEX IF NOT EXISTS idx_store_penalties_created ON om_store_penalties(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_store_penalties_deducted ON om_store_penalties(deducted_from_repasse) WHERE deducted_from_repasse = false;

-- Penalty rules/config per category
CREATE TABLE IF NOT EXISTS om_store_penalty_rules (
    id SERIAL PRIMARY KEY,
    category VARCHAR(50) UNIQUE NOT NULL,
    label VARCHAR(100) NOT NULL,
    default_penalty_percent NUMERIC(5,2), -- % of order total
    default_penalty_fixed NUMERIC(10,2), -- OR fixed amount
    penalty_type VARCHAR(20) DEFAULT 'percent', -- 'percent', 'fixed', 'full_order'
    refund_customer BOOLEAN DEFAULT true, -- Also refund customer?
    auto_apply BOOLEAN DEFAULT false, -- Auto-apply without admin review?
    description TEXT,
    active BOOLEAN DEFAULT true
);

-- Insert default penalty rules
INSERT INTO om_store_penalty_rules (category, label, default_penalty_percent, default_penalty_fixed, penalty_type, refund_customer, auto_apply, description) VALUES
('wrong_items', 'Itens errados', 100, 0, 'full_order', true, false, 'Loja enviou itens diferentes do pedido'),
('missing_items', 'Itens faltando', 50, 0, 'percent', true, false, 'Pedido incompleto — faltam itens'),
('bad_quality', 'Qualidade ruim', 30, 0, 'percent', true, false, 'Comida fria, mal preparada ou fora do padrao'),
('late_preparation', 'Preparo atrasado', 0, 5.00, 'fixed', false, true, 'Loja demorou mais de 30min alem do estimado'),
('wrong_order', 'Pedido trocado', 100, 0, 'full_order', true, false, 'Loja enviou pedido de outro cliente'),
('expired_food', 'Comida vencida', 100, 0, 'full_order', true, false, 'Produto com validade expirada — grave'),
('unhygienic', 'Higiene', 100, 0, 'full_order', true, false, 'Problema de higiene encontrado no pedido'),
('store_cancelled', 'Loja cancelou', 0, 10.00, 'fixed', false, true, 'Loja cancelou pedido apos aceitar'),
('false_stock', 'Estoque falso', 0, 5.00, 'fixed', false, true, 'Loja aceitou pedido sem ter o produto'),
('other', 'Outro', 0, 0, 'fixed', false, false, 'Outro problema')
ON CONFLICT (category) DO NOTHING;
