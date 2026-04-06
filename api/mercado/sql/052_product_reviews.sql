-- =====================================================
-- 052: Product Reviews
-- Allow customers to rate and review individual products
-- =====================================================

CREATE TABLE IF NOT EXISTS om_product_reviews (
    id SERIAL PRIMARY KEY,
    product_id INT NOT NULL,
    customer_id INT NOT NULL,
    order_id INT,
    rating INT NOT NULL CHECK (rating >= 1 AND rating <= 5),
    title VARCHAR(200),
    comment TEXT,
    photos TEXT[],
    helpful_count INT DEFAULT 0,
    verified_purchase BOOLEAN DEFAULT false,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    UNIQUE(product_id, customer_id, order_id)
);

CREATE INDEX IF NOT EXISTS idx_product_reviews_product ON om_product_reviews(product_id);
CREATE INDEX IF NOT EXISTS idx_product_reviews_customer ON om_product_reviews(customer_id);
CREATE INDEX IF NOT EXISTS idx_product_reviews_rating ON om_product_reviews(product_id, rating);
CREATE INDEX IF NOT EXISTS idx_product_reviews_created ON om_product_reviews(product_id, created_at DESC);
