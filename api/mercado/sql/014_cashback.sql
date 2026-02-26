-- ============================================================================
-- SISTEMA DE CASHBACK COMPLETO
-- ============================================================================
-- Permite configurar cashback por loja ou global da plataforma
-- Cliente ganha % do valor gasto que pode usar em compras futuras
-- ============================================================================

-- Tabela de configuracao de cashback (global ou por loja)
CREATE TABLE IF NOT EXISTS om_cashback_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    partner_id INT DEFAULT NULL COMMENT 'NULL = configuracao global da plataforma',
    cashback_percent DECIMAL(5,2) DEFAULT 5.00 COMMENT 'Porcentagem de cashback (0-100)',
    min_order_value DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Valor minimo do pedido para ganhar cashback',
    max_cashback DECIMAL(10,2) DEFAULT 50.00 COMMENT 'Maximo de cashback por pedido',
    valid_days INT DEFAULT 30 COMMENT 'Dias para usar o cashback antes de expirar',
    status TINYINT(1) DEFAULT 1 COMMENT '1=ativo, 0=inativo',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY idx_partner_unique (partner_id),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Carteira de cashback do cliente (saldo atual)
CREATE TABLE IF NOT EXISTS om_cashback_wallet (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL COMMENT 'ID do cliente',
    balance DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Saldo disponivel de cashback',
    total_earned DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Total ganho historico',
    total_used DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Total usado historico',
    total_expired DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Total expirado historico',
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY idx_customer (customer_id),
    KEY idx_balance (balance)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Transacoes de cashback (creditos, debitos, expiracoes)
CREATE TABLE IF NOT EXISTS om_cashback_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL COMMENT 'ID do cliente',
    order_id INT DEFAULT NULL COMMENT 'ID do pedido relacionado',
    partner_id INT DEFAULT NULL COMMENT 'ID da loja relacionada',
    type ENUM('credit', 'debit', 'expired') NOT NULL COMMENT 'Tipo da transacao',
    amount DECIMAL(10,2) NOT NULL COMMENT 'Valor (sempre positivo)',
    balance_after DECIMAL(10,2) DEFAULT NULL COMMENT 'Saldo apos transacao',
    description VARCHAR(255) DEFAULT NULL COMMENT 'Descricao da transacao',
    expires_at DATE DEFAULT NULL COMMENT 'Data de expiracao (apenas para credits)',
    expired TINYINT(1) DEFAULT 0 COMMENT '1=expirado',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    KEY idx_customer (customer_id),
    KEY idx_order (order_id),
    KEY idx_type (type),
    KEY idx_expires (expires_at, expired),
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Adicionar campo de cashback usado na tabela de pedidos
ALTER TABLE om_market_orders
    ADD COLUMN IF NOT EXISTS cashback_used DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Cashback usado como desconto',
    ADD COLUMN IF NOT EXISTS cashback_earned DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Cashback ganho apos entrega';

-- Configuracao global default (5% de cashback)
INSERT INTO om_cashback_config (partner_id, cashback_percent, min_order_value, max_cashback, valid_days, status)
VALUES (NULL, 5.00, 0.00, 50.00, 30, 1)
ON DUPLICATE KEY UPDATE updated_at = NOW();

-- Procedure para expirar cashback vencido
DELIMITER //
CREATE PROCEDURE IF NOT EXISTS expire_cashback()
BEGIN
    DECLARE done INT DEFAULT FALSE;
    DECLARE v_id INT;
    DECLARE v_customer_id INT;
    DECLARE v_amount DECIMAL(10,2);
    DECLARE v_balance DECIMAL(10,2);

    -- Cursor para pegar transacoes de credito vencidas nao processadas
    DECLARE cur CURSOR FOR
        SELECT t.id, t.customer_id, t.amount
        FROM om_cashback_transactions t
        WHERE t.type = 'credit'
        AND t.expired = 0
        AND t.expires_at IS NOT NULL
        AND t.expires_at < CURDATE();

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;

    OPEN cur;

    read_loop: LOOP
        FETCH cur INTO v_id, v_customer_id, v_amount;
        IF done THEN
            LEAVE read_loop;
        END IF;

        -- Marcar como expirado
        UPDATE om_cashback_transactions SET expired = 1 WHERE id = v_id;

        -- Obter saldo atual
        SELECT COALESCE(balance, 0) INTO v_balance
        FROM om_cashback_wallet WHERE customer_id = v_customer_id;

        -- Criar transacao de expiracao
        INSERT INTO om_cashback_transactions
            (customer_id, type, amount, balance_after, description)
        VALUES
            (v_customer_id, 'expired', v_amount, GREATEST(v_balance - v_amount, 0),
             CONCAT('Cashback expirado (ID: ', v_id, ')'));

        -- Atualizar wallet
        UPDATE om_cashback_wallet
        SET balance = GREATEST(balance - v_amount, 0),
            total_expired = total_expired + v_amount
        WHERE customer_id = v_customer_id;

    END LOOP;

    CLOSE cur;
END //
DELIMITER ;

-- Event para expirar cashback automaticamente (executar diariamente)
CREATE EVENT IF NOT EXISTS evt_expire_cashback
ON SCHEDULE EVERY 1 DAY
STARTS CURRENT_TIMESTAMP
DO CALL expire_cashback();

-- Indices adicionais para performance
CREATE INDEX IF NOT EXISTS idx_transactions_customer_created
    ON om_cashback_transactions (customer_id, created_at DESC);
