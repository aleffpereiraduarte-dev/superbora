-- 063_smart_push_ai.sql
-- Enhance smart push log for AI-powered personalized notifications

-- Add order_id column to track push-to-order conversion
ALTER TABLE om_smart_push_log ADD COLUMN IF NOT EXISTS order_id INTEGER;

-- Add ai_generated flag
ALTER TABLE om_smart_push_log ADD COLUMN IF NOT EXISTS ai_generated BOOLEAN DEFAULT false;

-- Index for conversion tracking (pushes that led to orders)
CREATE INDEX IF NOT EXISTS idx_smart_push_order ON om_smart_push_log(order_id) WHERE order_id IS NOT NULL;

-- Index for AI analytics
CREATE INDEX IF NOT EXISTS idx_smart_push_ai ON om_smart_push_log(ai_generated, sent_at DESC) WHERE ai_generated = true;
