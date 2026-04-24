-- 098_prescription.sql — Farmácia com receita
-- Adiciona suporte pra pedidos com medicamento controlado:
-- cliente envia foto da receita → farmacêutico aprova/rejeita no painel.
--
-- Run remotely:
--   rsync -az /var/www/html/api/mercado/sql/098_prescription.sql \
--     root@147.93.12.236:/tmp/098.sql
--   ssh root@147.93.12.236 "PGPASSWORD=\$(grep DB_PASSWORD /var/www/html/.env|cut -d= -f2) \
--     psql -h 127.0.0.1 -p 6432 -U love1 love1 -f /tmp/098.sql"

ALTER TABLE om_market_products
  ADD COLUMN IF NOT EXISTS requires_prescription SMALLINT NOT NULL DEFAULT 0;

ALTER TABLE om_market_orders
  ADD COLUMN IF NOT EXISTS prescription_status VARCHAR(20),
  ADD COLUMN IF NOT EXISTS prescription_image_url TEXT,
  ADD COLUMN IF NOT EXISTS prescription_reviewed_at TIMESTAMP,
  ADD COLUMN IF NOT EXISTS prescription_reviewed_by INTEGER,
  ADD COLUMN IF NOT EXISTS prescription_rejection_reason TEXT;

-- Pending prescriptions per partner — used pela fila no painel.
CREATE INDEX IF NOT EXISTS idx_orders_prescription_pending
  ON om_market_orders(partner_id, prescription_status)
  WHERE prescription_status = 'pending';
