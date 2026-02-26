-- ============================================================================
-- ANTECIPACAO DE RECEBIVEIS E RECONCILIACAO FINANCEIRA
-- ============================================================================
-- Sistema completo para gestao de repasses, antecipacoes e reconciliacao diaria
-- ============================================================================

-- Configuracao de repasses por parceiro
CREATE TABLE IF NOT EXISTS om_payout_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    partner_id INT NOT NULL COMMENT 'ID do parceiro',
    payout_frequency ENUM('daily', 'weekly', 'biweekly', 'monthly') DEFAULT 'weekly' COMMENT 'Frequencia de repasse',
    payout_day INT DEFAULT 1 COMMENT 'Dia do repasse (1-7 para semanal, 1-31 para mensal)',
    min_payout DECIMAL(10,2) DEFAULT 50.00 COMMENT 'Valor minimo para repasse automatico',
    bank_name VARCHAR(100) DEFAULT NULL COMMENT 'Nome do banco',
    bank_agency VARCHAR(20) DEFAULT NULL COMMENT 'Numero da agencia',
    bank_account VARCHAR(30) DEFAULT NULL COMMENT 'Numero da conta',
    bank_account_type ENUM('checking', 'savings') DEFAULT 'checking' COMMENT 'Tipo de conta',
    pix_key VARCHAR(100) DEFAULT NULL COMMENT 'Chave PIX',
    pix_key_type ENUM('cpf', 'cnpj', 'email', 'phone', 'random') DEFAULT NULL COMMENT 'Tipo da chave PIX',
    auto_payout TINYINT(1) DEFAULT 1 COMMENT 'Repasse automatico habilitado',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY idx_partner (partner_id),
    INDEX idx_frequency (payout_frequency),
    INDEX idx_auto_payout (auto_payout)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Repasses programados e realizados
CREATE TABLE IF NOT EXISTS om_payouts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    partner_id INT NOT NULL COMMENT 'ID do parceiro',
    amount DECIMAL(10,2) NOT NULL COMMENT 'Valor bruto do repasse',
    fee DECIMAL(10,2) DEFAULT 0 COMMENT 'Taxa de repasse',
    net_amount DECIMAL(10,2) NOT NULL COMMENT 'Valor liquido (amount - fee)',
    status ENUM('scheduled', 'pending', 'processing', 'completed', 'failed', 'cancelled') DEFAULT 'pending' COMMENT 'Status do repasse',
    scheduled_date DATE NOT NULL COMMENT 'Data programada para o repasse',
    processed_at DATETIME DEFAULT NULL COMMENT 'Data/hora do processamento',
    transaction_id VARCHAR(100) DEFAULT NULL COMMENT 'ID da transacao bancaria',
    receipt_url VARCHAR(255) DEFAULT NULL COMMENT 'URL do comprovante',
    bank_name VARCHAR(100) DEFAULT NULL COMMENT 'Banco usado no repasse',
    pix_key VARCHAR(100) DEFAULT NULL COMMENT 'Chave PIX usada',
    failure_reason VARCHAR(255) DEFAULT NULL COMMENT 'Motivo da falha (se aplicavel)',
    orders_count INT DEFAULT 0 COMMENT 'Quantidade de pedidos incluidos',
    period_start DATE DEFAULT NULL COMMENT 'Inicio do periodo de vendas',
    period_end DATE DEFAULT NULL COMMENT 'Fim do periodo de vendas',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_partner (partner_id),
    INDEX idx_status (status),
    INDEX idx_scheduled (scheduled_date),
    INDEX idx_processed (processed_at),
    INDEX idx_partner_date (partner_id, scheduled_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Antecipacoes de recebiveis
CREATE TABLE IF NOT EXISTS om_anticipations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    partner_id INT NOT NULL COMMENT 'ID do parceiro',
    requested_amount DECIMAL(10,2) NOT NULL COMMENT 'Valor solicitado',
    fee_percent DECIMAL(5,2) NOT NULL COMMENT 'Percentual da taxa',
    fee_amount DECIMAL(10,2) NOT NULL COMMENT 'Valor da taxa',
    net_amount DECIMAL(10,2) NOT NULL COMMENT 'Valor liquido a receber',
    days_ahead INT NOT NULL DEFAULT 7 COMMENT 'Dias de antecipacao',
    status ENUM('pending', 'approved', 'rejected', 'paid', 'cancelled') DEFAULT 'pending' COMMENT 'Status da antecipacao',
    requested_at DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'Data da solicitacao',
    processed_at DATETIME DEFAULT NULL COMMENT 'Data do processamento',
    paid_at DATETIME DEFAULT NULL COMMENT 'Data do pagamento',
    rejection_reason VARCHAR(255) DEFAULT NULL COMMENT 'Motivo da rejeicao',
    transaction_id VARCHAR(100) DEFAULT NULL COMMENT 'ID da transacao de pagamento',
    payout_ids JSON DEFAULT NULL COMMENT 'IDs dos repasses antecipados',

    INDEX idx_partner (partner_id),
    INDEX idx_status (status),
    INDEX idx_requested (requested_at),
    INDEX idx_partner_status (partner_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reconciliacao diaria
CREATE TABLE IF NOT EXISTS om_daily_reconciliation (
    id INT AUTO_INCREMENT PRIMARY KEY,
    partner_id INT NOT NULL COMMENT 'ID do parceiro',
    date DATE NOT NULL COMMENT 'Data da reconciliacao',
    total_orders INT DEFAULT 0 COMMENT 'Total de pedidos no dia',
    total_sales DECIMAL(10,2) DEFAULT 0 COMMENT 'Total bruto de vendas',
    total_commission DECIMAL(10,2) DEFAULT 0 COMMENT 'Total de comissao da plataforma',
    total_delivery_fee DECIMAL(10,2) DEFAULT 0 COMMENT 'Total de taxas de entrega',
    total_discounts DECIMAL(10,2) DEFAULT 0 COMMENT 'Total de descontos concedidos',
    total_refunds DECIMAL(10,2) DEFAULT 0 COMMENT 'Total de estornos/reembolsos',
    total_tips DECIMAL(10,2) DEFAULT 0 COMMENT 'Total de gorjetas',
    net_amount DECIMAL(10,2) DEFAULT 0 COMMENT 'Valor liquido do dia',
    status ENUM('pending', 'verified', 'disputed') DEFAULT 'pending' COMMENT 'Status da reconciliacao',
    verified_at DATETIME DEFAULT NULL COMMENT 'Data da verificacao',
    dispute_reason TEXT DEFAULT NULL COMMENT 'Motivo da disputa',
    notes TEXT DEFAULT NULL COMMENT 'Observacoes',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY idx_partner_date (partner_id, date),
    INDEX idx_status (status),
    INDEX idx_date (date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Historico de disputas de reconciliacao
CREATE TABLE IF NOT EXISTS om_reconciliation_disputes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reconciliation_id INT NOT NULL COMMENT 'ID da reconciliacao',
    partner_id INT NOT NULL COMMENT 'ID do parceiro',
    dispute_type ENUM('missing_order', 'wrong_amount', 'wrong_commission', 'wrong_refund', 'other') NOT NULL,
    description TEXT NOT NULL COMMENT 'Descricao da disputa',
    expected_amount DECIMAL(10,2) DEFAULT NULL COMMENT 'Valor esperado',
    actual_amount DECIMAL(10,2) DEFAULT NULL COMMENT 'Valor registrado',
    status ENUM('open', 'investigating', 'resolved', 'rejected') DEFAULT 'open',
    resolution TEXT DEFAULT NULL COMMENT 'Resolucao da disputa',
    resolved_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_reconciliation (reconciliation_id),
    INDEX idx_partner (partner_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Configuracao de taxas de antecipacao
CREATE TABLE IF NOT EXISTS om_anticipation_fees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    days_min INT NOT NULL COMMENT 'Dias minimos de antecipacao',
    days_max INT NOT NULL COMMENT 'Dias maximos de antecipacao',
    fee_percent DECIMAL(5,2) NOT NULL COMMENT 'Taxa percentual',
    active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY idx_days_range (days_min, days_max)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inserir taxas padrao de antecipacao
INSERT INTO om_anticipation_fees (days_min, days_max, fee_percent) VALUES
    (1, 7, 3.00),
    (8, 14, 5.00),
    (15, 30, 8.00)
ON DUPLICATE KEY UPDATE fee_percent = VALUES(fee_percent);

-- Procedure para gerar reconciliacao diaria automaticamente
DELIMITER //
CREATE PROCEDURE IF NOT EXISTS generate_daily_reconciliation(IN p_partner_id INT, IN p_date DATE)
BEGIN
    DECLARE v_total_orders INT DEFAULT 0;
    DECLARE v_total_sales DECIMAL(10,2) DEFAULT 0;
    DECLARE v_total_commission DECIMAL(10,2) DEFAULT 0;
    DECLARE v_total_delivery_fee DECIMAL(10,2) DEFAULT 0;
    DECLARE v_total_discounts DECIMAL(10,2) DEFAULT 0;
    DECLARE v_total_refunds DECIMAL(10,2) DEFAULT 0;
    DECLARE v_total_tips DECIMAL(10,2) DEFAULT 0;
    DECLARE v_net_amount DECIMAL(10,2) DEFAULT 0;
    DECLARE v_commission_rate DECIMAL(5,2) DEFAULT 12.00;

    -- Obter taxa de comissao do parceiro
    SELECT COALESCE(commission_rate, 12.00) INTO v_commission_rate
    FROM om_market_partners WHERE partner_id = p_partner_id;

    -- Calcular totais do dia
    SELECT
        COUNT(*),
        COALESCE(SUM(total), 0),
        COALESCE(SUM(total * v_commission_rate / 100), 0),
        COALESCE(SUM(delivery_fee), 0),
        COALESCE(SUM(COALESCE(discount, 0)), 0),
        COALESCE(SUM(COALESCE(tip, 0)), 0)
    INTO
        v_total_orders,
        v_total_sales,
        v_total_commission,
        v_total_delivery_fee,
        v_total_discounts,
        v_total_tips
    FROM om_market_orders
    WHERE partner_id = p_partner_id
      AND DATE(date_added) = p_date
      AND status NOT IN ('cancelado', 'cancelled');

    -- Calcular reembolsos
    SELECT COALESCE(SUM(total), 0) INTO v_total_refunds
    FROM om_market_orders
    WHERE partner_id = p_partner_id
      AND DATE(date_added) = p_date
      AND status IN ('cancelado', 'cancelled')
      AND refunded = 1;

    -- Calcular valor liquido
    SET v_net_amount = v_total_sales - v_total_commission - v_total_discounts - v_total_refunds + v_total_tips;

    -- Inserir ou atualizar reconciliacao
    INSERT INTO om_daily_reconciliation (
        partner_id, date, total_orders, total_sales, total_commission,
        total_delivery_fee, total_discounts, total_refunds, total_tips, net_amount
    ) VALUES (
        p_partner_id, p_date, v_total_orders, v_total_sales, v_total_commission,
        v_total_delivery_fee, v_total_discounts, v_total_refunds, v_total_tips, v_net_amount
    )
    ON DUPLICATE KEY UPDATE
        total_orders = v_total_orders,
        total_sales = v_total_sales,
        total_commission = v_total_commission,
        total_delivery_fee = v_total_delivery_fee,
        total_discounts = v_total_discounts,
        total_refunds = v_total_refunds,
        total_tips = v_total_tips,
        net_amount = v_net_amount,
        updated_at = CURRENT_TIMESTAMP;
END //
DELIMITER ;

-- Procedure para calcular proximo repasse
DELIMITER //
CREATE PROCEDURE IF NOT EXISTS calculate_next_payout(IN p_partner_id INT)
BEGIN
    DECLARE v_frequency VARCHAR(20);
    DECLARE v_payout_day INT;
    DECLARE v_min_payout DECIMAL(10,2);
    DECLARE v_next_date DATE;
    DECLARE v_amount DECIMAL(10,2);

    -- Obter configuracao de repasse
    SELECT payout_frequency, payout_day, min_payout
    INTO v_frequency, v_payout_day, v_min_payout
    FROM om_payout_config WHERE partner_id = p_partner_id;

    IF v_frequency IS NULL THEN
        SET v_frequency = 'weekly';
        SET v_payout_day = 1;
        SET v_min_payout = 50.00;
    END IF;

    -- Calcular proxima data de repasse
    CASE v_frequency
        WHEN 'daily' THEN
            SET v_next_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY);
        WHEN 'weekly' THEN
            SET v_next_date = DATE_ADD(CURDATE(), INTERVAL (7 - WEEKDAY(CURDATE()) + v_payout_day - 1) % 7 + 1 DAY);
        WHEN 'biweekly' THEN
            SET v_next_date = DATE_ADD(CURDATE(), INTERVAL (14 - WEEKDAY(CURDATE()) + v_payout_day - 1) % 14 + 1 DAY);
        WHEN 'monthly' THEN
            SET v_next_date = DATE_ADD(LAST_DAY(CURDATE()), INTERVAL v_payout_day DAY);
    END CASE;

    -- Calcular valor disponivel
    SELECT COALESCE(SUM(net_amount), 0) INTO v_amount
    FROM om_daily_reconciliation
    WHERE partner_id = p_partner_id
      AND status = 'verified'
      AND date < v_next_date
      AND id NOT IN (
          SELECT DISTINCT JSON_UNQUOTE(JSON_EXTRACT(payout_ids, '$[*]'))
          FROM om_payouts
          WHERE partner_id = p_partner_id AND status IN ('completed', 'processing')
      );

    -- Criar repasse agendado se valor minimo atingido
    IF v_amount >= v_min_payout THEN
        INSERT INTO om_payouts (partner_id, amount, net_amount, scheduled_date, status)
        VALUES (p_partner_id, v_amount, v_amount, v_next_date, 'scheduled')
        ON DUPLICATE KEY UPDATE amount = v_amount, net_amount = v_amount;
    END IF;
END //
DELIMITER ;

-- Event para gerar reconciliacao diaria de todos os parceiros
DELIMITER //
CREATE EVENT IF NOT EXISTS evt_daily_reconciliation
ON SCHEDULE EVERY 1 DAY
STARTS (TIMESTAMP(CURRENT_DATE) + INTERVAL 1 DAY + INTERVAL 2 HOUR)
DO
BEGIN
    DECLARE done INT DEFAULT FALSE;
    DECLARE v_partner_id INT;
    DECLARE cur CURSOR FOR SELECT partner_id FROM om_market_partners WHERE status = 'active';
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;

    OPEN cur;
    read_loop: LOOP
        FETCH cur INTO v_partner_id;
        IF done THEN
            LEAVE read_loop;
        END IF;
        CALL generate_daily_reconciliation(v_partner_id, DATE_SUB(CURDATE(), INTERVAL 1 DAY));
    END LOOP;
    CLOSE cur;
END //
DELIMITER ;

-- Adicionar campo refunded na tabela de pedidos se nao existir
ALTER TABLE om_market_orders
    ADD COLUMN IF NOT EXISTS refunded TINYINT(1) DEFAULT 0 COMMENT 'Pedido foi reembolsado',
    ADD COLUMN IF NOT EXISTS refund_amount DECIMAL(10,2) DEFAULT NULL COMMENT 'Valor do reembolso',
    ADD COLUMN IF NOT EXISTS refund_reason VARCHAR(255) DEFAULT NULL COMMENT 'Motivo do reembolso',
    ADD COLUMN IF NOT EXISTS refund_date DATETIME DEFAULT NULL COMMENT 'Data do reembolso';
