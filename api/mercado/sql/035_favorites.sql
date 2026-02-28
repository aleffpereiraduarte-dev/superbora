-- Migration 035: Customer favorites collections
-- Tables: om_favorites (collections), om_favorite_items (items within collections)

CREATE TABLE IF NOT EXISTS om_favorites (
    id          SERIAL PRIMARY KEY,
    customer_id INTEGER     NOT NULL,
    name        VARCHAR(100) NOT NULL DEFAULT 'Favoritos',
    description VARCHAR(255),
    icon        VARCHAR(50)  NOT NULL DEFAULT 'heart',
    color       VARCHAR(20)  NOT NULL DEFAULT '#ef4444',
    is_default  SMALLINT     NOT NULL DEFAULT 0,
    item_count  INTEGER      NOT NULL DEFAULT 0,
    created_at  TIMESTAMP    NOT NULL DEFAULT NOW(),
    updated_at  TIMESTAMP    NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_om_favorites_customer ON om_favorites (customer_id);
CREATE INDEX IF NOT EXISTS idx_om_favorites_default  ON om_favorites (customer_id, is_default);

CREATE TABLE IF NOT EXISTS om_favorite_items (
    id          SERIAL PRIMARY KEY,
    favorite_id INTEGER      NOT NULL REFERENCES om_favorites(id) ON DELETE CASCADE,
    customer_id INTEGER      NOT NULL,
    item_type   VARCHAR(20)  NOT NULL CHECK (item_type IN ('product', 'store', 'combo')),
    item_id     INTEGER      NOT NULL,
    notes       VARCHAR(255),
    created_at  TIMESTAMP    NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_om_fav_items_fav      ON om_favorite_items (favorite_id);
CREATE INDEX IF NOT EXISTS idx_om_fav_items_customer ON om_favorite_items (customer_id);
CREATE UNIQUE INDEX IF NOT EXISTS idx_om_fav_items_unique
    ON om_favorite_items (favorite_id, item_type, item_id);
