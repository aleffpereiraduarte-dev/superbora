-- Migration 099: Cart performance indexes
-- om_market_cart had NO indexes on customer_id / session_id — every listar.php /
-- adicionar.php / limpar.php was doing a Seq Scan. Harmless when the table has 500
-- rows (sub-ms) but the scan cost grows linearly, and with the expected 50k+ rows
-- across anonymous sessions this turns into the hot query on the app's critical
-- path. Adding the two obvious indexes keeps lookups on an index scan path.
--
-- CREATE INDEX CONCURRENTLY so it doesn't block writes during deploy.
-- Safe to re-run (IF NOT EXISTS).

CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_om_market_cart_customer_id
    ON om_market_cart (customer_id)
    WHERE customer_id IS NOT NULL;

CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_om_market_cart_session_id
    ON om_market_cart (session_id)
    WHERE session_id IS NOT NULL;

-- Composite for the hot path in adicionar.php (customer_id + product_id lookup
-- inside the FOR UPDATE). The partial index on customer_id already helps but
-- this lets the planner skip the filter step entirely.
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_om_market_cart_customer_product
    ON om_market_cart (customer_id, product_id)
    WHERE customer_id IS NOT NULL;
