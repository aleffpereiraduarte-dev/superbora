-- Group Orders feature
-- Allows customers to create shared orders with other people

CREATE TABLE IF NOT EXISTS om_market_group_orders (
    id SERIAL PRIMARY KEY,
    creator_id INTEGER NOT NULL,
    partner_id INTEGER NOT NULL,
    share_code VARCHAR(10) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
    UNIQUE(share_code)
);

CREATE INDEX IF NOT EXISTS idx_group_orders_creator ON om_market_group_orders(creator_id);
CREATE INDEX IF NOT EXISTS idx_group_orders_partner ON om_market_group_orders(partner_id);
CREATE INDEX IF NOT EXISTS idx_group_orders_share_code ON om_market_group_orders(share_code);
CREATE INDEX IF NOT EXISTS idx_group_orders_status ON om_market_group_orders(status);

CREATE TABLE IF NOT EXISTS om_market_group_order_participants (
    id SERIAL PRIMARY KEY,
    group_order_id INTEGER NOT NULL REFERENCES om_market_group_orders(id) ON DELETE CASCADE,
    customer_id INTEGER,
    guest_name VARCHAR(100),
    joined_at TIMESTAMP NOT NULL DEFAULT NOW(),
    UNIQUE(group_order_id, customer_id)
);

CREATE INDEX IF NOT EXISTS idx_group_participants_group ON om_market_group_order_participants(group_order_id);
CREATE INDEX IF NOT EXISTS idx_group_participants_customer ON om_market_group_order_participants(customer_id);

CREATE TABLE IF NOT EXISTS om_market_group_order_items (
    id SERIAL PRIMARY KEY,
    group_order_id INTEGER NOT NULL REFERENCES om_market_group_orders(id) ON DELETE CASCADE,
    participant_id INTEGER NOT NULL REFERENCES om_market_group_order_participants(id) ON DELETE CASCADE,
    product_id INTEGER NOT NULL,
    product_name VARCHAR(255) NOT NULL,
    quantity INTEGER NOT NULL DEFAULT 1,
    price NUMERIC(10,2) NOT NULL,
    notes TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_group_items_group ON om_market_group_order_items(group_order_id);
CREATE INDEX IF NOT EXISTS idx_group_items_participant ON om_market_group_order_items(participant_id);
