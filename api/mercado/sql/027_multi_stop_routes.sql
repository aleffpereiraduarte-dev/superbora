-- Migration 027: Multi-stop delivery routes
-- Permite rotas com multiplas paradas para motorista BoraUm
-- Nota: tabelas ja existiam com schema parcial, adicionamos colunas faltantes

-- Adicionar colunas faltantes em om_delivery_routes
ALTER TABLE om_delivery_routes ADD COLUMN IF NOT EXISTS customer_id INT;
ALTER TABLE om_delivery_routes ADD COLUMN IF NOT EXISTS origin_partner_id INT;
ALTER TABLE om_delivery_routes ADD COLUMN IF NOT EXISTS customer_lat DECIMAL(10,7);
ALTER TABLE om_delivery_routes ADD COLUMN IF NOT EXISTS customer_lng DECIMAL(10,7);
ALTER TABLE om_delivery_routes ADD COLUMN IF NOT EXISTS customer_address TEXT;
ALTER TABLE om_delivery_routes ADD COLUMN IF NOT EXISTS total_delivery_fee DECIMAL(10,2);
CREATE INDEX IF NOT EXISTS idx_route_customer ON om_delivery_routes(customer_id);
CREATE INDEX IF NOT EXISTS idx_route_status ON om_delivery_routes(status);

-- Adicionar colunas faltantes em om_delivery_route_stops
ALTER TABLE om_delivery_route_stops ADD COLUMN IF NOT EXISTS partner_id INT;
ALTER TABLE om_delivery_route_stops ADD COLUMN IF NOT EXISTS stop_sequence INT;
ALTER TABLE om_delivery_route_stops ADD COLUMN IF NOT EXISTS partner_lat DECIMAL(10,7);
ALTER TABLE om_delivery_route_stops ADD COLUMN IF NOT EXISTS partner_lng DECIMAL(10,7);
ALTER TABLE om_delivery_route_stops ADD COLUMN IF NOT EXISTS partner_name VARCHAR(200);
CREATE INDEX IF NOT EXISTS idx_stop_route ON om_delivery_route_stops(route_id);
CREATE INDEX IF NOT EXISTS idx_stop_order ON om_delivery_route_stops(order_id);

-- Colunas extras no pedido
ALTER TABLE om_market_orders ADD COLUMN IF NOT EXISTS route_id INT;
ALTER TABLE om_market_orders ADD COLUMN IF NOT EXISTS route_stop_sequence INT;
ALTER TABLE om_market_orders ADD COLUMN IF NOT EXISTS customer_lat DECIMAL(10,7);
ALTER TABLE om_market_orders ADD COLUMN IF NOT EXISTS customer_lng DECIMAL(10,7);
