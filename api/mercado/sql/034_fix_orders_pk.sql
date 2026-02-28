-- Migration 034: Fix om_market_orders missing PRIMARY KEY
-- The order_id column had no PK/UNIQUE constraint, allowing duplicate IDs.
-- This caused data corruption when the sequence generated IDs that already existed.

-- 1. Remove duplicate rows (keep earliest by ctid)
DELETE FROM om_market_orders a
USING om_market_orders b
WHERE a.order_id = b.order_id
  AND a.ctid > b.ctid;

-- 2. Add PRIMARY KEY
ALTER TABLE om_market_orders
  ADD CONSTRAINT om_market_orders_pkey PRIMARY KEY (order_id);

-- 3. Fix sequence to be at least max(order_id)
SELECT setval('om_market_orders_order_id_seq',
    GREATEST(
        (SELECT COALESCE(MAX(order_id), 0) FROM om_market_orders),
        (SELECT last_value FROM om_market_orders_order_id_seq)
    )
);
