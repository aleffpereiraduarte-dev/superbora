-- NF-e / NFC-e Invoice System
-- Migration 041: Invoice tables and partner fiscal configuration

-- Partner invoices (NF-e, NFC-e, NFS-e)
CREATE TABLE IF NOT EXISTS om_partner_invoices (
    invoice_id SERIAL PRIMARY KEY,
    partner_id INT NOT NULL,
    order_id INT,
    invoice_type VARCHAR(10) DEFAULT 'nfce', -- 'nfe', 'nfce', 'nfse'
    status VARCHAR(20) DEFAULT 'pending', -- 'pending', 'processing', 'authorized', 'cancelled', 'error'
    external_id VARCHAR(100), -- NFE.io or SEFAZ ID
    access_key VARCHAR(50), -- Chave de acesso (44 digits)
    number INT, -- Invoice number
    series INT DEFAULT 1,
    xml_url TEXT,
    pdf_url TEXT,
    total_amount DECIMAL(10,2),
    tax_amount DECIMAL(10,2),
    customer_cpf VARCHAR(14),
    customer_name VARCHAR(200),
    items_json TEXT, -- JSON array of invoice items
    error_message TEXT,
    issued_at TIMESTAMP,
    cancelled_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_invoices_partner ON om_partner_invoices(partner_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_invoices_order ON om_partner_invoices(order_id);
CREATE INDEX IF NOT EXISTS idx_invoices_status ON om_partner_invoices(status);
CREATE INDEX IF NOT EXISTS idx_invoices_external ON om_partner_invoices(external_id);

-- Partner fiscal config
CREATE TABLE IF NOT EXISTS om_partner_fiscal_config (
    partner_id INT PRIMARY KEY,
    enabled BOOLEAN DEFAULT false,
    auto_emit BOOLEAN DEFAULT false, -- auto-emit on order delivery
    regime VARCHAR(30) DEFAULT 'simples', -- 'mei', 'simples', 'presumido', 'real'
    cnpj VARCHAR(18),
    inscricao_estadual VARCHAR(20),
    inscricao_municipal VARCHAR(20),
    crt INT DEFAULT 1, -- Codigo Regime Tributario (1=Simples, 2=Simples Excesso, 3=Normal)
    cfop VARCHAR(10) DEFAULT '5102', -- CFOP padrao
    ncm_padrao VARCHAR(10) DEFAULT '21069090', -- NCM padrao para alimentos preparados
    nfeio_company_id VARCHAR(100), -- NFE.io company ID for this partner
    certificate_file TEXT, -- A1 certificate path
    certificate_password TEXT,
    updated_at TIMESTAMP DEFAULT NOW()
);
