<?php
/**
 * Instalador das tabelas do Smart Auth
 * Execute uma vez: /api/smart-auth/install.php
 */
header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../../database.php';

    $pdo = getConnection();

    $queries = [
        // Tabela de códigos de verificação
        "CREATE TABLE IF NOT EXISTS om_verification_codes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            customer_id INT NOT NULL,
            type ENUM('email', 'phone', '2fa') NOT NULL,
            code VARCHAR(10) NOT NULL,
            expires_at DATETIME NOT NULL,
            verified_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_customer_type (customer_id, type),
            INDEX idx_code (code),
            INDEX idx_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Tabela de análise de risco do cliente
        "CREATE TABLE IF NOT EXISTS om_customer_risk (
            id INT AUTO_INCREMENT PRIMARY KEY,
            customer_id INT NOT NULL,
            risk_score INT DEFAULT 0,
            risk_reasons JSON,
            ai_analysis JSON,
            created_at DATETIME NOT NULL,
            updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_customer (customer_id),
            INDEX idx_risk_score (risk_score)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Tabela de análise de risco de pedidos
        "CREATE TABLE IF NOT EXISTS om_order_risk (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            customer_id INT NOT NULL,
            risk_level ENUM('low', 'medium', 'high', 'critical') DEFAULT 'low',
            risk_score INT DEFAULT 0,
            flags JSON,
            recommendation ENUM('approve', 'review', 'reject') DEFAULT 'approve',
            ai_analysis JSON,
            reviewed_by INT DEFAULT NULL,
            reviewed_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_order (order_id),
            INDEX idx_customer (customer_id),
            INDEX idx_risk_level (risk_level),
            INDEX idx_recommendation (recommendation)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Tabela de logs de ações inteligentes
        "CREATE TABLE IF NOT EXISTS om_ai_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            action VARCHAR(50) NOT NULL,
            entity_type VARCHAR(20) NOT NULL,
            entity_id INT NOT NULL,
            input_data JSON,
            output_data JSON,
            tokens_used INT DEFAULT 0,
            response_time_ms INT DEFAULT 0,
            created_at DATETIME NOT NULL,
            INDEX idx_action (action),
            INDEX idx_entity (entity_type, entity_id),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ];

    $results = [];

    foreach ($queries as $index => $query) {
        try {
            $pdo->exec($query);
            $results[] = ['query' => $index + 1, 'status' => 'success'];
        } catch (PDOException $e) {
            $results[] = ['query' => $index + 1, 'status' => 'error', 'message' => $e->getMessage()];
        }
    }

    // Verificar se coluna CPF existe
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM oc_customer LIKE 'cpf'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE oc_customer ADD COLUMN cpf VARCHAR(14) DEFAULT NULL AFTER telephone");
            $results[] = ['action' => 'add_cpf_column', 'status' => 'success'];
        } else {
            $results[] = ['action' => 'cpf_column', 'status' => 'already_exists'];
        }
    } catch (PDOException $e) {
        $results[] = ['action' => 'cpf_column', 'status' => 'error', 'message' => $e->getMessage()];
    }

    // Criar índice para CPF se não existir
    try {
        $pdo->exec("CREATE INDEX idx_cpf ON oc_customer(cpf)");
        $results[] = ['action' => 'cpf_index', 'status' => 'success'];
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false) {
            $results[] = ['action' => 'cpf_index', 'status' => 'already_exists'];
        } else {
            $results[] = ['action' => 'cpf_index', 'status' => 'error', 'message' => $e->getMessage()];
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Instalação concluída',
        'results' => $results
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
