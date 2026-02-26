-- ══════════════════════════════════════════════════════════════════════════════
-- Migration 011: Driver reassignment support
-- Adds reassign_count column and status values for auto-reassign workflow
-- Executar apos 010_lojas_demo.sql
-- MySQL 8.4 compativel
-- ══════════════════════════════════════════════════════════════════════════════

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

-- ═══════════════════════════════════════════
-- reassign_count: tracks how many times an order was reassigned to a new driver
-- ═══════════════════════════════════════════
CALL add_column_if_not_exists('om_market_orders', 'reassign_count', 'INT DEFAULT 0 AFTER delivery_driver_id');

-- ═══════════════════════════════════════════
-- Update status ENUM to include awaiting_delivery and pronto_coleta
-- These are used when a driver cancels and order needs reassignment
-- ═══════════════════════════════════════════
ALTER TABLE om_market_orders
  MODIFY COLUMN `status` ENUM(
    'pending','confirmed','shopping','purchased','delivering','out_for_delivery','delivered','cancelled',
    'pendente','aceito','coletando','coleta_finalizada','em_entrega','aguardando_motorista',
    'problema_entrega','cancelado','aguardando_retirada','retirado','ready_for_delivery',
    'preparando','pronto','aguardando_entregador',
    'awaiting_delivery','pronto_coleta','collected'
  ) DEFAULT 'pendente';

-- ═══════════════════════════════════════════
-- Index for faster lookup of orders awaiting driver reassignment
-- ═══════════════════════════════════════════
-- Using a procedure to safely add index
DELIMITER //
DROP PROCEDURE IF EXISTS add_index_if_not_exists//
CREATE PROCEDURE add_index_if_not_exists(
    IN p_table VARCHAR(100),
    IN p_index_name VARCHAR(100),
    IN p_columns VARCHAR(500)
)
BEGIN
    DECLARE idx_count INT;
    SELECT COUNT(*) INTO idx_count
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = p_table
      AND INDEX_NAME = p_index_name;
    IF idx_count = 0 THEN
        SET @sql = CONCAT('CREATE INDEX ', p_index_name, ' ON ', p_table, ' (', p_columns, ')');
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END//
DELIMITER ;

CALL add_index_if_not_exists('om_market_orders', 'idx_reassign_status', '`status`, `reassign_count`');

-- ═══════════════════════════════════════════
-- Cleanup
-- ═══════════════════════════════════════════
DROP PROCEDURE IF EXISTS add_column_if_not_exists;
DROP PROCEDURE IF EXISTS add_index_if_not_exists;
