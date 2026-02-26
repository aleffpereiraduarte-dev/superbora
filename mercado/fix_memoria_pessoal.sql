-- ═══════════════════════════════════════════════════════════════════════════════
-- 🔧 CORREÇÃO - om_one_memoria_pessoal
-- Adiciona coluna session_id que está faltando
-- ═══════════════════════════════════════════════════════════════════════════════

-- 1. Adicionar coluna session_id
ALTER TABLE om_one_memoria_pessoal 
ADD COLUMN IF NOT EXISTS session_id VARCHAR(100) DEFAULT NULL AFTER customer_id;

-- 2. Adicionar índice para session_id
ALTER TABLE om_one_memoria_pessoal 
ADD INDEX IF NOT EXISTS idx_session (session_id);

-- 3. Verificar/adicionar outras colunas que podem estar faltando
ALTER TABLE om_one_memoria_pessoal 
ADD COLUMN IF NOT EXISTS contexto TEXT DEFAULT NULL AFTER valor;

ALTER TABLE om_one_memoria_pessoal 
ADD COLUMN IF NOT EXISTS fonte VARCHAR(50) DEFAULT 'conversa' AFTER contexto;

ALTER TABLE om_one_memoria_pessoal 
ADD COLUMN IF NOT EXISTS vezes_mencionado INT DEFAULT 1 AFTER fonte;

ALTER TABLE om_one_memoria_pessoal 
ADD COLUMN IF NOT EXISTS confianca FLOAT DEFAULT 0.8 AFTER vezes_mencionado;

-- ═══════════════════════════════════════════════════════════════════════════════
-- 🔧 CORREÇÃO - om_one_user_memory (se não existir, criar)
-- ═══════════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS om_one_user_memory (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_id INT NOT NULL DEFAULT 0,
  session_id VARCHAR(100) DEFAULT NULL,
  tipo VARCHAR(50) NOT NULL DEFAULT 'outro',
  chave VARCHAR(100) NOT NULL,
  valor TEXT NOT NULL,
  confianca FLOAT DEFAULT 0.8,
  vezes_mencionado INT DEFAULT 1,
  ultima_mencao DATETIME DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_customer (customer_id),
  INDEX idx_session (session_id),
  INDEX idx_tipo (tipo),
  INDEX idx_chave (chave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════════════════════
-- 🔧 CORREÇÃO - om_one_user_history (se não existir, criar)
-- ═══════════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS om_one_user_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_id INT NOT NULL DEFAULT 0,
  session_id VARCHAR(100) DEFAULT NULL,
  role ENUM('user','assistant') NOT NULL,
  message TEXT NOT NULL,
  intent VARCHAR(50) DEFAULT NULL,
  entities JSON DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_customer (customer_id),
  INDEX idx_session (session_id),
  INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════════════════════
-- 🔧 CORREÇÃO - om_one_client_memory (se não existir, criar)
-- ═══════════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS om_one_client_memory (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_id INT DEFAULT NULL,
  session_id VARCHAR(100) DEFAULT NULL,
  memoria_key VARCHAR(100) NOT NULL,
  memoria_value TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_customer (customer_id),
  INDEX idx_session (session_id),
  INDEX idx_key (memoria_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════════════════════
-- ✅ VERIFICAR
-- ═══════════════════════════════════════════════════════════════════════════════

-- Mostrar estrutura atualizada
DESCRIBE om_one_memoria_pessoal;
