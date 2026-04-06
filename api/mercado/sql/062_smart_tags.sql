-- Smart Tags: AI-generated dietary/preference tags for products
-- Inspired by DoorDash Smart Tags feature

CREATE TABLE IF NOT EXISTS om_product_smart_tags (
    id SERIAL PRIMARY KEY,
    product_id INTEGER NOT NULL,
    tag_slug VARCHAR(50) NOT NULL,
    tag_label VARCHAR(100) NOT NULL,
    tag_category VARCHAR(30) NOT NULL CHECK (tag_category IN ('dietary', 'attribute', 'allergen')),
    confidence DECIMAL(3,2) DEFAULT 0.80,
    source VARCHAR(20) DEFAULT 'ai' CHECK (source IN ('ai', 'manual', 'partner')),
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    UNIQUE(product_id, tag_slug)
);

CREATE INDEX IF NOT EXISTS idx_smart_tags_product ON om_product_smart_tags(product_id);
CREATE INDEX IF NOT EXISTS idx_smart_tags_slug ON om_product_smart_tags(tag_slug);
CREATE INDEX IF NOT EXISTS idx_smart_tags_category ON om_product_smart_tags(tag_category);

-- Track batch processing state
CREATE TABLE IF NOT EXISTS om_smart_tags_log (
    id SERIAL PRIMARY KEY,
    product_id INTEGER NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'processed', 'failed', 'skipped')),
    error_message TEXT,
    tokens_used INTEGER DEFAULT 0,
    processed_at TIMESTAMP DEFAULT NOW(),
    UNIQUE(product_id)
);

CREATE INDEX IF NOT EXISTS idx_smart_tags_log_status ON om_smart_tags_log(status);
