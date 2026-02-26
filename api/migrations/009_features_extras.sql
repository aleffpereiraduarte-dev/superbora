-- Migration 009: Features extras (22 features delivery)
-- Executar apos 008_ifood_features.sql
-- Compativel com MySQL 8.4+

DELIMITER //

-- Procedure para adicionar coluna se nao existir
DROP PROCEDURE IF EXISTS add_column_if_not_exists//
CREATE PROCEDURE add_column_if_not_exists(
    IN p_table VARCHAR(100),
    IN p_column VARCHAR(100),
    IN p_definition VARCHAR(500)
)
BEGIN
    DECLARE col_count INT;
    SELECT COUNT(*) INTO col_count
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = p_table
      AND COLUMN_NAME = p_column;
    IF col_count = 0 THEN
        SET @sql = CONCAT('ALTER TABLE ', p_table, ' ADD COLUMN ', p_column, ' ', p_definition);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END//

DELIMITER ;

-- B4: Gorjeta
CALL add_column_if_not_exists('om_market_orders', 'tip_amount', 'DECIMAL(10,2) DEFAULT 0.00 AFTER total');

-- B1: Checkout - campos extras
CALL add_column_if_not_exists('om_market_orders', 'is_pickup', 'TINYINT(1) DEFAULT 0 AFTER notes');
CALL add_column_if_not_exists('om_market_orders', 'schedule_date', 'DATE DEFAULT NULL AFTER is_pickup');
CALL add_column_if_not_exists('om_market_orders', 'schedule_time', 'VARCHAR(10) DEFAULT NULL AFTER schedule_date');

-- A1: Cupom aplicado no pedido
CALL add_column_if_not_exists('om_market_orders', 'coupon_id', 'INT DEFAULT NULL AFTER forma_pagamento');
CALL add_column_if_not_exists('om_market_orders', 'coupon_discount', 'DECIMAL(10,2) DEFAULT 0.00 AFTER coupon_id');

-- C5: Fidelidade
CALL add_column_if_not_exists('om_market_orders', 'loyalty_points_earned', 'INT DEFAULT 0 AFTER coupon_discount');
CALL add_column_if_not_exists('om_market_orders', 'loyalty_points_used', 'INT DEFAULT 0 AFTER loyalty_points_earned');
CALL add_column_if_not_exists('om_market_orders', 'loyalty_discount', 'DECIMAL(10,2) DEFAULT 0.00 AFTER loyalty_points_used');

-- B7: Preferencia de substituicao
CALL add_column_if_not_exists('om_market_order_items', 'accept_substitute', 'TINYINT(1) DEFAULT 0 AFTER observacao');

-- Limpar procedure temporaria
DROP PROCEDURE IF EXISTS add_column_if_not_exists;

-- Tabela de buscas populares para autocomplete (A2)
CREATE TABLE IF NOT EXISTS om_market_search_popular (
    id INT AUTO_INCREMENT PRIMARY KEY,
    term VARCHAR(200) NOT NULL,
    search_count INT DEFAULT 1,
    last_searched_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY idx_term (term),
    KEY idx_count (search_count DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabela de uso de cupons para controle de max_uses_per_user (A1)
CREATE TABLE IF NOT EXISTS om_market_coupon_usage (
    id INT AUTO_INCREMENT PRIMARY KEY,
    coupon_id INT NOT NULL,
    customer_id INT NOT NULL,
    order_id INT DEFAULT NULL,
    used_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_coupon_customer (coupon_id, customer_id),
    KEY idx_coupon (coupon_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabela de pontos de fidelidade (C5)
CREATE TABLE IF NOT EXISTS om_market_loyalty_points (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    points INT NOT NULL DEFAULT 0,
    type ENUM('earned', 'used', 'expired') DEFAULT 'earned',
    order_id INT DEFAULT NULL,
    description VARCHAR(255) DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_customer (customer_id),
    KEY idx_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
