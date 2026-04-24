-- Migration 097: PIX webhook deduplication table
-- Protects against EFI re-delivering webhooks (if our response times out)
-- which would otherwise double-credit the customer wallet / order.
--
-- The tx_id PK is the EFI endToEndId (preferred) or txid — whichever is
-- available on the inbound payload. Insert with ON CONFLICT DO NOTHING;
-- if rowCount() === 0 the webhook is a duplicate replay.

CREATE TABLE IF NOT EXISTS om_pix_webhook_dedup (
    tx_id VARCHAR(100) PRIMARY KEY,
    processed_at TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Optional: prune entries older than 30 days to keep table small.
-- Run in cron/cleanup.php or similar if needed:
--   DELETE FROM om_pix_webhook_dedup WHERE processed_at < NOW() - INTERVAL '30 days';
