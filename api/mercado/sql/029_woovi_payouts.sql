-- ============================================================
-- 029: Woovi (OpenPix) Payout Integration
-- Tabelas para saques PIX automaticos e manuais dos parceiros
-- ============================================================

-- Configuracao de payout por parceiro (se ja nao existir da payout-config.php)
CREATE TABLE IF NOT EXISTS om_payout_config (
    id SERIAL PRIMARY KEY,
    partner_id INT NOT NULL UNIQUE,
    payout_frequency VARCHAR(20) DEFAULT 'weekly',
    payout_day INT DEFAULT 5,
    min_payout NUMERIC(10,2) DEFAULT 50.00,
    bank_name VARCHAR(100),
    bank_agency VARCHAR(20),
    bank_account VARCHAR(30),
    bank_account_type VARCHAR(20) DEFAULT 'checking',
    pix_key VARCHAR(100),
    pix_key_type VARCHAR(20),
    pix_key_validated BOOLEAN DEFAULT FALSE,
    pix_key_validated_at TIMESTAMP,
    auto_payout BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Registro de payouts via Woovi
CREATE TABLE IF NOT EXISTS om_woovi_payouts (
    id SERIAL PRIMARY KEY,
    partner_id INT NOT NULL,
    correlation_id VARCHAR(100) NOT NULL UNIQUE,
    woovi_transaction_id VARCHAR(100),
    amount_cents INT NOT NULL,
    amount NUMERIC(10,2) NOT NULL,
    pix_key VARCHAR(100) NOT NULL,
    pix_key_type VARCHAR(20) NOT NULL,
    status VARCHAR(30) DEFAULT 'pending',
    type VARCHAR(20) NOT NULL DEFAULT 'manual',
    failure_reason TEXT,
    woovi_raw_response TEXT,
    created_at TIMESTAMP DEFAULT NOW(),
    processed_at TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_woovi_payouts_partner ON om_woovi_payouts(partner_id);
CREATE INDEX IF NOT EXISTS idx_woovi_payouts_status ON om_woovi_payouts(status);
CREATE INDEX IF NOT EXISTS idx_woovi_payouts_correlation ON om_woovi_payouts(correlation_id);
