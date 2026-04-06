-- =====================================================
-- SuperBora Club Membership System
-- 3-tier: Club (R$9,90), Club Plus (R$19,90), Club Black (R$39,90)
-- Uses Stripe recurring subscriptions
-- Run: ALTER existing tables + create payments table + insert plans
-- =====================================================

-- Add missing columns to om_membership_plans
ALTER TABLE om_membership_plans ADD COLUMN IF NOT EXISTS icon VARCHAR(50) DEFAULT 'diamond';
ALTER TABLE om_membership_plans ADD COLUMN IF NOT EXISTS color VARCHAR(20) DEFAULT '#f59e0b';
ALTER TABLE om_membership_plans ADD COLUMN IF NOT EXISTS sort_order INT DEFAULT 0;
ALTER TABLE om_membership_plans ADD COLUMN IF NOT EXISTS stripe_price_id VARCHAR(100);
ALTER TABLE om_membership_plans ADD COLUMN IF NOT EXISTS free_delivery_min DECIMAL(10,2) DEFAULT NULL;
ALTER TABLE om_membership_plans ADD COLUMN IF NOT EXISTS cashback_rate DECIMAL(5,2) DEFAULT 0;
ALTER TABLE om_membership_plans ADD COLUMN IF NOT EXISTS priority_support VARCHAR(20) DEFAULT 'normal';
ALTER TABLE om_membership_plans ADD COLUMN IF NOT EXISTS express_discount DECIMAL(5,2) DEFAULT 0;
ALTER TABLE om_membership_plans ADD COLUMN IF NOT EXISTS express_free BOOLEAN DEFAULT FALSE;
ALTER TABLE om_membership_plans ADD COLUMN IF NOT EXISTS no_service_fee BOOLEAN DEFAULT FALSE;
ALTER TABLE om_membership_plans ADD COLUMN IF NOT EXISTS early_access_offers BOOLEAN DEFAULT FALSE;
ALTER TABLE om_membership_plans ADD COLUMN IF NOT EXISTS exclusive_coupons BOOLEAN DEFAULT FALSE;
ALTER TABLE om_membership_plans ADD COLUMN IF NOT EXISTS benefits_json JSONB DEFAULT '[]';
ALTER TABLE om_membership_plans ADD COLUMN IF NOT EXISTS trial_days INT DEFAULT 7;
ALTER TABLE om_membership_plans ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT NOW();

-- Add unique constraint on slug (needed for ON CONFLICT)
-- ALTER TABLE om_membership_plans ADD CONSTRAINT om_membership_plans_slug_key UNIQUE (slug);

-- Add missing columns to om_customer_memberships
ALTER TABLE om_customer_memberships ADD COLUMN IF NOT EXISTS stripe_subscription_id VARCHAR(100);
ALTER TABLE om_customer_memberships ADD COLUMN IF NOT EXISTS stripe_customer_id VARCHAR(100);
ALTER TABLE om_customer_memberships ADD COLUMN IF NOT EXISTS current_period_start TIMESTAMP;
ALTER TABLE om_customer_memberships ADD COLUMN IF NOT EXISTS current_period_end TIMESTAMP;
ALTER TABLE om_customer_memberships ADD COLUMN IF NOT EXISTS trial_end TIMESTAMP;
ALTER TABLE om_customer_memberships ADD COLUMN IF NOT EXISTS cancel_at_period_end BOOLEAN DEFAULT FALSE;
ALTER TABLE om_customer_memberships ADD COLUMN IF NOT EXISTS cancelled_at TIMESTAMP;
ALTER TABLE om_customer_memberships ADD COLUMN IF NOT EXISTS payment_method VARCHAR(50) DEFAULT 'stripe';
ALTER TABLE om_customer_memberships ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT NOW();

-- Indexes
CREATE INDEX IF NOT EXISTS idx_cm_stripe_sub ON om_customer_memberships(stripe_subscription_id);
CREATE INDEX IF NOT EXISTS idx_cm_period_end ON om_customer_memberships(current_period_end);

-- Payments table
CREATE TABLE IF NOT EXISTS om_membership_payments (
    id SERIAL PRIMARY KEY,
    membership_id INTEGER NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    status VARCHAR(20) DEFAULT 'pending',
    stripe_invoice_id VARCHAR(100),
    stripe_payment_intent_id VARCHAR(100),
    paid_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_mp_membership ON om_membership_payments(membership_id);
CREATE INDEX IF NOT EXISTS idx_mp_stripe_invoice ON om_membership_payments(stripe_invoice_id);

-- Deactivate old plans
UPDATE om_membership_plans SET status = 0 WHERE slug NOT IN ('club', 'club_plus', 'club_black');

-- Insert/update the 3 Club plans (use ON CONFLICT if slug unique constraint exists)
-- Club: R$9,90/mes - Frete gratis acima R$30, 3% cashback
-- Club Plus: R$19,90/mes - Frete gratis todos, 5% cashback, suporte prioritario
-- Club Black: R$39,90/mes - Frete gratis, 8% cashback, VIP, sem taxa servico
INSERT INTO om_membership_plans (name, slug, price, duration_months, description, icon, color, sort_order, free_delivery_min, cashback_rate, priority_support, express_discount, express_free, no_service_fee, early_access_offers, exclusive_coupons, trial_days, benefits_json, status, is_family, max_members)
VALUES
    ('Club', 'club', 9.90, 1, 'Frete gratis acima de R$30 + 3% cashback', 'flash-outline', '#00A868', 1, 30.00, 3.00, 'normal', 0, FALSE, FALSE, FALSE, TRUE, 7,
     '[{"text":"Frete gratis em pedidos acima de R$30","icon":"bicycle-outline"},{"text":"3% de cashback em todas as compras","icon":"cash-outline"},{"text":"Cupons exclusivos mensais","icon":"pricetag-outline"},{"text":"Suporte por chat","icon":"chatbubble-outline"}]'::jsonb, 1, 0, 1),
    ('Club Plus', 'club_plus', 19.90, 1, 'Frete gratis em todos + 5% cashback + suporte prioritario', 'diamond-outline', '#d97706', 2, NULL, 5.00, 'priority', 50, FALSE, FALSE, TRUE, TRUE, 7,
     '[{"text":"Frete gratis em TODOS os pedidos","icon":"bicycle-outline"},{"text":"5% de cashback em todas as compras","icon":"cash-outline"},{"text":"Suporte prioritario","icon":"headset-outline"},{"text":"Entrega express com 50% de desconto","icon":"rocket-outline"},{"text":"Acesso antecipado a ofertas","icon":"time-outline"},{"text":"Cupons exclusivos mensais","icon":"pricetag-outline"}]'::jsonb, 1, 0, 1),
    ('Club Black', 'club_black', 39.90, 1, 'Frete e express gratis + 8% cashback + VIP + sem taxa', 'star-outline', '#7c3aed', 3, NULL, 8.00, 'vip', 100, TRUE, TRUE, TRUE, TRUE, 7,
     '[{"text":"Frete gratis em TODOS os pedidos","icon":"bicycle-outline"},{"text":"8% de cashback em todas as compras","icon":"cash-outline"},{"text":"Suporte VIP 24h via WhatsApp","icon":"headset-outline"},{"text":"Entrega express GRATIS","icon":"rocket-outline"},{"text":"Sem taxa de servico","icon":"pricetag-outline"},{"text":"Descontos exclusivos","icon":"gift-outline"},{"text":"Acesso antecipado a ofertas","icon":"time-outline"}]'::jsonb, 1, 0, 1)
ON CONFLICT (slug) DO UPDATE SET
    name = EXCLUDED.name,
    price = EXCLUDED.price,
    description = EXCLUDED.description,
    icon = EXCLUDED.icon,
    color = EXCLUDED.color,
    sort_order = EXCLUDED.sort_order,
    free_delivery_min = EXCLUDED.free_delivery_min,
    cashback_rate = EXCLUDED.cashback_rate,
    priority_support = EXCLUDED.priority_support,
    express_discount = EXCLUDED.express_discount,
    express_free = EXCLUDED.express_free,
    no_service_fee = EXCLUDED.no_service_fee,
    early_access_offers = EXCLUDED.early_access_offers,
    exclusive_coupons = EXCLUDED.exclusive_coupons,
    benefits_json = EXCLUDED.benefits_json,
    trial_days = EXCLUDED.trial_days,
    status = EXCLUDED.status,
    updated_at = NOW();
