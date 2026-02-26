-- ============================================================================
-- Migration 002: Cashback Idempotency Constraints
-- SuperBora Marketplace - PostgreSQL
-- Run: psql -U $DB_USER -d $DB_NAME -f 002_cashback_idempotency.sql
-- ============================================================================
-- Prevents duplicate credit/debit/refund operations on the same order via
-- partial unique indexes. Required by INSERT ... ON CONFLICT patterns in
-- helpers/cashback.php.
-- ============================================================================

BEGIN;

-- Only one active credit per order
CREATE UNIQUE INDEX IF NOT EXISTS idx_cashback_tx_credit_per_order
    ON om_cashback_transactions (order_id)
    WHERE type = 'credit' AND expired = 0;

-- Only one active debit per order
CREATE UNIQUE INDEX IF NOT EXISTS idx_cashback_tx_debit_per_order
    ON om_cashback_transactions (order_id)
    WHERE type = 'debit' AND expired = 0;

-- Only one refund record per order (type='expired' with amount=0 is the refund marker)
CREATE UNIQUE INDEX IF NOT EXISTS idx_cashback_tx_refund_per_order
    ON om_cashback_transactions (order_id)
    WHERE type = 'expired' AND amount = 0;

COMMIT;
