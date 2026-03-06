<?php
header('Content-Type: text/plain; charset=utf-8');
if (($_GET['key'] ?? '') !== 'abc123') { die("no"); }

echo "=== Fix Call Center DB ===\n\n";

require_once __DIR__ . '/config/database.php';
$db = getDB();

$statements = [
    // WhatsApp Conversations
    "CREATE TABLE IF NOT EXISTS om_callcenter_whatsapp (
        id SERIAL PRIMARY KEY,
        phone VARCHAR(30) NOT NULL,
        customer_id INT,
        customer_name VARCHAR(200),
        agent_id INT,
        status VARCHAR(20) DEFAULT 'bot',
        ai_context JSONB DEFAULT '{}',
        last_message_at TIMESTAMP DEFAULT NOW(),
        unread_count INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT NOW()
    )",
    "CREATE INDEX IF NOT EXISTS idx_cc_wa_phone ON om_callcenter_whatsapp(phone)",

    // WhatsApp Messages
    "CREATE TABLE IF NOT EXISTS om_callcenter_wa_messages (
        id SERIAL PRIMARY KEY,
        conversation_id INT NOT NULL,
        direction VARCHAR(10) DEFAULT 'inbound',
        sender_type VARCHAR(10) DEFAULT 'customer',
        message TEXT,
        message_type VARCHAR(20) DEFAULT 'text',
        media_url TEXT,
        ai_suggested BOOLEAN DEFAULT FALSE,
        created_at TIMESTAMP DEFAULT NOW()
    )",
    "CREATE INDEX IF NOT EXISTS idx_cc_wam_conv ON om_callcenter_wa_messages(conversation_id, created_at DESC)",

    // Call Queue
    "CREATE TABLE IF NOT EXISTS om_callcenter_queue (
        id SERIAL PRIMARY KEY,
        call_id INT,
        customer_phone VARCHAR(30),
        customer_name VARCHAR(200),
        customer_id INT,
        priority INT DEFAULT 5,
        skill_required VARCHAR(50),
        estimated_wait_seconds INT,
        position_in_queue INT,
        queued_at TIMESTAMP DEFAULT NOW(),
        picked_at TIMESTAMP,
        picked_by INT,
        abandoned_at TIMESTAMP,
        callback_number VARCHAR(30)
    )",

    // Order Drafts
    "CREATE TABLE IF NOT EXISTS om_callcenter_order_drafts (
        id SERIAL PRIMARY KEY,
        agent_id INT,
        call_id INT,
        whatsapp_id INT,
        source VARCHAR(20) DEFAULT 'phone',
        customer_id INT,
        customer_name VARCHAR(200),
        customer_phone VARCHAR(30),
        customer_address_id INT,
        partner_id INT,
        partner_name VARCHAR(200),
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
        status VARCHAR(20) DEFAULT 'building',
        sms_sent BOOLEAN DEFAULT FALSE,
        sms_sent_at TIMESTAMP,
        submitted_order_id INT,
        created_at TIMESTAMP DEFAULT NOW(),
        updated_at TIMESTAMP DEFAULT NOW()
    )",

    // Daily Metrics
    "CREATE TABLE IF NOT EXISTS om_callcenter_metrics (
        id SERIAL PRIMARY KEY,
        date DATE NOT NULL,
        agent_id INT,
        total_calls INT DEFAULT 0,
        answered_calls INT DEFAULT 0,
        missed_calls INT DEFAULT 0,
        ai_handled_calls INT DEFAULT 0,
        ai_orders_placed INT DEFAULT 0,
        agent_orders_placed INT DEFAULT 0,
        avg_handle_time_seconds INT DEFAULT 0,
        avg_wait_time_seconds INT DEFAULT 0,
        orders_total_value DECIMAL(10,2) DEFAULT 0,
        whatsapp_conversations INT DEFAULT 0,
        callbacks_requested INT DEFAULT 0,
        callbacks_completed INT DEFAULT 0,
        csat_sum INT DEFAULT 0,
        csat_count INT DEFAULT 0
    )",

    // Payment Links
    "CREATE TABLE IF NOT EXISTS om_callcenter_payment_links (
        id SERIAL PRIMARY KEY,
        draft_id INT,
        stripe_session_id VARCHAR(200),
        stripe_payment_link_url TEXT,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        status VARCHAR(20) DEFAULT 'pending',
        customer_phone VARCHAR(30),
        sms_sent BOOLEAN DEFAULT FALSE,
        paid_at TIMESTAMP,
        expires_at TIMESTAMP,
        created_at TIMESTAMP DEFAULT NOW()
    )",

    // AI Call Memory
    "CREATE TABLE IF NOT EXISTS om_ai_call_memory (
        id SERIAL PRIMARY KEY,
        customer_phone VARCHAR(20) NOT NULL,
        customer_id INT,
        memory_type VARCHAR(30) NOT NULL,
        memory_key VARCHAR(100) NOT NULL,
        memory_value TEXT NOT NULL,
        confidence FLOAT DEFAULT 1.0,
        times_confirmed INT DEFAULT 1,
        last_used_at TIMESTAMP DEFAULT NOW(),
        created_at TIMESTAMP DEFAULT NOW(),
        updated_at TIMESTAMP DEFAULT NOW(),
        UNIQUE(customer_phone, memory_type, memory_key)
    )",
    "CREATE INDEX IF NOT EXISTS idx_ai_call_memory_phone ON om_ai_call_memory(customer_phone)",

    // Outbound Calls
    "CREATE TABLE IF NOT EXISTS om_outbound_calls (
        id SERIAL PRIMARY KEY,
        twilio_call_sid VARCHAR(50),
        phone VARCHAR(30) NOT NULL,
        customer_id INT,
        customer_name VARCHAR(200),
        call_type VARCHAR(30) NOT NULL DEFAULT 'reengagement',
        status VARCHAR(20) DEFAULT 'scheduled',
        outcome VARCHAR(30),
        outcome_details TEXT,
        campaign_id INT,
        order_id INT,
        ai_context JSONB DEFAULT '{}',
        duration_seconds INT,
        attempts INT DEFAULT 0,
        max_attempts INT DEFAULT 3,
        scheduled_at TIMESTAMP,
        started_at TIMESTAMP,
        ended_at TIMESTAMP,
        created_at TIMESTAMP DEFAULT NOW()
    )",

    // Outbound Call Queue
    "CREATE TABLE IF NOT EXISTS om_outbound_call_queue (
        id SERIAL PRIMARY KEY,
        phone VARCHAR(30) NOT NULL,
        customer_id INT,
        call_type VARCHAR(30) NOT NULL DEFAULT 'reengagement',
        priority INT DEFAULT 5,
        payload JSONB DEFAULT '{}',
        status VARCHAR(20) DEFAULT 'pending',
        scheduled_for TIMESTAMP DEFAULT NOW(),
        attempts INT DEFAULT 0,
        last_attempt_at TIMESTAMP,
        error_message TEXT,
        created_at TIMESTAMP DEFAULT NOW()
    )",

    // Outbound Campaigns
    "CREATE TABLE IF NOT EXISTS om_outbound_campaigns (
        id SERIAL PRIMARY KEY,
        name VARCHAR(200) NOT NULL,
        campaign_type VARCHAR(30) NOT NULL,
        status VARCHAR(20) DEFAULT 'draft',
        target_count INT DEFAULT 0,
        called_count INT DEFAULT 0,
        answered_count INT DEFAULT 0,
        converted_count INT DEFAULT 0,
        payload JSONB DEFAULT '{}',
        started_at TIMESTAMP,
        completed_at TIMESTAMP,
        created_by INT,
        created_at TIMESTAMP DEFAULT NOW()
    )",

    // Opt-Out Registry (LGPD)
    "CREATE TABLE IF NOT EXISTS om_outbound_opt_outs (
        id SERIAL PRIMARY KEY,
        phone VARCHAR(30) NOT NULL UNIQUE,
        reason VARCHAR(200),
        source VARCHAR(30) DEFAULT 'customer',
        created_at TIMESTAMP DEFAULT NOW()
    )",

    // Rate Limiting
    "CREATE TABLE IF NOT EXISTS om_callcenter_rate_limit (
        id SERIAL PRIMARY KEY,
        phone VARCHAR(30) NOT NULL,
        action_type VARCHAR(30) DEFAULT 'call',
        window_start TIMESTAMP DEFAULT NOW(),
        count INT DEFAULT 1,
        UNIQUE(phone, action_type)
    )",

    // Blacklist
    "CREATE TABLE IF NOT EXISTS om_callcenter_blacklist (
        id SERIAL PRIMARY KEY,
        phone VARCHAR(30) NOT NULL UNIQUE,
        reason TEXT,
        severity VARCHAR(20) DEFAULT 'temporary',
        expires_at TIMESTAMP,
        created_at TIMESTAMP DEFAULT NOW()
    )",

    // WhatsApp Rate Limit
    "CREATE TABLE IF NOT EXISTS om_whatsapp_rate_limit (
        id SERIAL PRIMARY KEY,
        phone VARCHAR(30) NOT NULL,
        minute_window VARCHAR(20) NOT NULL,
        count INT DEFAULT 1,
        UNIQUE(phone, minute_window)
    )",
];

$ok = 0;
$err = 0;
foreach ($statements as $sql) {
    try {
        $db->exec($sql);
        // Extract table/index name for display
        if (preg_match('/(?:TABLE|INDEX)\s+(?:IF\s+NOT\s+EXISTS\s+)?(\w+)/i', $sql, $m)) {
            echo "OK: {$m[1]}\n";
        } else {
            echo "OK\n";
        }
        $ok++;
    } catch (Exception $e) {
        $msg = $e->getMessage();
        if (preg_match('/(?:TABLE|INDEX)\s+(?:IF\s+NOT\s+EXISTS\s+)?(\w+)/i', $sql, $m)) {
            echo "ERR {$m[1]}: {$msg}\n";
        } else {
            echo "ERR: {$msg}\n";
        }
        $err++;
    }
}

// Add source column to orders if missing
try {
    $db->exec("DO \$\$ BEGIN
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'om_market_orders' AND column_name = 'source') THEN
            ALTER TABLE om_market_orders ADD COLUMN source VARCHAR(20) DEFAULT 'app';
        END IF;
    END \$\$");
    echo "OK: om_market_orders.source column\n";
    $ok++;
} catch (Exception $e) {
    echo "ERR orders.source: " . $e->getMessage() . "\n";
    $err++;
}

echo "\nDone: {$ok} OK, {$err} errors\n";

// Test: verify all tables
echo "\n--- Verify tables ---\n";
$tables = [
    'om_callcenter_agents', 'om_callcenter_calls', 'om_callcenter_queue',
    'om_callcenter_whatsapp', 'om_callcenter_wa_messages', 'om_callcenter_order_drafts',
    'om_callcenter_metrics', 'om_callcenter_payment_links', 'om_ai_call_memory',
    'om_outbound_calls', 'om_outbound_call_queue', 'om_outbound_campaigns',
    'om_outbound_opt_outs', 'om_callcenter_rate_limit', 'om_callcenter_blacklist',
    'om_whatsapp_rate_limit',
];
foreach ($tables as $t) {
    try {
        $count = $db->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
        echo "{$t}: OK ({$count} rows)\n";
    } catch (Exception $e) {
        echo "{$t}: MISSING!\n";
    }
}
