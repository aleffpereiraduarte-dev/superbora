-- ============================================================
-- Migration 048: Call center missing indexes + performance fixes
-- ============================================================

-- Missing indexes for call center performance
CREATE INDEX IF NOT EXISTS idx_cc_calls_customer_id ON om_callcenter_calls(customer_id) WHERE customer_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_cc_calls_status_created ON om_callcenter_calls(status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_quality_type_date ON om_ai_quality_scores(conversation_type, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_cc_wa_phone ON om_callcenter_whatsapp(phone);
CREATE INDEX IF NOT EXISTS idx_cc_wa_status ON om_callcenter_whatsapp(status) WHERE status != 'closed';

-- Fix analytics date-range queries (use range instead of ::date cast)
-- (Applied in code, not SQL)
