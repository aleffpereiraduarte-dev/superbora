-- =====================================================
-- Migration 021: Product Images Gallery
-- Adds images JSON column for multiple product photos
-- =====================================================

-- Helper procedure for adding column if not exists
DELIMITER //
DROP PROCEDURE IF EXISTS add_column_if_not_exists//
CREATE PROCEDURE add_column_if_not_exists(
    IN p_table VARCHAR(100),
    IN p_column VARCHAR(100),
    IN p_definition VARCHAR(500)
)
BEGIN
    SET @col_exists = (
        SELECT COUNT(*) FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = p_table AND column_name = p_column
    );
    IF @col_exists = 0 THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_definition);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END//
DELIMITER ;

-- Add images JSON column to om_market_products
-- Format: ["url1", "url2", "url3"] - array of image URLs
-- Primary image remains in `image` column for backwards compatibility
CALL add_column_if_not_exists('om_market_products', 'images', 'JSON DEFAULT NULL AFTER `image`');

-- Add customer review photos for products
CREATE TABLE IF NOT EXISTS om_product_review_photos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    order_id INT NOT NULL,
    customer_id INT NOT NULL,
    photo_url VARCHAR(500) NOT NULL,
    caption VARCHAR(255),
    rating TINYINT DEFAULT 5,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_product (product_id),
    INDEX idx_customer (customer_id),
    INDEX idx_status (status),
    INDEX idx_product_approved (product_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Clean up
DROP PROCEDURE IF EXISTS add_column_if_not_exists;
