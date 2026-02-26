-- Migration: Add badge-related fields to om_market_partners
-- Run this migration to add eco_friendly, allows_pickup, has_express columns

-- Add eco_friendly column if not exists
SET @dbname = DATABASE();
SET @tablename = 'om_market_partners';

-- eco_friendly: indicates the store is environmentally conscious
SET @columnname = 'eco_friendly';
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @columnname) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE `', @tablename, '` ADD COLUMN `', @columnname, '` TINYINT(1) DEFAULT 0 COMMENT "Eco-friendly store flag"')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- allows_pickup: indicates the store allows in-store pickup
SET @columnname = 'allows_pickup';
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @columnname) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE `', @tablename, '` ADD COLUMN `', @columnname, '` TINYINT(1) DEFAULT 0 COMMENT "Allows store pickup"')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- has_express: indicates the store offers express delivery
SET @columnname = 'has_express';
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @columnname) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE `', @tablename, '` ADD COLUMN `', @columnname, '` TINYINT(1) DEFAULT 0 COMMENT "Offers express delivery"')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- created_at: if not exists, add for "Novo" badge calculation
SET @columnname = 'created_at';
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @columnname) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE `', @tablename, '` ADD COLUMN `', @columnname, '` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT "Partner creation date"')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add indexes for filtering
CREATE INDEX IF NOT EXISTS idx_partner_eco_friendly ON om_market_partners(eco_friendly);
CREATE INDEX IF NOT EXISTS idx_partner_allows_pickup ON om_market_partners(allows_pickup);
CREATE INDEX IF NOT EXISTS idx_partner_has_express ON om_market_partners(has_express);

-- Sample: Enable some flags for demo purposes (uncomment to run)
-- UPDATE om_market_partners SET eco_friendly = 1 WHERE partner_id IN (1, 3, 5);
-- UPDATE om_market_partners SET allows_pickup = 1 WHERE partner_id IN (1, 2, 4);
-- UPDATE om_market_partners SET has_express = 1 WHERE partner_id IN (2, 3);
