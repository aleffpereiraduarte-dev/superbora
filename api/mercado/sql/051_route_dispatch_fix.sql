-- Migration 051: Route dispatch fix
-- Adds route_id/route_stop_id to om_entregas for multi-stop route tracking
-- Adds boraum_delivery_id/boraum_status to om_delivery_routes for route-level dispatch

ALTER TABLE om_entregas ADD COLUMN IF NOT EXISTS route_id INT;
ALTER TABLE om_entregas ADD COLUMN IF NOT EXISTS route_stop_id INT;
CREATE INDEX IF NOT EXISTS idx_entregas_route ON om_entregas(route_id);

ALTER TABLE om_delivery_routes ADD COLUMN IF NOT EXISTS boraum_delivery_id INT;
ALTER TABLE om_delivery_routes ADD COLUMN IF NOT EXISTS boraum_status VARCHAR(50);
