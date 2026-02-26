-- ══════════════════════════════════════════════════════════════════════════════
-- TABELAS DO SISTEMA DE ENTREGA "RECEBE HOJE" - OneMundo
-- ══════════════════════════════════════════════════════════════════════════════

-- Tabela principal de entregas
CREATE TABLE IF NOT EXISTS om_entregas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    customer_id INT NOT NULL,
    seller_id INT NOT NULL,
    ponto_apoio_id INT NULL,
    tipo_entrega ENUM('retirada_ponto', 'recebe_hoje', 'recebe_hoje_direto', 'padrao', 'melhor_envio') NOT NULL DEFAULT 'padrao',
    status VARCHAR(50) NOT NULL DEFAULT 'aguardando_vendedor',
    endereco_cliente TEXT NULL,
    cep_cliente VARCHAR(10) NULL,
    cidade_cliente VARCHAR(100) NULL,
    lat_cliente DECIMAL(10, 8) NULL,
    lng_cliente DECIMAL(11, 8) NULL,
    valor_frete DECIMAL(10, 2) NOT NULL DEFAULT 0,
    valor_produto DECIMAL(10, 2) NOT NULL DEFAULT 0,
    previsao_disponivel DATETIME NULL,
    previsao_entrega DATETIME NULL,
    codigo_retirada VARCHAR(10) NULL,
    entregador_tipo ENUM('boraum', 'uber', '99', 'mototaxi') NULL,
    entregador_id INT NULL,
    entregador_nome VARCHAR(100) NULL,
    entregador_telefone VARCHAR(20) NULL,
    data_enviado_ponto DATETIME NULL,
    data_chegou_ponto DATETIME NULL,
    data_saiu_entrega DATETIME NULL,
    data_entregue DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_order (order_id),
    INDEX idx_customer (customer_id),
    INDEX idx_seller (seller_id),
    INDEX idx_ponto (ponto_apoio_id),
    INDEX idx_status (status),
    INDEX idx_tipo (tipo_entrega)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de handoffs (transferências de custódia)
CREATE TABLE IF NOT EXISTS om_entrega_handoffs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entrega_id INT NOT NULL,
    de_tipo ENUM('vendedor', 'ponto_apoio', 'entregador', 'suporte') NOT NULL,
    de_id INT NULL,
    de_nome VARCHAR(100) NULL,
    para_tipo ENUM('ponto_apoio', 'entregador', 'cliente', 'suporte') NOT NULL,
    para_id INT NULL,
    para_nome VARCHAR(100) NULL,
    status ENUM('pendente', 'aceito', 'recusado') NOT NULL DEFAULT 'pendente',
    data_aceito DATETIME NULL,
    observacao TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_entrega (entrega_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de notificações
CREATE TABLE IF NOT EXISTS om_entrega_notificacoes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entrega_id INT NULL,
    destinatario_tipo ENUM('cliente', 'vendedor', 'ponto_apoio', 'entregador', 'suporte') NOT NULL,
    destinatario_id INT NOT NULL DEFAULT 0,
    titulo VARCHAR(200) NOT NULL,
    mensagem TEXT NOT NULL,
    tipo ENUM('info', 'sucesso', 'acao_necessaria', 'urgente') NOT NULL DEFAULT 'info',
    acao_url VARCHAR(255) NULL,
    enviado TINYINT(1) NOT NULL DEFAULT 0,
    lido TINYINT(1) NOT NULL DEFAULT 0,
    data_lido DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_destinatario (destinatario_tipo, destinatario_id),
    INDEX idx_lido (lido),
    INDEX idx_entrega (entrega_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de chamadas BoraUm/Uber
CREATE TABLE IF NOT EXISTS om_boraum_chamadas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entrega_id INT NOT NULL,
    ponto_apoio_id INT NULL,
    origem_endereco TEXT NOT NULL,
    origem_lat DECIMAL(10, 8) NULL,
    origem_lng DECIMAL(11, 8) NULL,
    destino_endereco TEXT NOT NULL,
    destino_lat DECIMAL(10, 8) NULL,
    destino_lng DECIMAL(11, 8) NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'buscando_motorista',
    valor_estimado DECIMAL(10, 2) NULL,
    valor_final DECIMAL(10, 2) NULL,
    servico_tipo ENUM('boraum', 'uber', '99', 'mototaxi') NOT NULL DEFAULT 'boraum',
    entrega_direta TINYINT(1) NOT NULL DEFAULT 0,
    motorista_id INT NULL,
    motorista_nome VARCHAR(100) NULL,
    motorista_telefone VARCHAR(20) NULL,
    motorista_placa VARCHAR(20) NULL,
    motorista_veiculo VARCHAR(100) NULL,
    motorista_foto VARCHAR(255) NULL,
    data_aceito DATETIME NULL,
    data_coleta DATETIME NULL,
    data_entrega DATETIME NULL,
    fallback_usado TINYINT(1) NOT NULL DEFAULT 0,
    fallback_servico VARCHAR(50) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_entrega (entrega_id),
    INDEX idx_status (status),
    INDEX idx_ponto (ponto_apoio_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de tracking em tempo real
CREATE TABLE IF NOT EXISTS om_entrega_tracking (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entrega_id INT NOT NULL,
    lat DECIMAL(10, 8) NULL,
    lng DECIMAL(11, 8) NULL,
    status VARCHAR(50) NULL,
    mensagem VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_entrega (entrega_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de configurações de entrega
CREATE TABLE IF NOT EXISTS om_entrega_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    chave VARCHAR(100) NOT NULL UNIQUE,
    valor TEXT NULL,
    descricao VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inserir configurações padrão
INSERT IGNORE INTO om_entrega_config (chave, valor, descricao) VALUES
('melhor_envio_token', '', 'Token da API Melhor Envio'),
('boraum_api_url', 'https://api.boraum.com.br/v1', 'URL da API BoraUm'),
('boraum_api_key', '', 'Chave API BoraUm'),
('uber_client_id', '', 'Client ID Uber Delivery'),
('uber_client_secret', '', 'Client Secret Uber Delivery'),
('preco_km_recebe_hoje', '2.00', 'Preço por km para Recebe Hoje'),
('preco_minimo_recebe_hoje', '9.90', 'Preço mínimo Recebe Hoje'),
('preco_km_entrega_direta', '2.50', 'Preço por km para entrega direta'),
('preco_minimo_entrega_direta', '12.90', 'Preço mínimo entrega direta'),
('tempo_retirada_horas', '4', 'Horas para disponibilizar retirada'),
('tempo_entrega_horas', '3', 'Horas para entrega em casa'),
('tempo_entrega_direta_horas', '2', 'Horas para entrega direta'),
('claude_api_key', '', 'API Key da Anthropic Claude para decisor inteligente');

-- Adicionar colunas de ponto de apoio na tabela de vendedores (se não existir)
-- ALTER TABLE oc_purpletree_vendor_stores ADD COLUMN IF NOT EXISTS is_ponto_apoio TINYINT(1) DEFAULT 0;
-- ALTER TABLE oc_purpletree_vendor_stores ADD COLUMN IF NOT EXISTS ponto_apoio_status ENUM('ativo','inativo','suspenso') DEFAULT 'inativo';
-- ALTER TABLE oc_purpletree_vendor_stores ADD COLUMN IF NOT EXISTS ponto_capacidade INT DEFAULT 20;
-- ALTER TABLE oc_purpletree_vendor_stores ADD COLUMN IF NOT EXISTS ponto_pacotes_atuais INT DEFAULT 0;
-- ALTER TABLE oc_purpletree_vendor_stores ADD COLUMN IF NOT EXISTS ponto_horario_abertura TIME DEFAULT '08:00:00';
-- ALTER TABLE oc_purpletree_vendor_stores ADD COLUMN IF NOT EXISTS ponto_horario_fechamento TIME DEFAULT '18:00:00';
-- ALTER TABLE oc_purpletree_vendor_stores ADD COLUMN IF NOT EXISTS ponto_dias_funcionamento VARCHAR(50) DEFAULT 'seg-sab';

SELECT 'Tabelas criadas com sucesso!' AS resultado;
