-- =====================================================
-- EVOLUCAO ONEMUNDO MARKETPLACE - MIGRACAO COMPLETA
-- Data: 2026-01-22
-- Versao: 2.0
-- =====================================================

-- =====================================================
-- 1. ALTERACOES NA TABELA om_vendedores
-- =====================================================

-- Adicionar tipo de vendedor (simples vs loja oficial)
ALTER TABLE om_vendedores
ADD COLUMN IF NOT EXISTS tipo_vendedor ENUM('simples', 'loja_oficial') DEFAULT 'simples' AFTER status,
ADD COLUMN IF NOT EXISTS tem_loja_publica TINYINT(1) DEFAULT 0 AFTER tipo_vendedor,
ADD COLUMN IF NOT EXISTS nivel_verificacao ENUM('basico', 'completo') DEFAULT 'basico' AFTER tem_loja_publica,
ADD COLUMN IF NOT EXISTS score_interno DECIMAL(5,2) DEFAULT 5.00 COMMENT 'Score SLA, fraude, qualidade (0-10)' AFTER nivel_verificacao,
ADD COLUMN IF NOT EXISTS selo_verificado TINYINT(1) DEFAULT 0 AFTER score_interno,
ADD COLUMN IF NOT EXISTS selo_oficial TINYINT(1) DEFAULT 0 AFTER selo_verificado,
ADD COLUMN IF NOT EXISTS total_vendas INT DEFAULT 0 AFTER selo_oficial,
ADD COLUMN IF NOT EXISTS total_pedidos INT DEFAULT 0 AFTER total_vendas,
ADD COLUMN IF NOT EXISTS avaliacao_media DECIMAL(3,2) DEFAULT 0 AFTER total_pedidos,
ADD COLUMN IF NOT EXISTS total_avaliacoes INT DEFAULT 0 AFTER avaliacao_media,
ADD COLUMN IF NOT EXISTS politica_troca TEXT AFTER descricao_loja,
ADD COLUMN IF NOT EXISTS politica_envio TEXT AFTER politica_troca,
ADD COLUMN IF NOT EXISTS horario_atendimento VARCHAR(100) AFTER politica_envio,
ADD COLUMN IF NOT EXISTS tempo_preparo_horas INT DEFAULT 24 AFTER horario_atendimento,
ADD COLUMN IF NOT EXISTS aceita_retirada TINYINT(1) DEFAULT 0 AFTER tempo_preparo_horas,
ADD COLUMN IF NOT EXISTS raio_entrega_km INT DEFAULT 0 COMMENT '0 = sem limite' AFTER aceita_retirada;

-- Indices para busca
ALTER TABLE om_vendedores
ADD INDEX IF NOT EXISTS idx_tipo_vendedor (tipo_vendedor),
ADD INDEX IF NOT EXISTS idx_status_tipo (status, tipo_vendedor),
ADD INDEX IF NOT EXISTS idx_cidade_estado (cidade, estado),
ADD INDEX IF NOT EXISTS idx_slug_loja (slug_loja);

-- =====================================================
-- 2. TABELA DE SUBPEDIDOS POR VENDEDOR
-- =====================================================

CREATE TABLE IF NOT EXISTS om_order_sellers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL COMMENT 'ID do pedido principal OpenCart',
    seller_id INT NOT NULL COMMENT 'ID do vendedor',

    -- Valores
    subtotal DECIMAL(15,2) NOT NULL DEFAULT 0,
    frete DECIMAL(10,2) NOT NULL DEFAULT 0,
    desconto DECIMAL(10,2) NOT NULL DEFAULT 0,
    total DECIMAL(15,2) NOT NULL DEFAULT 0,

    -- Comissao
    comissao_percentual DECIMAL(5,2) NOT NULL DEFAULT 10.00,
    comissao_valor DECIMAL(10,2) NOT NULL DEFAULT 0,
    valor_liquido DECIMAL(15,2) NOT NULL DEFAULT 0,

    -- Status do subpedido
    status ENUM(
        'pendente',
        'pago',
        'em_separacao',
        'enviado_ponto',
        'no_ponto',
        'em_transito',
        'entregue',
        'cancelado',
        'devolvido'
    ) DEFAULT 'pendente',

    -- Logistica
    ponto_apoio_id INT DEFAULT NULL,
    metodo_envio VARCHAR(50) DEFAULT NULL,
    codigo_rastreio VARCHAR(100) DEFAULT NULL,
    etiqueta_url VARCHAR(500) DEFAULT NULL,

    -- Datas
    data_pagamento DATETIME DEFAULT NULL,
    data_separacao DATETIME DEFAULT NULL,
    data_envio_ponto DATETIME DEFAULT NULL,
    data_chegada_ponto DATETIME DEFAULT NULL,
    data_saida_ponto DATETIME DEFAULT NULL,
    data_entrega DATETIME DEFAULT NULL,

    -- QR Code
    qrcode_vendedor VARCHAR(100) DEFAULT NULL COMMENT 'QR para entrega no ponto',
    qrcode_cliente VARCHAR(100) DEFAULT NULL COMMENT 'QR para retirada do cliente',

    -- Timestamps
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_order (order_id),
    INDEX idx_seller (seller_id),
    INDEX idx_status (status),
    INDEX idx_ponto (ponto_apoio_id),

    FOREIGN KEY (seller_id) REFERENCES om_vendedores(vendedor_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 3. TABELA DE BALANCE DO VENDEDOR (COMPRA PROTEGIDA)
-- =====================================================

CREATE TABLE IF NOT EXISTS om_seller_balance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    seller_id INT NOT NULL,
    order_seller_id INT NOT NULL COMMENT 'ID do subpedido',

    -- Valores
    valor_bruto DECIMAL(15,2) NOT NULL,
    comissao DECIMAL(10,2) NOT NULL,
    taxas DECIMAL(10,2) DEFAULT 0,
    valor_liquido DECIMAL(15,2) NOT NULL,

    -- Status de liberacao
    status ENUM(
        'pendente',         -- Aguardando eventos
        'em_transito',      -- Produto saiu do vendedor
        'no_ponto',         -- Chegou no ponto de apoio
        'liberado',         -- Pode ser sacado
        'congelado',        -- Disputa aberta
        'estornado',        -- Devolvido ao cliente
        'pago'              -- Transferido ao vendedor
    ) DEFAULT 'pendente',

    -- Datas
    data_previsao DATE DEFAULT NULL,
    data_liberacao DATETIME DEFAULT NULL,
    data_pagamento DATETIME DEFAULT NULL,

    -- Pagamento
    metodo_pagamento ENUM('pix', 'transferencia') DEFAULT 'pix',
    comprovante_url VARCHAR(500) DEFAULT NULL,

    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_seller (seller_id),
    INDEX idx_status (status),
    INDEX idx_liberacao (data_previsao),

    FOREIGN KEY (seller_id) REFERENCES om_vendedores(vendedor_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 4. TABELA DE DISPUTAS
-- =====================================================

CREATE TABLE IF NOT EXISTS om_disputes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    order_seller_id INT DEFAULT NULL,
    customer_id INT NOT NULL,
    seller_id INT NOT NULL,

    -- Tipo e motivo
    tipo ENUM(
        'nao_recebido',
        'diferente_anunciado',
        'defeito',
        'arrependimento',
        'devolucao',
        'outro'
    ) NOT NULL,

    motivo TEXT NOT NULL,

    -- Status
    status ENUM(
        'aberta',
        'aguardando_vendedor',
        'aguardando_cliente',
        'em_analise',
        'mediacao',
        'resolvida_cliente',
        'resolvida_vendedor',
        'encerrada'
    ) DEFAULT 'aberta',

    -- Evidencias
    evidencias JSON DEFAULT NULL COMMENT 'Array de URLs de fotos/videos',

    -- Resolucao
    resolucao TEXT DEFAULT NULL,
    valor_reembolso DECIMAL(10,2) DEFAULT NULL,
    credito_concedido DECIMAL(10,2) DEFAULT NULL,

    -- Logistica de devolucao
    ponto_apoio_devolucao_id INT DEFAULT NULL,
    codigo_devolucao VARCHAR(50) DEFAULT NULL,
    data_limite_devolucao DATE DEFAULT NULL,

    -- Responsavel
    atendente_id INT DEFAULT NULL,

    -- Timestamps
    data_abertura DATETIME DEFAULT CURRENT_TIMESTAMP,
    data_resposta_vendedor DATETIME DEFAULT NULL,
    data_resolucao DATETIME DEFAULT NULL,

    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_order (order_id),
    INDEX idx_customer (customer_id),
    INDEX idx_seller (seller_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mensagens da disputa
CREATE TABLE IF NOT EXISTS om_dispute_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dispute_id INT NOT NULL,
    sender_type ENUM('customer', 'seller', 'admin') NOT NULL,
    sender_id INT NOT NULL,
    mensagem TEXT NOT NULL,
    anexos JSON DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_dispute (dispute_id),
    FOREIGN KEY (dispute_id) REFERENCES om_disputes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 5. TABELA DE GARANTIAS
-- =====================================================

CREATE TABLE IF NOT EXISTS om_garantias (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    order_product_id INT NOT NULL,
    customer_id INT NOT NULL,
    seller_id INT NOT NULL,
    product_id INT NOT NULL,

    -- Tipo de garantia
    tipo ENUM(
        'garantia_loja',
        'garantia_extendida',
        'seguro_roubo',
        'seguro_dano',
        'seguro_quebra_acidental'
    ) NOT NULL,

    -- Valores
    valor_produto DECIMAL(10,2) NOT NULL,
    valor_garantia DECIMAL(10,2) DEFAULT 0 COMMENT 'Valor pago pela garantia extra',
    valor_cobertura DECIMAL(10,2) NOT NULL COMMENT 'Valor maximo de cobertura',

    -- Vigencia
    vigencia_inicio DATE NOT NULL,
    vigencia_fim DATE NOT NULL,

    -- Status
    status ENUM('ativa', 'utilizada', 'expirada', 'cancelada') DEFAULT 'ativa',

    -- Uso
    data_acionamento DATETIME DEFAULT NULL,
    motivo_acionamento TEXT DEFAULT NULL,
    resolucao TEXT DEFAULT NULL,

    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_customer (customer_id),
    INDEX idx_order (order_id),
    INDEX idx_status (status),
    INDEX idx_vigencia (vigencia_fim)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 6. TABELA DE SLA E COMPENSACOES
-- =====================================================

CREATE TABLE IF NOT EXISTS om_sla_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tipo ENUM('preparacao', 'envio_ponto', 'entrega_local', 'entrega_nacional') NOT NULL,
    cidade_origem VARCHAR(100) DEFAULT NULL,
    cidade_destino VARCHAR(100) DEFAULT NULL,
    prazo_horas INT NOT NULL,
    compensacao_tipo ENUM('credito', 'cupom', 'percentual', 'frete_gratis') DEFAULT 'credito',
    compensacao_valor DECIMAL(10,2) DEFAULT 0,
    compensacao_percentual DECIMAL(5,2) DEFAULT 0,
    ativo TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SLAs padrao
INSERT INTO om_sla_config (tipo, prazo_horas, compensacao_tipo, compensacao_valor) VALUES
('preparacao', 24, 'credito', 5.00),
('envio_ponto', 48, 'credito', 5.00),
('entrega_local', 72, 'credito', 10.00),
('entrega_nacional', 168, 'credito', 10.00);

CREATE TABLE IF NOT EXISTS om_sla_violations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    order_seller_id INT DEFAULT NULL,
    tipo VARCHAR(50) NOT NULL,
    sla_prometido_horas INT NOT NULL,
    sla_real_horas INT NOT NULL,
    compensacao_tipo VARCHAR(20) DEFAULT NULL,
    compensacao_valor DECIMAL(10,2) DEFAULT 0,
    aplicada TINYINT(1) DEFAULT 0,
    customer_id INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_order (order_id),
    INDEX idx_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 7. TABELA DE AGRUPAMENTO POS-CHECKOUT
-- =====================================================

CREATE TABLE IF NOT EXISTS om_order_grouping (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    order_id INT NOT NULL COMMENT 'Pedido principal',

    -- Janela de agrupamento
    janela_inicio DATETIME NOT NULL,
    janela_fim DATETIME NOT NULL,
    janela_minutos INT DEFAULT 60,

    -- Status
    status ENUM('aberta', 'fechada', 'expirada') DEFAULT 'aberta',

    -- Restricoes de agrupamento
    seller_id INT DEFAULT NULL COMMENT 'Se definido, so aceita mesmo vendedor',
    ponto_apoio_id INT DEFAULT NULL COMMENT 'Se definido, so aceita mesmo ponto',
    cidade VARCHAR(100) DEFAULT NULL,

    -- Pagamento
    cartao_token_id INT DEFAULT NULL COMMENT 'Cartao para cobranca automatica',

    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_customer (customer_id),
    INDEX idx_status (status),
    INDEX idx_janela (janela_fim)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pedidos adicionados no agrupamento
CREATE TABLE IF NOT EXISTS om_order_grouping_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    grouping_id INT NOT NULL,
    order_id INT NOT NULL,
    valor DECIMAL(10,2) NOT NULL,
    cobrado TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (grouping_id) REFERENCES om_order_grouping(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 8. TABELA DE CARTOES TOKENIZADOS (COMPRA RAPIDA)
-- =====================================================

CREATE TABLE IF NOT EXISTS om_customer_cards (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,

    -- Dados do cartao (tokenizado)
    gateway ENUM('pagarme', 'mercadopago', 'asaas') NOT NULL,
    token VARCHAR(255) NOT NULL,
    card_id VARCHAR(100) DEFAULT NULL COMMENT 'ID do cartao no gateway',

    -- Info visivel
    bandeira VARCHAR(20) NOT NULL,
    ultimos_digitos VARCHAR(4) NOT NULL,
    nome_titular VARCHAR(100) NOT NULL,
    validade VARCHAR(7) NOT NULL COMMENT 'MM/YYYY',

    -- Preferencias
    is_default TINYINT(1) DEFAULT 0,
    apelido VARCHAR(50) DEFAULT NULL,

    -- Limites de seguranca para compra rapida
    limite_compra_rapida DECIMAL(10,2) DEFAULT 500.00,
    compra_rapida_ativa TINYINT(1) DEFAULT 0,

    -- Status
    status ENUM('ativo', 'inativo', 'expirado') DEFAULT 'ativo',

    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_customer (customer_id),
    INDEX idx_default (customer_id, is_default)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 9. AVALIACOES DE LOJAS (SEPARADO DE PRODUTOS)
-- =====================================================

CREATE TABLE IF NOT EXISTS om_store_reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    seller_id INT NOT NULL,
    customer_id INT NOT NULL,
    order_id INT NOT NULL,

    -- Notas (1-5)
    nota_geral TINYINT NOT NULL,
    nota_atendimento TINYINT DEFAULT NULL,
    nota_embalagem TINYINT DEFAULT NULL,
    nota_prazo TINYINT DEFAULT NULL,

    -- Comentario
    titulo VARCHAR(100) DEFAULT NULL,
    comentario TEXT DEFAULT NULL,

    -- Resposta do vendedor
    resposta TEXT DEFAULT NULL,
    data_resposta DATETIME DEFAULT NULL,

    -- Moderacao
    status ENUM('pendente', 'aprovado', 'rejeitado') DEFAULT 'pendente',

    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_seller (seller_id),
    INDEX idx_customer (customer_id),
    UNIQUE KEY unique_order_review (order_id, seller_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 10. AFILIADOS
-- =====================================================

CREATE TABLE IF NOT EXISTS om_affiliates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,

    -- Dados do afiliado
    codigo VARCHAR(20) NOT NULL UNIQUE,
    nome VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL,
    telefone VARCHAR(20) DEFAULT NULL,

    -- Comissao
    comissao_padrao DECIMAL(5,2) DEFAULT 5.00 COMMENT 'Percentual padrao',

    -- Dados bancarios
    pix_tipo ENUM('cpf', 'cnpj', 'email', 'telefone', 'aleatoria') DEFAULT NULL,
    pix_chave VARCHAR(255) DEFAULT NULL,

    -- Status
    status ENUM('pendente', 'ativo', 'suspenso', 'inativo') DEFAULT 'pendente',

    -- Metricas
    total_cliques INT DEFAULT 0,
    total_vendas INT DEFAULT 0,
    total_comissoes DECIMAL(15,2) DEFAULT 0,

    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_customer (customer_id),
    INDEX idx_codigo (codigo),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Comissoes personalizadas por loja
CREATE TABLE IF NOT EXISTS om_affiliate_commissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    affiliate_id INT NOT NULL,
    seller_id INT DEFAULT NULL COMMENT 'NULL = comissao global',
    category_id INT DEFAULT NULL COMMENT 'NULL = todas categorias',
    comissao_percentual DECIMAL(5,2) NOT NULL,
    ativo TINYINT(1) DEFAULT 1,

    FOREIGN KEY (affiliate_id) REFERENCES om_affiliates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vendas de afiliados
CREATE TABLE IF NOT EXISTS om_affiliate_sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    affiliate_id INT NOT NULL,
    order_id INT NOT NULL,
    customer_id INT NOT NULL,
    seller_id INT DEFAULT NULL,

    valor_venda DECIMAL(15,2) NOT NULL,
    comissao_percentual DECIMAL(5,2) NOT NULL,
    comissao_valor DECIMAL(10,2) NOT NULL,

    status ENUM('pendente', 'aprovada', 'paga', 'cancelada') DEFAULT 'pendente',
    data_aprovacao DATETIME DEFAULT NULL,
    data_pagamento DATETIME DEFAULT NULL,

    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_affiliate (affiliate_id),
    INDEX idx_order (order_id),
    INDEX idx_status (status),

    FOREIGN KEY (affiliate_id) REFERENCES om_affiliates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 11. COMUNICACAO INTERNA
-- =====================================================

CREATE TABLE IF NOT EXISTS om_conversations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT DEFAULT NULL,

    -- Participantes
    customer_id INT DEFAULT NULL,
    seller_id INT DEFAULT NULL,
    admin_id INT DEFAULT NULL,

    -- Tipo
    tipo ENUM('pedido', 'produto', 'suporte', 'disputa') NOT NULL,
    assunto VARCHAR(200) NOT NULL,

    -- Status
    status ENUM('aberta', 'aguardando_resposta', 'resolvida', 'fechada') DEFAULT 'aberta',

    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_order (order_id),
    INDEX idx_customer (customer_id),
    INDEX idx_seller (seller_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS om_conversation_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT NOT NULL,
    sender_type ENUM('customer', 'seller', 'admin', 'system') NOT NULL,
    sender_id INT DEFAULT NULL,
    mensagem TEXT NOT NULL,
    anexos JSON DEFAULT NULL,
    lida TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_conversation (conversation_id),
    FOREIGN KEY (conversation_id) REFERENCES om_conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 12. PONTOS DE APOIO - AJUSTES
-- =====================================================

-- Garantir que a tabela existe com todos os campos
CREATE TABLE IF NOT EXISTS om_pontos_apoio (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vendedor_id INT DEFAULT NULL,

    -- Dados basicos
    nome VARCHAR(100) NOT NULL,
    nome_fantasia VARCHAR(100) DEFAULT NULL,
    responsavel VARCHAR(100) NOT NULL,
    telefone VARCHAR(20) NOT NULL,
    email VARCHAR(255) DEFAULT NULL,

    -- Endereco
    cep VARCHAR(10) DEFAULT NULL,
    endereco VARCHAR(255) NOT NULL,
    numero VARCHAR(20) DEFAULT NULL,
    complemento VARCHAR(100) DEFAULT NULL,
    bairro VARCHAR(100) DEFAULT NULL,
    cidade VARCHAR(100) NOT NULL,
    estado VARCHAR(2) NOT NULL,
    latitude DECIMAL(10, 8) DEFAULT NULL,
    longitude DECIMAL(11, 8) DEFAULT NULL,

    -- Horarios
    horario_abertura TIME DEFAULT '08:00:00',
    horario_fechamento TIME DEFAULT '18:00:00',
    dias_funcionamento VARCHAR(50) DEFAULT 'seg-sab',

    -- Capacidade
    capacidade_pacotes INT DEFAULT 50,
    pacotes_atuais INT DEFAULT 0,

    -- Taxas
    taxa_recebimento DECIMAL(10,2) DEFAULT 2.00,
    taxa_despacho DECIMAL(10,2) DEFAULT 3.00,
    taxa_guarda_diaria DECIMAL(10,2) DEFAULT 1.00,
    dias_guarda_max INT DEFAULT 7,

    -- Servicos
    aceita_coleta TINYINT(1) DEFAULT 1,
    aceita_entrega TINYINT(1) DEFAULT 1,
    aceita_devolucao TINYINT(1) DEFAULT 1,

    -- Status
    status ENUM('pendente', 'ativo', 'inativo', 'suspenso') DEFAULT 'pendente',

    -- Metricas
    total_pacotes_recebidos INT DEFAULT 0,
    total_pacotes_despachados INT DEFAULT 0,
    avaliacao_media DECIMAL(3,2) DEFAULT 5.00,

    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_cidade (cidade, estado),
    INDEX idx_status (status),
    INDEX idx_coords (latitude, longitude),
    INDEX idx_vendedor (vendedor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Adicionar campos extras se nao existirem
ALTER TABLE om_pontos_apoio
ADD COLUMN IF NOT EXISTS cidade VARCHAR(100) NOT NULL DEFAULT '' AFTER bairro,
ADD COLUMN IF NOT EXISTS estado VARCHAR(2) NOT NULL DEFAULT '' AFTER cidade;

-- =====================================================
-- FIM DA MIGRACAO
-- =====================================================
