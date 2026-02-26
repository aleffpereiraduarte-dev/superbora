-- ============================================================================
-- Migration 001: Indexes, Foreign Keys, and Constraints
-- SuperBora Marketplace - PostgreSQL
-- Run: psql -U $DB_USER -d $DB_NAME -f 001_indexes_constraints.sql
-- ============================================================================

BEGIN;

-- ============================================================================
-- 1. PERFORMANCE INDEXES
-- ============================================================================

-- om_market_orders: Most queried table
CREATE INDEX IF NOT EXISTS idx_orders_customer_created
    ON om_market_orders (customer_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_orders_partner_status
    ON om_market_orders (partner_id, status);

CREATE INDEX IF NOT EXISTS idx_orders_status_created
    ON om_market_orders (status, created_at);

-- Stripe payment intent uniqueness (prevent duplicate charges)
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_indexes
        WHERE indexname = 'idx_orders_stripe_intent_unique'
    ) THEN
        CREATE UNIQUE INDEX idx_orders_stripe_intent_unique
            ON om_market_orders (stripe_payment_intent_id)
            WHERE stripe_payment_intent_id IS NOT NULL;
    END IF;
END $$;

-- om_cashback_transactions: Heavy reads on customer dashboards
CREATE INDEX IF NOT EXISTS idx_cashback_tx_customer_type_date
    ON om_cashback_transactions (customer_id, type, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_cashback_tx_expires
    ON om_cashback_transactions (expires_at, expired)
    WHERE expired = false;

CREATE INDEX IF NOT EXISTS idx_cashback_tx_order
    ON om_cashback_transactions (order_id);

-- om_market_cart: Cart lookups
CREATE INDEX IF NOT EXISTS idx_cart_customer_partner
    ON om_market_cart (customer_id, partner_id)
    WHERE customer_id IS NOT NULL AND customer_id > 0;

CREATE INDEX IF NOT EXISTS idx_cart_session_partner
    ON om_market_cart (session_id, partner_id)
    WHERE session_id IS NOT NULL AND session_id != '';

-- om_delivery_locations: Real-time tracking queries
CREATE INDEX IF NOT EXISTS idx_delivery_locations_order_date
    ON om_delivery_locations (order_id, created_at DESC);

-- om_recurring_orders: Cron job lookups
CREATE INDEX IF NOT EXISTS idx_recurring_next_status
    ON om_recurring_orders (next_order_at, status)
    WHERE status = 'active';

-- om_repasses: Cron auto-release lookups
CREATE INDEX IF NOT EXISTS idx_repasses_hold_until
    ON om_repasses (status, hold_until)
    WHERE status = 'hold';

CREATE INDEX IF NOT EXISTS idx_repasses_order
    ON om_repasses (order_id, order_type);

CREATE INDEX IF NOT EXISTS idx_repasses_destinatario
    ON om_repasses (tipo, destinatario_id, status);

-- om_market_notifications: Customer notification screen
CREATE INDEX IF NOT EXISTS idx_notifications_recipient
    ON om_market_notifications (recipient_id, recipient_type, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_notifications_unread
    ON om_market_notifications (recipient_id, recipient_type, is_read)
    WHERE is_read = false;

-- om_market_push_tokens: Push notification dispatch
CREATE INDEX IF NOT EXISTS idx_push_tokens_user
    ON om_market_push_tokens (user_id, user_type);

-- om_market_coupons: Coupon lookup
CREATE INDEX IF NOT EXISTS idx_coupons_code_status
    ON om_market_coupons (code, status);

-- om_market_coupon_usage: Usage counting
CREATE INDEX IF NOT EXISTS idx_coupon_usage_coupon_customer
    ON om_market_coupon_usage (coupon_id, customer_id);

-- om_favorites: Customer favorites
CREATE INDEX IF NOT EXISTS idx_favorites_customer
    ON om_favorites (customer_id);

-- om_cashback_wallet: Balance lookups
CREATE INDEX IF NOT EXISTS idx_cashback_wallet_customer
    ON om_cashback_wallet (customer_id);

-- om_subscriptions: Active subscription queries
CREATE INDEX IF NOT EXISTS idx_subscriptions_customer_status
    ON om_subscriptions (customer_id, status)
    WHERE status IN ('active', 'trial');


-- ============================================================================
-- 2. FOREIGN KEYS (with CASCADE where appropriate)
-- ============================================================================

-- Cart item extras cascade with cart deletion
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE constraint_name = 'fk_cart_extras_cart'
    ) THEN
        ALTER TABLE om_cart_item_extras
            ADD CONSTRAINT fk_cart_extras_cart
            FOREIGN KEY (cart_id)
            REFERENCES om_market_cart (id)
            ON DELETE CASCADE;
    END IF;
EXCEPTION WHEN OTHERS THEN
    RAISE NOTICE 'FK fk_cart_extras_cart skipped: %', SQLERRM;
END $$;

-- Smart recommendations cascade with customer deletion
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE constraint_name = 'fk_smart_recs_customer'
    ) THEN
        ALTER TABLE om_smart_recommendations
            ADD CONSTRAINT fk_smart_recs_customer
            FOREIGN KEY (customer_id)
            REFERENCES om_market_customers (customer_id)
            ON DELETE CASCADE;
    END IF;
EXCEPTION WHEN OTHERS THEN
    RAISE NOTICE 'FK fk_smart_recs_customer skipped: %', SQLERRM;
END $$;

-- Age verifications cascade with customer deletion
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE constraint_name = 'fk_age_verif_customer'
    ) THEN
        ALTER TABLE om_age_verifications
            ADD CONSTRAINT fk_age_verif_customer
            FOREIGN KEY (customer_id)
            REFERENCES om_market_customers (customer_id)
            ON DELETE CASCADE;
    END IF;
EXCEPTION WHEN OTHERS THEN
    RAISE NOTICE 'FK fk_age_verif_customer skipped: %', SQLERRM;
END $$;


-- ============================================================================
-- 3. MISSING COLUMNS
-- ============================================================================

-- CPF na nota (for checkout)
ALTER TABLE om_market_orders
    ADD COLUMN IF NOT EXISTS cpf_nota VARCHAR(11);

-- Account deletion tracking
CREATE TABLE IF NOT EXISTS om_account_deletions (
    id SERIAL PRIMARY KEY,
    customer_id INTEGER NOT NULL,
    reason TEXT,
    email_backup VARCHAR(255),
    phone_backup VARCHAR(20),
    status VARCHAR(20) DEFAULT 'pending',
    completed_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_account_deletions_customer
    ON om_account_deletions (customer_id, status);


-- ============================================================================
-- 4. REPASSE SYSTEM TABLES (ensure they exist)
-- ============================================================================

CREATE TABLE IF NOT EXISTS om_repasses_config (
    chave VARCHAR(50) PRIMARY KEY,
    valor TEXT NOT NULL
);

-- Insert default config values if not present
INSERT INTO om_repasses_config (chave, valor) VALUES
    ('hold_horas', '2'),
    ('notificar_liberacao', 'true'),
    ('notificar_cancelamento', 'true')
ON CONFLICT (chave) DO NOTHING;

CREATE TABLE IF NOT EXISTS om_repasses_log (
    id SERIAL PRIMARY KEY,
    repasse_id INTEGER NOT NULL,
    status_anterior VARCHAR(30),
    status_novo VARCHAR(30) NOT NULL,
    executado_por_tipo VARCHAR(20),
    executado_por_id INTEGER,
    observacao TEXT,
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_repasses_log_repasse
    ON om_repasses_log (repasse_id, created_at DESC);


-- ============================================================================
-- 5. NOT NULL CONSTRAINTS (safe — only if column has no NULLs)
-- ============================================================================

-- Ensure critical order columns are not null
DO $$
BEGIN
    -- Only apply if no NULL values exist
    IF NOT EXISTS (
        SELECT 1 FROM om_market_orders
        WHERE customer_id IS NULL OR partner_id IS NULL OR subtotal IS NULL
        LIMIT 1
    ) THEN
        ALTER TABLE om_market_orders ALTER COLUMN customer_id SET NOT NULL;
        ALTER TABLE om_market_orders ALTER COLUMN partner_id SET NOT NULL;
        ALTER TABLE om_market_orders ALTER COLUMN subtotal SET NOT NULL;
    END IF;
EXCEPTION WHEN OTHERS THEN
    RAISE NOTICE 'NOT NULL constraints on orders skipped: %', SQLERRM;
END $$;


COMMIT;

-- ============================================================================
-- VERIFY
-- ============================================================================
-- Run after migration to verify:
-- SELECT indexname FROM pg_indexes WHERE tablename = 'om_market_orders';
-- SELECT conname FROM pg_constraint WHERE conrelid = 'om_cart_item_extras'::regclass;
