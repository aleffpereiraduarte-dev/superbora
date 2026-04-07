-- Encryption-at-rest schema additions for sensitive PII (LGPD/PCI compliance).
-- The actual encryption happens in PHP via helpers/encryption.php (AES-256-GCM).
--
-- Strategy: keep the existing plaintext columns for backward compatibility,
-- add `*_enc` columns to hold ciphertext, and a hashed lookup column for queries.
-- A migration script then encrypts existing rows and (optionally) nulls plaintext.

-- ========== Customers ==========
ALTER TABLE om_customers
    ADD COLUMN IF NOT EXISTS cpf_enc TEXT,
    ADD COLUMN IF NOT EXISTS cpf_hash CHAR(64),
    ADD COLUMN IF NOT EXISTS rg_enc TEXT,
    ADD COLUMN IF NOT EXISTS birth_date_enc TEXT;

CREATE INDEX IF NOT EXISTS idx_customers_cpf_hash ON om_customers(cpf_hash) WHERE cpf_hash IS NOT NULL;

-- ========== Saved cards ==========
-- om_customer_cards already stores last4/brand from Stripe; we don't store full PAN.
-- Add encryption for cardholder name and billing address.
ALTER TABLE om_customer_cards
    ADD COLUMN IF NOT EXISTS holder_name_enc TEXT,
    ADD COLUMN IF NOT EXISTS billing_address_enc TEXT;

-- ========== Addresses ==========
-- Encrypt the full address details (street + number + complement) — not city/state/CEP
-- so we can still do geo queries.
ALTER TABLE om_customer_addresses
    ADD COLUMN IF NOT EXISTS street_enc TEXT,
    ADD COLUMN IF NOT EXISTS number_enc TEXT,
    ADD COLUMN IF NOT EXISTS complement_enc TEXT;

-- ========== Audit log of encryption events ==========
CREATE TABLE IF NOT EXISTS om_encryption_audit (
    id BIGSERIAL PRIMARY KEY,
    table_name VARCHAR(50) NOT NULL,
    column_name VARCHAR(50) NOT NULL,
    row_id BIGINT NOT NULL,
    action VARCHAR(20) NOT NULL, -- 'encrypt', 'decrypt', 'rotate', 'fail'
    actor VARCHAR(50),
    error_message TEXT,
    created_at TIMESTAMPTZ DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_enc_audit_table_row ON om_encryption_audit(table_name, row_id, created_at);
