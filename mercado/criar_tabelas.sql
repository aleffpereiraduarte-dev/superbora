-- ═══════════════════════════════════════════════════════════════════════════════
-- 🧠 ONE ULTRA BRAIN 2.0 - TABELAS DO SISTEMA
-- ═══════════════════════════════════════════════════════════════════════════════
-- Execute este SQL no banco love1
-- ═══════════════════════════════════════════════════════════════════════════════

-- 1. DADOS PESSOAIS DO CLIENTE (extraídos das conversas)
CREATE TABLE IF NOT EXISTS om_one_cliente_dados (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    chave VARCHAR(100) NOT NULL COMMENT 'nome_pai, alergia, cidade, etc',
    valor TEXT NOT NULL,
    confianca INT DEFAULT 80 COMMENT '0-100 certeza do dado',
    fonte ENUM('conversa', 'cadastro', 'pedido', 'manual') DEFAULT 'conversa',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_customer (customer_id),
    INDEX idx_chave (chave),
    UNIQUE KEY uk_customer_chave (customer_id, chave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. MINI-BRAIN PESSOAL DO CLIENTE
CREATE TABLE IF NOT EXISTS om_one_cliente_brain (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    pergunta TEXT NOT NULL,
    resposta TEXT NOT NULL,
    contexto TEXT COMMENT 'Contexto adicional',
    vezes_usado INT DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_customer (customer_id),
    FULLTEXT idx_busca (pergunta, resposta, contexto)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. HISTÓRICO DE SENTIMENTO
CREATE TABLE IF NOT EXISTS om_one_sentimento_historico (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    session_id VARCHAR(100),
    sentimento ENUM('muito_negativo', 'negativo', 'neutro', 'positivo', 'muito_positivo') DEFAULT 'neutro',
    score INT DEFAULT 0,
    urgente TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_customer (customer_id),
    INDEX idx_sentimento (sentimento),
    INDEX idx_data (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. RESUMO DE CONVERSAS (memória de longo prazo)
CREATE TABLE IF NOT EXISTS om_one_conversa_resumo (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    session_id VARCHAR(100),
    resumo TEXT NOT NULL COMMENT 'Resumo gerado pelo GPT',
    sentimento VARCHAR(50),
    topicos_principais JSON COMMENT '["compras", "receitas", "reclamação"]',
    produtos_mencionados JSON COMMENT '[123, 456, 789]',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_customer (customer_id),
    INDEX idx_data (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. FEEDBACK DO CLIENTE
CREATE TABLE IF NOT EXISTS om_one_feedback (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT DEFAULT 0,
    mensagem_id INT COMMENT 'ID da mensagem/resposta',
    brain_id INT COMMENT 'ID do brain que gerou a resposta',
    tipo ENUM('positivo', 'negativo', 'correcao') NOT NULL,
    comentario TEXT,
    resposta_correta TEXT COMMENT 'Se tipo=correcao, qual seria a resposta certa',
    processado TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tipo (tipo),
    INDEX idx_processado (processado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. ADICIONAR COLUNA needs_embedding NO BRAIN (se não existir)
ALTER TABLE om_one_brain_universal 
ADD COLUMN IF NOT EXISTS needs_embedding TINYINT(1) DEFAULT 0 COMMENT 'Precisa regenerar embedding',
ADD COLUMN IF NOT EXISTS score_qualidade INT DEFAULT 0 COMMENT 'Score de qualidade da resposta 0-100',
ADD COLUMN IF NOT EXISTS updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- 7. ADICIONAR COLUNA brain_id NO EMBEDDINGS (se não existir)
ALTER TABLE om_one_embeddings
ADD COLUMN IF NOT EXISTS brain_id INT DEFAULT NULL COMMENT 'Referência ao registro do brain',
ADD INDEX IF NOT EXISTS idx_brain_id (brain_id);

-- 8. ÍNDICE FULLTEXT NO BRAIN (se não existir)
-- Pode dar erro se já existir, ignore nesse caso
ALTER TABLE om_one_brain_universal 
ADD FULLTEXT INDEX idx_fulltext_busca (pergunta, resposta);

-- 9. TABELA DE RELAÇÕES FAMILIARES
CREATE TABLE IF NOT EXISTS om_one_familia (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    familiar_customer_id INT DEFAULT NULL COMMENT 'Se o familiar também é cliente',
    relacao VARCHAR(50) NOT NULL COMMENT 'pai, mae, filho, esposo, etc',
    nome VARCHAR(100),
    aniversario DATE DEFAULT NULL,
    dados_extras JSON,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. TABELA DE PREVISÃO DE COMPRAS
CREATE TABLE IF NOT EXISTS om_one_previsao_compra (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    product_id INT NOT NULL,
    produto_nome VARCHAR(255),
    media_dias_recompra INT COMMENT 'Média de dias entre compras',
    ultima_compra DATE,
    proxima_previsao DATE,
    notificado TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_customer (customer_id),
    INDEX idx_proxima (proxima_previsao),
    UNIQUE KEY uk_customer_product (customer_id, product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════════════════════
-- DADOS INICIAIS
-- ═══════════════════════════════════════════════════════════════════════════════

-- Marca todos os registros do brain para gerar embedding (se ainda não tiverem)
UPDATE om_one_brain_universal b
LEFT JOIN om_one_embeddings e ON b.id = e.brain_id
SET b.needs_embedding = 1
WHERE e.id IS NULL AND b.needs_embedding = 0;

-- ═══════════════════════════════════════════════════════════════════════════════
-- VERIFICAÇÃO
-- ═══════════════════════════════════════════════════════════════════════════════

SELECT 'Tabelas criadas com sucesso!' as status;

SELECT 
    (SELECT COUNT(*) FROM om_one_cliente_dados) as dados_pessoais,
    (SELECT COUNT(*) FROM om_one_cliente_brain) as brain_pessoal,
    (SELECT COUNT(*) FROM om_one_sentimento_historico) as sentimentos,
    (SELECT COUNT(*) FROM om_one_conversa_resumo) as resumos,
    (SELECT COUNT(*) FROM om_one_feedback) as feedbacks,
    (SELECT COUNT(*) FROM om_one_brain_universal WHERE needs_embedding = 1) as pendentes_embedding;
