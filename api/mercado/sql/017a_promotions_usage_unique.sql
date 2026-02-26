-- =============================================
-- Fix: Add unique constraint on om_promotions_advanced_usage
-- Required for idempotent recordPromotionUsage() with ON CONFLICT DO NOTHING
-- =============================================

-- Prevent duplicate usage records for the same promotion + order
CREATE UNIQUE INDEX IF NOT EXISTS uq_promo_usage_promotion_order
    ON om_promotions_advanced_usage (promotion_id, order_id);
