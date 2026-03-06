-- ═══════════════════════════════════════════════════════════════════════════
-- 045: WhatsApp Proactive Messaging Log
-- Tracks proactive messages sent to customers via WhatsApp
-- to enforce rate limits and prevent spam.
-- ═══════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS om_whatsapp_proactive_log (
    id SERIAL PRIMARY KEY,
    customer_id INTEGER NOT NULL,
    phone VARCHAR(20) NOT NULL,
    message_type VARCHAR(30) NOT NULL, -- lunch, dinner, promo, winback
    message TEXT,
    sent_date DATE NOT NULL DEFAULT CURRENT_DATE,
    sent_at TIMESTAMP DEFAULT NOW(),
    CONSTRAINT unique_customer_type_day UNIQUE (customer_id, message_type, sent_date)
);

CREATE INDEX IF NOT EXISTS idx_proactive_log_customer ON om_whatsapp_proactive_log(customer_id, sent_at);
CREATE INDEX IF NOT EXISTS idx_proactive_log_type ON om_whatsapp_proactive_log(message_type, sent_at);

-- Opt-out table for customers who don't want proactive messages
CREATE TABLE IF NOT EXISTS om_whatsapp_proactive_optout (
    customer_id INTEGER PRIMARY KEY,
    opted_out_at TIMESTAMP DEFAULT NOW(),
    reason VARCHAR(100) DEFAULT 'user_request'
);
