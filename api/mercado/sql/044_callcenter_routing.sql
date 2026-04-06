-- ============================================================
-- SuperBora Call Center — Smart Routing, Recording & Transcripts
-- Migration 044
-- ============================================================

-- Add 'transferred' status to calls
ALTER TABLE om_callcenter_calls DROP CONSTRAINT IF EXISTS om_callcenter_calls_status_check;
ALTER TABLE om_callcenter_calls ADD CONSTRAINT om_callcenter_calls_status_check
    CHECK (status IN ('queued','ringing','ai_handling','in_progress','on_hold','completed','missed','voicemail','callback','transferred'));

-- Recording SID for Twilio management + cleanup
ALTER TABLE om_callcenter_calls ADD COLUMN IF NOT EXISTS recording_sid VARCHAR(64);

-- Agent idle timestamp for smart routing (ring least-recently-active first)
ALTER TABLE om_callcenter_agents ADD COLUMN IF NOT EXISTS last_call_ended_at TIMESTAMPTZ;

-- Index for smart routing: find agents with active calls efficiently
CREATE INDEX IF NOT EXISTS idx_cc_calls_active_agent
    ON om_callcenter_calls(agent_id)
    WHERE status IN ('in_progress', 'ringing', 'queued') AND ended_at IS NULL AND agent_id IS NOT NULL;

-- Index for recording cleanup cron (find old recordings)
CREATE INDEX IF NOT EXISTS idx_cc_calls_recording_cleanup
    ON om_callcenter_calls(ended_at)
    WHERE recording_url IS NOT NULL AND recording_sid IS NOT NULL;

-- Index for call SID lookups (used by client-status, recording-status callbacks)
CREATE INDEX IF NOT EXISTS idx_cc_calls_sid ON om_callcenter_calls(twilio_call_sid);
