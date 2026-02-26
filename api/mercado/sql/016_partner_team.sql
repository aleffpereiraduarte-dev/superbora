-- ============================================================================
-- SISTEMA DE EQUIPE DO PARCEIRO
-- ============================================================================
-- Permite ao parceiro cadastrar membros da equipe com diferentes niveis de acesso
-- admin: acesso total
-- gerente: pode editar produtos/pedidos mas nao configuracoes financeiras
-- atendente: apenas visualiza e gerencia pedidos
-- ============================================================================

CREATE TABLE IF NOT EXISTS om_partner_team (
    id INT AUTO_INCREMENT PRIMARY KEY,
    partner_id INT NOT NULL COMMENT 'ID do parceiro dono da equipe',
    name VARCHAR(100) NOT NULL COMMENT 'Nome do membro',
    email VARCHAR(255) NOT NULL COMMENT 'Email (usado para login)',
    password_hash VARCHAR(255) DEFAULT NULL COMMENT 'Senha hashada (Argon2ID)',
    role ENUM('admin','gerente','atendente') DEFAULT 'atendente' COMMENT 'Nivel de acesso',
    status TINYINT(1) DEFAULT 1 COMMENT '1=ativo, 0=inativo',
    last_login DATETIME DEFAULT NULL COMMENT 'Ultima vez que fez login',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY idx_partner_email (partner_id, email),
    KEY idx_partner_status (partner_id, status),
    KEY idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Comentario sobre permissoes por role:
-- admin: tudo (produtos, pedidos, financeiro, configuracoes, equipe)
-- gerente: produtos, pedidos, promocoes, cupons
-- atendente: apenas pedidos e chat
