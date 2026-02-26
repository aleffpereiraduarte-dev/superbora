-- =============================================
-- SuperBora Marketplace - Promotions Advanced
-- Table: om_promotions_advanced
-- Features: Happy Hour, BOGO, Quantity Discounts
-- =============================================

-- Main promotions table with all advanced promo types
CREATE TABLE IF NOT EXISTS om_promotions_advanced (
    id INT AUTO_INCREMENT PRIMARY KEY,
    partner_id INT NOT NULL,

    -- Promo type: happy_hour, bogo, quantity_discount, combo_deal
    type ENUM('happy_hour', 'bogo', 'quantity_discount', 'combo_deal') NOT NULL,

    -- Basic info
    name VARCHAR(100) NOT NULL,
    description TEXT,
    badge_text VARCHAR(50),
    badge_color VARCHAR(10) DEFAULT '#FF5722',

    -- Happy Hour fields
    discount_percent DECIMAL(5,2) DEFAULT 0,
    start_time TIME,
    end_time TIME,
    days_of_week VARCHAR(20) DEFAULT '1,2,3,4,5,6,7',

    -- BOGO (Buy One Get One) fields
    buy_quantity INT DEFAULT 1,
    get_quantity INT DEFAULT 1,
    get_discount_percent DECIMAL(5,2) DEFAULT 100,

    -- Quantity Discount fields
    min_quantity INT DEFAULT 1,
    quantity_discount_percent DECIMAL(5,2) DEFAULT 0,

    -- Applicability
    applies_to ENUM('all', 'category', 'products') DEFAULT 'all',
    product_ids TEXT,
    category_ids TEXT,

    -- Validity period
    valid_from DATE,
    valid_until DATE,

    -- Usage limits
    max_uses INT DEFAULT NULL,
    max_uses_per_customer INT DEFAULT NULL,
    current_uses INT DEFAULT 0,

    -- Status and priority
    status TINYINT(1) DEFAULT 1,
    priority INT DEFAULT 0,

    -- Timestamps
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,

    -- Indexes
    KEY idx_partner (partner_id),
    KEY idx_type_status (type, status),
    KEY idx_status_valid (status, valid_from, valid_until),
    KEY idx_priority (priority DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Track promotion usage per customer
CREATE TABLE IF NOT EXISTS om_promotions_advanced_usage (
    id INT AUTO_INCREMENT PRIMARY KEY,
    promotion_id INT NOT NULL,
    customer_id INT NOT NULL,
    order_id INT NOT NULL,
    discount_applied DECIMAL(10,2) NOT NULL,
    used_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    KEY idx_promo_customer (promotion_id, customer_id),
    KEY idx_order (order_id),
    FOREIGN KEY (promotion_id) REFERENCES om_promotions_advanced(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- Sample promotions for testing
-- =============================================

-- Example Happy Hour (uncomment to insert)
-- INSERT INTO om_promotions_advanced (partner_id, type, name, description, badge_text, badge_color, discount_percent, start_time, end_time, days_of_week, applies_to, status)
-- VALUES (1, 'happy_hour', 'Happy Hour da Noite', 'Desconto especial das 18h as 20h', 'Happy Hour -20%', '#FF9800', 20.00, '18:00:00', '20:00:00', '2,3,4,5,6', 'all', 1);

-- Example BOGO
-- INSERT INTO om_promotions_advanced (partner_id, type, name, description, badge_text, badge_color, buy_quantity, get_quantity, get_discount_percent, applies_to, status)
-- VALUES (1, 'bogo', 'Leve 2 Pague 1', 'Na compra de 2 unidades, a segunda sai gratis', 'Leve 2 Pague 1', '#4CAF50', 1, 1, 100.00, 'products', 1);

-- Example Quantity Discount
-- INSERT INTO om_promotions_advanced (partner_id, type, name, description, badge_text, badge_color, min_quantity, quantity_discount_percent, applies_to, status)
-- VALUES (1, 'quantity_discount', 'Compre 3+', 'Compre 3 ou mais e ganhe 15% de desconto', '3+ = -15%', '#2196F3', 3, 15.00, 'all', 1);
