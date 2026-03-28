-- 056: Post-delivery tip with Stripe payment
-- Adds columns to track Stripe PaymentIntent for post-delivery tips

ALTER TABLE om_market_orders ADD COLUMN IF NOT EXISTS tip_paid BOOLEAN DEFAULT false;
ALTER TABLE om_market_orders ADD COLUMN IF NOT EXISTS tip_payment_id VARCHAR(255);
ALTER TABLE om_market_orders ADD COLUMN IF NOT EXISTS tip_message TEXT;
