-- =====================================================
-- MERCADO PAGE BUILDER - DATABASE SCHEMA
-- Sistema completo de construção de páginas
-- =====================================================

-- Tabela principal de páginas
CREATE TABLE IF NOT EXISTS `om_page_builder` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `slug` VARCHAR(255) NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT,
    `layout_json` LONGTEXT NOT NULL COMMENT 'JSON com estrutura completa da página',
    `settings_json` LONGTEXT COMMENT 'Configurações gerais da página (cores, fontes, etc)',
    `status` ENUM('draft', 'published', 'scheduled') DEFAULT 'draft',
    `is_homepage` TINYINT(1) DEFAULT 0,
    `scheduled_at` DATETIME DEFAULT NULL,
    `created_by` INT(11) DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `slug` (`slug`),
    KEY `status` (`status`),
    KEY `is_homepage` (`is_homepage`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Blocos/Componentes salvos reutilizáveis
CREATE TABLE IF NOT EXISTS `om_page_blocks` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL,
    `type` VARCHAR(50) NOT NULL,
    `config_json` LONGTEXT NOT NULL,
    `thumbnail` VARCHAR(255) DEFAULT NULL,
    `is_global` TINYINT(1) DEFAULT 0 COMMENT 'Bloco aparece em todas as páginas',
    `position` VARCHAR(50) DEFAULT NULL COMMENT 'header, footer, sidebar',
    `status` TINYINT(1) DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `type` (`type`),
    KEY `is_global` (`is_global`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Templates pré-definidos
CREATE TABLE IF NOT EXISTS `om_page_templates` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL,
    `category` VARCHAR(100) DEFAULT 'general',
    `thumbnail` VARCHAR(255) DEFAULT NULL,
    `layout_json` LONGTEXT NOT NULL,
    `settings_json` LONGTEXT,
    `is_default` TINYINT(1) DEFAULT 0,
    `sort_order` INT(11) DEFAULT 0,
    `status` TINYINT(1) DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Biblioteca de mídia para o Page Builder
CREATE TABLE IF NOT EXISTS `om_page_media` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `filename` VARCHAR(255) NOT NULL,
    `original_name` VARCHAR(255) NOT NULL,
    `path` VARCHAR(500) NOT NULL,
    `type` ENUM('image', 'video', 'icon', 'document') DEFAULT 'image',
    `mime_type` VARCHAR(100),
    `size` INT(11) DEFAULT 0,
    `width` INT(11) DEFAULT NULL,
    `height` INT(11) DEFAULT NULL,
    `alt_text` VARCHAR(255) DEFAULT NULL,
    `folder` VARCHAR(255) DEFAULT 'general',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `type` (`type`),
    KEY `folder` (`folder`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Histórico de versões das páginas
CREATE TABLE IF NOT EXISTS `om_page_versions` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `page_id` INT(11) NOT NULL,
    `version` INT(11) NOT NULL,
    `layout_json` LONGTEXT NOT NULL,
    `settings_json` LONGTEXT,
    `created_by` INT(11) DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `page_id` (`page_id`),
    KEY `version` (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Configurações globais do Page Builder
CREATE TABLE IF NOT EXISTS `om_page_settings` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `setting_key` VARCHAR(100) NOT NULL,
    `setting_value` LONGTEXT,
    `setting_type` VARCHAR(50) DEFAULT 'text',
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inserir configurações padrão
INSERT INTO `om_page_settings` (`setting_key`, `setting_value`, `setting_type`) VALUES
('primary_color', '#2563eb', 'color'),
('secondary_color', '#10b981', 'color'),
('accent_color', '#f59e0b', 'color'),
('text_color', '#1f2937', 'color'),
('background_color', '#ffffff', 'color'),
('font_family', 'Inter', 'font'),
('font_size_base', '16', 'number'),
('header_height', '80', 'number'),
('container_width', '1400', 'number'),
('border_radius', '8', 'number')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;

-- Inserir templates padrão
INSERT INTO `om_page_templates` (`name`, `category`, `layout_json`, `settings_json`, `is_default`, `sort_order`) VALUES
('Página em Branco', 'basic', '{"sections":[]}', '{}', 1, 1),
('Landing Page', 'marketing', '{"sections":[{"id":"hero","type":"hero","config":{}},{"id":"features","type":"grid","config":{}}]}', '{}', 0, 2),
('Catálogo de Produtos', 'ecommerce', '{"sections":[{"id":"banner","type":"banner","config":{}},{"id":"products","type":"product-grid","config":{}}]}', '{}', 0, 3),
('Página Institucional', 'content', '{"sections":[{"id":"header","type":"hero","config":{}},{"id":"content","type":"text","config":{}}]}', '{}', 0, 4)
ON DUPLICATE KEY UPDATE `name` = `name`;
