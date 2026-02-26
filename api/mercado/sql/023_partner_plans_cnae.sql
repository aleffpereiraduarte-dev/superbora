-- 023_partner_plans_cnae.sql
-- Planos de comissao para parceiros + campos CNAE para validacao de CNPJ

-- Tabela de planos de comissao
CREATE TABLE IF NOT EXISTS om_partner_plans (
    id SERIAL PRIMARY KEY,
    slug VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    commission_rate DECIMAL(5,2) NOT NULL DEFAULT 5.00,
    commission_online_rate DECIMAL(5,2) NOT NULL DEFAULT 8.00,
    uses_platform_delivery SMALLINT NOT NULL DEFAULT 0,
    delivery_commission DECIMAL(5,2) DEFAULT 0.00,
    status SMALLINT NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT NOW()
);

-- Seed: dois planos
INSERT INTO om_partner_plans (slug, name, description, commission_rate, commission_online_rate, uses_platform_delivery, delivery_commission)
VALUES
('basico', 'Plano Basico', 'Entrega propria do parceiro. Comissao 5% (dinheiro) ou 8% (online).', 5.00, 8.00, 0, 0.00),
('premium', 'Plano Premium', 'Entrega via BoraUm. Comissao inclui entregador da plataforma.', 15.00, 18.00, 1, 5.00)
ON CONFLICT (slug) DO NOTHING;

-- Colunas extras em om_market_partners (cadastro parceiro)
ALTER TABLE om_market_partners ADD COLUMN IF NOT EXISTS razao_social VARCHAR(255);
ALTER TABLE om_market_partners ADD COLUMN IF NOT EXISTS nome_fantasia VARCHAR(255);
ALTER TABLE om_market_partners ADD COLUMN IF NOT EXISTS owner_name VARCHAR(255);
ALTER TABLE om_market_partners ADD COLUMN IF NOT EXISTS owner_cpf VARCHAR(20);
ALTER TABLE om_market_partners ADD COLUMN IF NOT EXISTS owner_phone VARCHAR(20);
ALTER TABLE om_market_partners ADD COLUMN IF NOT EXISTS address_number VARCHAR(20);
ALTER TABLE om_market_partners ADD COLUMN IF NOT EXISTS address_complement VARCHAR(255);
ALTER TABLE om_market_partners ADD COLUMN IF NOT EXISTS bairro VARCHAR(100);
ALTER TABLE om_market_partners ADD COLUMN IF NOT EXISTS registration_step INT DEFAULT 0;
ALTER TABLE om_market_partners ADD COLUMN IF NOT EXISTS terms_accepted_at TIMESTAMP;
ALTER TABLE om_market_partners ADD COLUMN IF NOT EXISTS pix_type VARCHAR(20);
ALTER TABLE om_market_partners ADD COLUMN IF NOT EXISTS cnae_principal VARCHAR(20);
ALTER TABLE om_market_partners ADD COLUMN IF NOT EXISTS cnae_descricao VARCHAR(255);
ALTER TABLE om_market_partners ADD COLUMN IF NOT EXISTS cnpj_situacao VARCHAR(30);
ALTER TABLE om_market_partners ADD COLUMN IF NOT EXISTS plan_id INT REFERENCES om_partner_plans(id);

-- Parceiros existentes recebem plano basico por padrao
UPDATE om_market_partners SET plan_id = 1 WHERE plan_id IS NULL;

-- Colunas de comissao snapshot no pedido
ALTER TABLE om_market_orders ADD COLUMN IF NOT EXISTS partner_plan_slug VARCHAR(50);
ALTER TABLE om_market_orders ADD COLUMN IF NOT EXISTS commission_rate DECIMAL(5,2);
ALTER TABLE om_market_orders ADD COLUMN IF NOT EXISTS commission_amount DECIMAL(10,2);
ALTER TABLE om_market_orders ADD COLUMN IF NOT EXISTS platform_fee DECIMAL(10,2);
ALTER TABLE om_market_orders ADD COLUMN IF NOT EXISTS tip_amount DECIMAL(10,2);
ALTER TABLE om_market_orders ADD COLUMN IF NOT EXISTS coupon_id INT;
ALTER TABLE om_market_orders ADD COLUMN IF NOT EXISTS coupon_discount DECIMAL(10,2);
ALTER TABLE om_market_orders ADD COLUMN IF NOT EXISTS is_scheduled SMALLINT DEFAULT 0;
ALTER TABLE om_market_orders ADD COLUMN IF NOT EXISTS is_pickup SMALLINT DEFAULT 0;
ALTER TABLE om_market_orders ADD COLUMN IF NOT EXISTS items_count INT DEFAULT 0;
ALTER TABLE om_market_orders ADD COLUMN IF NOT EXISTS partner_name VARCHAR(255);
ALTER TABLE om_market_orders ADD COLUMN IF NOT EXISTS partner_categoria VARCHAR(50);
ALTER TABLE om_market_orders ADD COLUMN IF NOT EXISTS forma_pagamento VARCHAR(20);
ALTER TABLE om_market_orders ADD COLUMN IF NOT EXISTS codigo_entrega VARCHAR(20);
ALTER TABLE om_market_orders ADD COLUMN IF NOT EXISTS shipping_lat NUMERIC(10,7);
ALTER TABLE om_market_orders ADD COLUMN IF NOT EXISTS shipping_lng NUMERIC(10,7);
ALTER TABLE om_market_orders ADD COLUMN IF NOT EXISTS delivery_instructions VARCHAR(500);
ALTER TABLE om_market_orders ADD COLUMN IF NOT EXISTS contactless SMALLINT DEFAULT 0;
