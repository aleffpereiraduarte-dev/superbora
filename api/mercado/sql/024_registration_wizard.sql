-- 024: Registration wizard + contrato + setup
-- Novas colunas para suporte a CPF, wizard de cadastro, contrato digital e setup

-- Suporte CPF alem de CNPJ
ALTER TABLE om_market_partners ADD COLUMN IF NOT EXISTS document_type VARCHAR(10) DEFAULT 'cnpj';
ALTER TABLE om_market_partners ADD COLUMN IF NOT EXISTS cpf VARCHAR(14);
ALTER TABLE om_market_partners ADD COLUMN IF NOT EXISTS cpf_expiry_date DATE;

-- Display name e especialidade
ALTER TABLE om_market_partners ADD COLUMN IF NOT EXISTS display_name VARCHAR(255);
ALTER TABLE om_market_partners ADD COLUMN IF NOT EXISTS specialty VARCHAR(100);

-- Coordenadas (se nao existem)
ALTER TABLE om_market_partners ADD COLUMN IF NOT EXISTS lat DECIMAL(10,7);
ALTER TABLE om_market_partners ADD COLUMN IF NOT EXISTS lng DECIMAL(10,7);

-- Contrato digital
ALTER TABLE om_market_partners ADD COLUMN IF NOT EXISTS contract_signed_at TIMESTAMP;
ALTER TABLE om_market_partners ADD COLUMN IF NOT EXISTS contract_ip VARCHAR(45);

-- Setup wizard
ALTER TABLE om_market_partners ADD COLUMN IF NOT EXISTS first_setup_complete SMALLINT DEFAULT 0;

-- Tabela de contratos
CREATE TABLE IF NOT EXISTS om_partner_contracts (
    id SERIAL PRIMARY KEY,
    partner_id INT NOT NULL,
    plan_slug VARCHAR(50),
    contract_text TEXT NOT NULL,
    signed_at TIMESTAMP,
    signer_name VARCHAR(255),
    signer_document VARCHAR(20),
    signer_ip VARCHAR(45),
    signer_user_agent TEXT,
    status VARCHAR(20) DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT NOW()
);

-- Indice para buscar contratos por parceiro
CREATE INDEX IF NOT EXISTS idx_partner_contracts_partner ON om_partner_contracts(partner_id);
