-- Migration 050: Cron job logging and monitoring
-- Tracks execution of all cron jobs for observability, debugging, and idempotency

CREATE TABLE IF NOT EXISTS om_cron_log (
    id SERIAL PRIMARY KEY,
    job_name VARCHAR(100) NOT NULL,
    started_at TIMESTAMP DEFAULT NOW(),
    finished_at TIMESTAMP,
    duration_ms INTEGER,
    status VARCHAR(20) DEFAULT 'running',  -- running, success, failed
    rows_affected INTEGER DEFAULT 0,
    error_message TEXT,
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_cron_log_job_date ON om_cron_log(job_name, started_at DESC);
