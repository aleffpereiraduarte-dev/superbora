-- ═══════════════════════════════════════════════════════════════════════════════
-- ⏰ TABELA DE LEMBRETES ULTRA INTELIGENTES
-- ═══════════════════════════════════════════════════════════════════════════════

DROP TABLE IF EXISTS om_one_lembretes;

CREATE TABLE om_one_lembretes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    texto VARCHAR(500) NOT NULL COMMENT 'O que lembrar',
    data_lembrete DATETIME NOT NULL COMMENT 'Quando enviar',
    tipo ENUM('whatsapp', 'email', 'push') DEFAULT 'whatsapp',
    telefone VARCHAR(20),
    
    -- Frequência
    frequencia ENUM('unica', 'diario', 'semanal', 'mensal') DEFAULT 'unica',
    dias_semana JSON COMMENT '[0,1,3,5] = Dom, Seg, Qua, Sex',
    hora TINYINT COMMENT 'Hora do lembrete',
    minuto TINYINT DEFAULT 0,
    
    -- Status
    status ENUM('pendente', 'enviado', 'erro', 'cancelado') DEFAULT 'pendente',
    tentativas INT DEFAULT 0,
    enviado_at DATETIME DEFAULT NULL,
    
    -- Confirmação
    confirmado TINYINT(1) DEFAULT 0 COMMENT 'Cliente confirmou que viu?',
    confirmado_at DATETIME DEFAULT NULL,
    perguntado TINYINT(1) DEFAULT 0 COMMENT 'Já perguntamos se ele viu?',
    
    -- Timestamps
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_customer (customer_id),
    INDEX idx_status_data (status, data_lembrete),
    INDEX idx_confirmacao (customer_id, status, confirmado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ═══════════════════════════════════════════════════════════════════════════════
-- Verificação
-- ═══════════════════════════════════════════════════════════════════════════════

SELECT 'Tabela om_one_lembretes criada com sucesso!' as status;
DESCRIBE om_one_lembretes;
