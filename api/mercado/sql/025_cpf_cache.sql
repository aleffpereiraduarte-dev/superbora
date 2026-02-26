-- Cache de consultas CPF via cpfcnpj.com.br
-- Evita consultas repetidas (economia de creditos)

CREATE TABLE IF NOT EXISTS om_cpf_cache (
    cpf VARCHAR(11) PRIMARY KEY,
    nome VARCHAR(255),
    status VARCHAR(20) NOT NULL DEFAULT 'valid',
    api_response JSONB,
    consulted_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_cpf_cache_date ON om_cpf_cache(consulted_at);
