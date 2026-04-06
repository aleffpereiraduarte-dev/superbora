-- 065: Basket Editing — customer preference for unavailable items
-- Inspired by iFood's "Edicao de Cesta" feature
-- Options: 'substitute' (picker chooses similar), 'contact' (ask customer), 'remove' (remove from order)

-- Global preference per order
ALTER TABLE om_market_orders ADD COLUMN IF NOT EXISTS unavailable_preference VARCHAR(20) DEFAULT 'contact';
COMMENT ON COLUMN om_market_orders.unavailable_preference IS 'Customer preference for unavailable items: substitute, contact, remove';

-- Per-item override (NULL = inherit from order-level preference)
ALTER TABLE om_market_order_items ADD COLUMN IF NOT EXISTS unavailable_preference VARCHAR(20) DEFAULT NULL;
COMMENT ON COLUMN om_market_order_items.unavailable_preference IS 'Per-item preference for unavailable: substitute, contact, remove. NULL = inherit from order';
