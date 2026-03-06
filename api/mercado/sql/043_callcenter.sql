-- ============================================================
-- SuperBora Call Center — Central de Atendimento Inteligente
-- Migration 043: Call center tables
-- ============================================================

-- Agent profiles linked to admin users
CREATE TABLE IF NOT EXISTS om_callcenter_agents (
  id SERIAL PRIMARY KEY,
  admin_id INT NOT NULL,
  display_name VARCHAR(100) NOT NULL,
  extension VARCHAR(10),
  status VARCHAR(20) DEFAULT 'offline' CHECK (status IN ('online','busy','break','offline')),
  skills TEXT[] DEFAULT '{}',
  max_concurrent INT DEFAULT 3,
  avatar_url TEXT,
  created_at TIMESTAMPTZ DEFAULT NOW(),
  updated_at TIMESTAMPTZ DEFAULT NOW()
);

-- Every call record
CREATE TABLE IF NOT EXISTS om_callcenter_calls (
  id SERIAL PRIMARY KEY,
  twilio_call_sid VARCHAR(64) UNIQUE,
  customer_phone VARCHAR(20),
  customer_id INT,
  customer_name VARCHAR(100),
  agent_id INT REFERENCES om_callcenter_agents(id),
  direction VARCHAR(10) DEFAULT 'inbound' CHECK (direction IN ('inbound','outbound')),
  status VARCHAR(20) DEFAULT 'queued' CHECK (status IN ('queued','ringing','ai_handling','in_progress','on_hold','completed','missed','voicemail','callback')),
  duration_seconds INT,
  recording_url TEXT,
  recording_duration INT,
  transcription TEXT,
  ai_summary TEXT,
  ai_sentiment VARCHAR(20) CHECK (ai_sentiment IS NULL OR ai_sentiment IN ('positive','neutral','negative','frustrated')),
  ai_tags TEXT[] DEFAULT '{}',
  notes TEXT,
  order_id INT,
  store_identified VARCHAR(200),
  callback_requested BOOLEAN DEFAULT FALSE,
  callback_completed_at TIMESTAMPTZ,
  wait_time_seconds INT,
  started_at TIMESTAMPTZ DEFAULT NOW(),
  answered_at TIMESTAMPTZ,
  ended_at TIMESTAMPTZ,
  created_at TIMESTAMPTZ DEFAULT NOW()
);

-- Live call queue
CREATE TABLE IF NOT EXISTS om_callcenter_queue (
  id SERIAL PRIMARY KEY,
  call_id INT REFERENCES om_callcenter_calls(id) ON DELETE CASCADE,
  customer_phone VARCHAR(20),
  customer_name VARCHAR(100),
  customer_id INT,
  priority INT DEFAULT 5 CHECK (priority BETWEEN 1 AND 10),
  skill_required VARCHAR(50),
  estimated_wait_seconds INT,
  position_in_queue INT,
  queued_at TIMESTAMPTZ DEFAULT NOW(),
  picked_at TIMESTAMPTZ,
  picked_by INT REFERENCES om_callcenter_agents(id),
  abandoned_at TIMESTAMPTZ,
  callback_number VARCHAR(20)
);

-- WhatsApp conversations
CREATE TABLE IF NOT EXISTS om_callcenter_whatsapp (
  id SERIAL PRIMARY KEY,
  phone VARCHAR(20) NOT NULL,
  customer_id INT,
  customer_name VARCHAR(100),
  agent_id INT REFERENCES om_callcenter_agents(id),
  status VARCHAR(20) DEFAULT 'bot' CHECK (status IN ('bot','waiting','assigned','closed')),
  ai_context JSONB DEFAULT '{}',
  last_message_at TIMESTAMPTZ,
  unread_count INT DEFAULT 0,
  created_at TIMESTAMPTZ DEFAULT NOW()
);

-- Individual WhatsApp messages
CREATE TABLE IF NOT EXISTS om_callcenter_wa_messages (
  id SERIAL PRIMARY KEY,
  conversation_id INT REFERENCES om_callcenter_whatsapp(id) ON DELETE CASCADE,
  direction VARCHAR(10) NOT NULL CHECK (direction IN ('inbound','outbound')),
  sender_type VARCHAR(20) NOT NULL CHECK (sender_type IN ('customer','agent','bot')),
  message TEXT NOT NULL,
  message_type VARCHAR(20) DEFAULT 'text' CHECK (message_type IN ('text','image','audio','document','location')),
  media_url TEXT,
  ai_suggested BOOLEAN DEFAULT FALSE,
  created_at TIMESTAMPTZ DEFAULT NOW()
);

-- In-progress orders being built by agents or AI
CREATE TABLE IF NOT EXISTS om_callcenter_order_drafts (
  id SERIAL PRIMARY KEY,
  agent_id INT REFERENCES om_callcenter_agents(id),
  call_id INT REFERENCES om_callcenter_calls(id),
  whatsapp_id INT REFERENCES om_callcenter_whatsapp(id),
  source VARCHAR(20) DEFAULT 'manual' CHECK (source IN ('phone','whatsapp','manual')),
  customer_id INT,
  customer_name VARCHAR(100),
  customer_phone VARCHAR(20),
  customer_address_id INT,
  partner_id INT,
  partner_name VARCHAR(100),
  items JSONB DEFAULT '[]',
  address JSONB,
  payment_method VARCHAR(30),
  payment_change DECIMAL(10,2),
  payment_link_url TEXT,
  payment_link_id VARCHAR(100),
  subtotal DECIMAL(10,2) DEFAULT 0,
  delivery_fee DECIMAL(10,2) DEFAULT 0,
  service_fee DECIMAL(10,2) DEFAULT 0,
  tip DECIMAL(10,2) DEFAULT 0,
  discount DECIMAL(10,2) DEFAULT 0,
  coupon_code VARCHAR(50),
  total DECIMAL(10,2) DEFAULT 0,
  notes TEXT,
  status VARCHAR(20) DEFAULT 'building' CHECK (status IN ('building','review','awaiting_payment','submitted','cancelled')),
  sms_sent BOOLEAN DEFAULT FALSE,
  sms_sent_at TIMESTAMPTZ,
  submitted_order_id INT,
  created_at TIMESTAMPTZ DEFAULT NOW(),
  updated_at TIMESTAMPTZ DEFAULT NOW()
);

-- Daily aggregated metrics per agent
CREATE TABLE IF NOT EXISTS om_callcenter_metrics (
  id SERIAL PRIMARY KEY,
  date DATE NOT NULL,
  agent_id INT REFERENCES om_callcenter_agents(id),
  total_calls INT DEFAULT 0,
  answered_calls INT DEFAULT 0,
  missed_calls INT DEFAULT 0,
  ai_handled_calls INT DEFAULT 0,
  ai_orders_placed INT DEFAULT 0,
  agent_orders_placed INT DEFAULT 0,
  avg_handle_time_seconds INT DEFAULT 0,
  avg_wait_time_seconds INT DEFAULT 0,
  orders_total_value DECIMAL(12,2) DEFAULT 0,
  whatsapp_conversations INT DEFAULT 0,
  callbacks_requested INT DEFAULT 0,
  callbacks_completed INT DEFAULT 0,
  csat_sum DECIMAL(5,1) DEFAULT 0,
  csat_count INT DEFAULT 0,
  created_at TIMESTAMPTZ DEFAULT NOW(),
  UNIQUE(date, agent_id)
);

-- Stripe payment links for phone/WhatsApp orders
CREATE TABLE IF NOT EXISTS om_callcenter_payment_links (
  id SERIAL PRIMARY KEY,
  draft_id INT REFERENCES om_callcenter_order_drafts(id) ON DELETE CASCADE,
  stripe_session_id VARCHAR(100),
  stripe_payment_link_url TEXT,
  amount DECIMAL(10,2) NOT NULL,
  status VARCHAR(20) DEFAULT 'pending' CHECK (status IN ('pending','paid','expired','cancelled')),
  customer_phone VARCHAR(20),
  sms_sent BOOLEAN DEFAULT FALSE,
  paid_at TIMESTAMPTZ,
  expires_at TIMESTAMPTZ,
  created_at TIMESTAMPTZ DEFAULT NOW()
);

-- Indexes
CREATE INDEX IF NOT EXISTS idx_cc_agents_admin ON om_callcenter_agents(admin_id);
CREATE INDEX IF NOT EXISTS idx_cc_agents_status ON om_callcenter_agents(status);
CREATE INDEX IF NOT EXISTS idx_cc_calls_agent ON om_callcenter_calls(agent_id);
CREATE INDEX IF NOT EXISTS idx_cc_calls_phone ON om_callcenter_calls(customer_phone);
CREATE INDEX IF NOT EXISTS idx_cc_calls_status ON om_callcenter_calls(status);
CREATE INDEX IF NOT EXISTS idx_cc_calls_created ON om_callcenter_calls(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_cc_queue_pending ON om_callcenter_queue(queued_at) WHERE picked_at IS NULL AND abandoned_at IS NULL;
CREATE INDEX IF NOT EXISTS idx_cc_queue_call ON om_callcenter_queue(call_id);
CREATE INDEX IF NOT EXISTS idx_cc_wa_status ON om_callcenter_whatsapp(status);
CREATE INDEX IF NOT EXISTS idx_cc_wa_phone ON om_callcenter_whatsapp(phone);
CREATE INDEX IF NOT EXISTS idx_cc_wa_agent ON om_callcenter_whatsapp(agent_id);
CREATE INDEX IF NOT EXISTS idx_cc_wa_msgs_conv ON om_callcenter_wa_messages(conversation_id, created_at);
CREATE INDEX IF NOT EXISTS idx_cc_drafts_agent ON om_callcenter_order_drafts(agent_id);
CREATE INDEX IF NOT EXISTS idx_cc_drafts_status ON om_callcenter_order_drafts(status);
CREATE INDEX IF NOT EXISTS idx_cc_drafts_created ON om_callcenter_order_drafts(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_cc_metrics_date ON om_callcenter_metrics(date DESC);
CREATE INDEX IF NOT EXISTS idx_cc_metrics_agent ON om_callcenter_metrics(agent_id, date DESC);
CREATE INDEX IF NOT EXISTS idx_cc_paylinks_draft ON om_callcenter_payment_links(draft_id);
CREATE INDEX IF NOT EXISTS idx_cc_paylinks_status ON om_callcenter_payment_links(status);
