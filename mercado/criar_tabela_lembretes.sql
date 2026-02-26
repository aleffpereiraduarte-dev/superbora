-- TABELA DE LEMBRETES
CREATE TABLE IF NOT EXISTS om_one_lembretes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    texto VARCHAR(500) NOT NULL,
    data_lembrete DATETIME NOT NULL,
    tipo ENUM('whatsapp', 'email', 'push') DEFAULT 'whatsapp',
    telefone VARCHAR(20),
    status ENUM('pendente', 'enviado', 'erro', 'cancelado') DEFAULT 'pendente',
    tentativas INT DEFAULT 0,
    enviado_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_customer (customer_id),
    INDEX idx_status_data (status, data_lembrete)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SELECT 'Tabela om_one_lembretes criada!' as status;
