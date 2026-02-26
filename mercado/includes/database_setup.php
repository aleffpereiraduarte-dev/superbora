<?php
/**
 * Database Setup - OneMundo Mercado
 * Criação de tabelas necessárias
 */

require_once __DIR__ . '/database.php';

function setupTables() {
    $pdo = getDB();
    
    $tables = [
        // Tabela de rate limiting
        "CREATE TABLE IF NOT EXISTS om_rate_limits (
            id INT AUTO_INCREMENT PRIMARY KEY,
            rate_key VARCHAR(255) NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_rate_key (rate_key),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        
        // Tabela de sessões de cliente
        "CREATE TABLE IF NOT EXISTS oc_customer_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            customer_id INT NOT NULL,
            session_token VARCHAR(64) NOT NULL,
            ip_address VARCHAR(45),
            user_agent VARCHAR(255),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME,
            INDEX idx_customer (customer_id),
            INDEX idx_token (session_token),
            INDEX idx_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        
        // Tabela de logs de login
        "CREATE TABLE IF NOT EXISTS om_login_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_type ENUM('customer', 'partner', 'shopper', 'delivery', 'admin') NOT NULL,
            user_id INT,
            ip_address VARCHAR(45),
            success TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (user_type, user_id),
            INDEX idx_ip (ip_address),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ];
    
    foreach ($tables as $sql) {
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            error_log('Table creation error: ' . $e->getMessage());
        }
    }
    
    return true;
}

// Executar setup se chamado diretamente
if (basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    setupTables();
    echo "Tabelas criadas com sucesso!";
}
