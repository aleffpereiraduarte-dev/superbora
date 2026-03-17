-- ============================================================
-- Migration 049: Performance indexes for high-traffic queries
-- ============================================================

-- 1. Products by partner + status (vitrine.php subquery)
CREATE INDEX IF NOT EXISTS idx_products_partner_status
    ON om_market_products(partner_id, status);

-- 2. Support messages lookup by ticket (tickets.php LATERAL join)
CREATE INDEX IF NOT EXISTS idx_support_msgs_ticket_sender_date
    ON om_support_messages(ticket_id, remetente_tipo, created_at);

-- 3. Customer default address lookup (home.php)
CREATE INDEX IF NOT EXISTS idx_customer_addr_default
    ON om_customer_addresses(customer_id, is_default, created_at DESC);

-- 4. Partner hours by day (home.php open/closed check)
CREATE INDEX IF NOT EXISTS idx_partner_hours_day
    ON om_partner_hours(partner_id, day_of_week);

-- 5. Orders by payment method + status (payment reconciliation)
CREATE INDEX IF NOT EXISTS idx_orders_payment_status
    ON om_market_orders(forma_pagamento, status, created_at);

-- 6. Partners by status + city (vitrine filtering)
CREATE INDEX IF NOT EXISTS idx_partners_status_city
    ON om_market_partners(status, city);
