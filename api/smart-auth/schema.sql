-- Schema para Smart Auth com Claude AI
-- Execute este SQL no banco de dados

-- Tabela de códigos de verificação
CREATE TABLE IF NOT EXISTS om_verification_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    type ENUM('email', 'phone', '2fa') NOT NULL,
    code VARCHAR(10) NOT NULL,
    expires_at DATETIME NOT NULL,
    verified_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_customer_type (customer_id, type),
    INDEX idx_code (code),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabela de análise de risco do cliente
CREATE TABLE IF NOT EXISTS om_customer_risk (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    risk_score INT DEFAULT 0,
    risk_reasons JSON,
    ai_analysis JSON,
    created_at DATETIME NOT NULL,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_customer (customer_id),
    INDEX idx_risk_score (risk_score)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabela de análise de risco de pedidos
CREATE TABLE IF NOT EXISTS om_order_risk (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    customer_id INT NOT NULL,
    risk_level ENUM('low', 'medium', 'high', 'critical') DEFAULT 'low',
    risk_score INT DEFAULT 0,
    flags JSON,
    recommendation ENUM('approve', 'review', 'reject') DEFAULT 'approve',
    ai_analysis JSON,
    reviewed_by INT DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_order (order_id),
    INDEX idx_customer (customer_id),
    INDEX idx_risk_level (risk_level),
    INDEX idx_recommendation (recommendation)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabela de logs de ações inteligentes
CREATE TABLE IF NOT EXISTS om_ai_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    action VARCHAR(50) NOT NULL,
    entity_type VARCHAR(20) NOT NULL,
    entity_id INT NOT NULL,
    input_data JSON,
    output_data JSON,
    tokens_used INT DEFAULT 0,
    response_time_ms INT DEFAULT 0,
    created_at DATETIME NOT NULL,
    INDEX idx_action (action),
    INDEX idx_entity (entity_type, entity_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Adicionar coluna CPF na tabela de clientes se não existir
SET @dbname = DATABASE();
SET @tablename = 'oc_customer';
SET @columnname = 'cpf';
SET @preparedStatement = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE table_schema = @dbname AND table_name = @tablename AND column_name = @columnname) > 0,
    'SELECT 1',
    'ALTER TABLE oc_customer ADD COLUMN cpf VARCHAR(14) DEFAULT NULL AFTER telephone'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Índice para CPF
CREATE INDEX IF NOT EXISTS idx_cpf ON oc_customer(cpf);
