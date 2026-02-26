-- Store Chat Tables for Pre-Order Communication

CREATE TABLE IF NOT EXISTS om_store_chats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    partner_id INT NOT NULL,
    status ENUM('active', 'archived') DEFAULT 'active',
    last_message_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY idx_customer_partner (customer_id, partner_id),
    KEY idx_partner (partner_id),
    KEY idx_last_message (last_message_at)
);

CREATE TABLE IF NOT EXISTS om_store_chat_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    chat_id INT NOT NULL,
    sender ENUM('customer', 'partner') NOT NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_chat_id (chat_id),
    KEY idx_created (created_at)
);

-- Search logs for trending searches
CREATE TABLE IF NOT EXISTS om_search_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    query VARCHAR(255) NOT NULL,
    customer_id INT DEFAULT NULL,
    results_count INT DEFAULT 0,
    city VARCHAR(100) DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_query (query),
    KEY idx_created (created_at),
    KEY idx_city (city)
);

-- Add nutritional info columns to products if not exist
-- ALTER TABLE om_market_products ADD COLUMN IF NOT EXISTS nutrition JSON DEFAULT NULL;
-- ALTER TABLE om_market_products ADD COLUMN IF NOT EXISTS allergens JSON DEFAULT NULL;

-- Add partner badges/features columns
-- ALTER TABLE om_market_partners ADD COLUMN IF NOT EXISTS eco_friendly TINYINT(1) DEFAULT 0;
-- ALTER TABLE om_market_partners ADD COLUMN IF NOT EXISTS allows_pickup TINYINT(1) DEFAULT 0;
-- ALTER TABLE om_market_partners ADD COLUMN IF NOT EXISTS has_express TINYINT(1) DEFAULT 0;
