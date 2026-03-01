-- 038_campaigns.sql
-- Sistema de campanhas promocionais com QR code anti-fraude
-- Campanha inicial: Cachorro-quente gratis em Governador Valadares (5 maio 2026)

-- ─── Tabela de campanhas ───
CREATE TABLE IF NOT EXISTS om_campaigns (
    campaign_id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) UNIQUE,
    description TEXT,
    city VARCHAR(100) NOT NULL,
    state VARCHAR(2) NOT NULL,
    reward_text VARCHAR(200) NOT NULL,
    banner_title VARCHAR(100) NOT NULL,
    banner_subtitle VARCHAR(200),
    banner_gradient JSONB DEFAULT '["#FF6B00","#E65100"]',
    banner_icon VARCHAR(50) DEFAULT 'Gift',
    max_redemptions INT DEFAULT 500,
    current_redemptions INT DEFAULT 0,
    qr_secret VARCHAR(64) NOT NULL,
    admin_pin VARCHAR(10) NOT NULL,
    qr_rotation_seconds INT DEFAULT 30,
    qr_validity_seconds INT DEFAULT 60,
    new_customers_only BOOLEAN DEFAULT true,
    start_date TIMESTAMP NOT NULL,
    end_date TIMESTAMP NOT NULL,
    status VARCHAR(20) DEFAULT 'active',
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_campaigns_city_status
    ON om_campaigns(city, status);

-- ─── Tabela de resgates (1 por pessoa por campanha) ───
CREATE TABLE IF NOT EXISTS om_campaign_redemptions (
    redemption_id SERIAL PRIMARY KEY,
    campaign_id INT NOT NULL REFERENCES om_campaigns(campaign_id),
    customer_id INT NOT NULL,
    customer_name VARCHAR(100),
    customer_phone VARCHAR(20),
    redemption_code VARCHAR(10) NOT NULL,
    qr_timestamp BIGINT NOT NULL,
    redeemed_at TIMESTAMP DEFAULT NOW(),
    ip_address VARCHAR(45),
    UNIQUE(campaign_id, customer_id)
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_redemption_code
    ON om_campaign_redemptions(redemption_code);

-- ─── Tabela de tentativas (forensics + rate limit) ───
CREATE TABLE IF NOT EXISTS om_campaign_qr_attempts (
    attempt_id SERIAL PRIMARY KEY,
    campaign_id INT NOT NULL,
    customer_id INT NOT NULL,
    attempted_at TIMESTAMP DEFAULT NOW(),
    success BOOLEAN DEFAULT false,
    failure_reason VARCHAR(50)
);

CREATE INDEX IF NOT EXISTS idx_qr_attempts_customer
    ON om_campaign_qr_attempts(customer_id, attempted_at);

-- ─── Seed: campanha Governador Valadares ───
INSERT INTO om_campaigns (
    name, slug, description, city, state,
    reward_text, banner_title, banner_subtitle,
    banner_gradient, banner_icon,
    max_redemptions, qr_secret, admin_pin,
    new_customers_only,
    start_date, end_date
) VALUES (
    'Cachorro-quente gratis GV',
    'hotdog-gv-2026',
    'Baixou o SuperBora? Venha ao nosso evento em Governador Valadares e retire seu cachorro-quente gratis! Valido apenas para novos clientes.',
    'Governador Valadares', 'MG',
    'Cachorro-quente gratis',
    'Cachorro-quente GRATIS!',
    'Baixou o app? Venha retirar o seu!',
    '["#FF6B00","#E65100"]',
    'Gift',
    500,
    md5(random()::text || now()::text),
    'SB2026',
    true,
    '2026-05-05 08:00:00',
    '2026-05-05 22:00:00'
) ON CONFLICT (slug) DO NOTHING;
