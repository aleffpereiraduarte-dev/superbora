-- ══════════════════════════════════════════════════════════════════════════════
-- Migration 008: iFood-like Features para SuperBora
-- MySQL 8.4 compativel
-- ══════════════════════════════════════════════════════════════════════════════

-- Helper procedure para adicionar coluna se nao existir
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

DROP PROCEDURE IF EXISTS add_index_if_not_exists//
CREATE PROCEDURE add_index_if_not_exists(
    IN p_table VARCHAR(100),
    IN p_index VARCHAR(100),
    IN p_columns VARCHAR(500)
)
BEGIN
    SET @idx_exists = (
        SELECT COUNT(*) FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = p_table AND index_name = p_index
    );
    IF @idx_exists = 0 THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD INDEX `', p_index, '` (', p_columns, ')');
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END//
DELIMITER ;

-- ═══════════════════════════════════════════
-- Feature 6: Pausar item (om_market_products)
-- ═══════════════════════════════════════════
CALL add_column_if_not_exists('om_market_products', 'available', 'TINYINT(1) DEFAULT 1');
CALL add_column_if_not_exists('om_market_products', 'unavailable_at', 'DATETIME DEFAULT NULL');
CALL add_index_if_not_exists('om_market_products', 'idx_available', '`partner_id`, `available`, `status`');

-- ═══════════════════════════════════════════
-- Feature 4: Fluxo restaurante - status ENUM
-- ═══════════════════════════════════════════
-- Adicionar 'preparando','pronto','aguardando_entregador' ao ENUM existente
ALTER TABLE om_market_orders
  MODIFY COLUMN `status` ENUM(
    'pending','confirmed','shopping','purchased','delivering','out_for_delivery','delivered','cancelled',
    'pendente','aceito','coletando','coleta_finalizada','em_entrega','aguardando_motorista',
    'problema_entrega','cancelado','aguardando_retirada','retirado','ready_for_delivery',
    'preparando','pronto','aguardando_entregador'
  ) DEFAULT 'pendente';

-- Colunas faltantes em om_market_orders
CALL add_column_if_not_exists('om_market_orders', 'ready_at', 'DATETIME DEFAULT NULL');
CALL add_column_if_not_exists('om_market_orders', 'partner_categoria', 'VARCHAR(50) DEFAULT NULL');
CALL add_index_if_not_exists('om_market_orders', 'idx_timer_expires', '`status`, `timer_expires`');
CALL add_index_if_not_exists('om_market_orders', 'idx_partner_updated', '`partner_id`, `date_modified`');

-- ═══════════════════════════════════════════
-- Feature 3: Complementos/Adicionais
-- ═══════════════════════════════════════════
CREATE TABLE IF NOT EXISTS om_product_option_groups (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  partner_id INT NOT NULL,
  name VARCHAR(100) NOT NULL,
  required TINYINT(1) DEFAULT 0,
  min_select INT DEFAULT 0,
  max_select INT DEFAULT 1,
  sort_order INT DEFAULT 0,
  active TINYINT(1) DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_product (product_id),
  INDEX idx_partner (partner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS om_product_options (
  id INT AUTO_INCREMENT PRIMARY KEY,
  group_id INT NOT NULL,
  name VARCHAR(150) NOT NULL,
  price_extra DECIMAL(10,2) DEFAULT 0.00,
  available TINYINT(1) DEFAULT 1,
  sort_order INT DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_group (group_id),
  FOREIGN KEY (group_id) REFERENCES om_product_option_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS om_order_item_options (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_item_id INT NOT NULL,
  option_id INT DEFAULT NULL,
  option_group_name VARCHAR(100) NOT NULL,
  option_name VARCHAR(150) NOT NULL,
  price_extra DECIMAL(10,2) DEFAULT 0.00,
  INDEX idx_order_item (order_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Observacao por item do pedido
CALL add_column_if_not_exists('om_market_order_items', 'observacao', 'TEXT DEFAULT NULL');

-- ═══════════════════════════════════════════
-- Feature 2: Upload tracking
-- ═══════════════════════════════════════════
CREATE TABLE IF NOT EXISTS om_uploads (
  id INT AUTO_INCREMENT PRIMARY KEY,
  partner_id INT NOT NULL,
  type ENUM('product','banner','logo') NOT NULL,
  entity_id INT DEFAULT NULL,
  filename VARCHAR(255) NOT NULL,
  path VARCHAR(500) NOT NULL,
  original_name VARCHAR(255) DEFAULT NULL,
  file_size INT DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_partner (partner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ═══════════════════════════════════════════
-- Feature 7: Push parceiro
-- ═══════════════════════════════════════════
-- Adicionar 'parceiro' e 'admin' ao ENUM user_type
ALTER TABLE om_push_subscriptions
  MODIFY COLUMN `user_type` ENUM('customer','motorista','shopper','parceiro','admin','guest') DEFAULT 'guest';
CALL add_index_if_not_exists('om_push_subscriptions', 'idx_user_type_customer', '`user_type`, `customer_id`, `is_active`');

-- Cleanup helper procedures
DROP PROCEDURE IF EXISTS add_column_if_not_exists;
DROP PROCEDURE IF EXISTS add_index_if_not_exists;
