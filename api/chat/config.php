<?php
/**
 * Configuração do Chat Cliente-Vendedor
 */

// Carregar config principal
require_once dirname(dirname(__DIR__)) . '/config.php';

// Conexão PDO
function getChatDB() {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            "mysql:host=" . DB_HOSTNAME . ";dbname=" . DB_DATABASE . ";charset=utf8mb4",
            DB_USERNAME,
            DB_PASSWORD,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]
        );
    }
    return $pdo;
}

// Resposta JSON padrão
function jsonResponse($success, $data = null, $message = '', $code = 200) {
    http_response_code($code);
    echo json_encode([
        'success' => $success,
        'data' => $data,
        'message' => $message,
        'timestamp' => date('c')
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Headers CORS
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Criar/atualizar tabelas para chat
function ensureChatTables() {
    $pdo = getChatDB();

    // Tabela de conversas
    $tableExists = $pdo->query("SHOW TABLES LIKE 'om_chat_conversations'")->rowCount() > 0;

    if (!$tableExists) {
        $pdo->exec("CREATE TABLE om_chat_conversations (
            conversation_id INT AUTO_INCREMENT PRIMARY KEY,
            customer_id INT NOT NULL,
            seller_id INT DEFAULT NULL,
            product_id INT DEFAULT NULL,
            order_id INT DEFAULT NULL,
            subject VARCHAR(255) DEFAULT NULL,
            status ENUM('active', 'waiting', 'closed') DEFAULT 'active',
            customer_unread INT DEFAULT 0,
            seller_unread INT DEFAULT 0,
            last_message_at DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            closed_at DATETIME DEFAULT NULL,
            INDEX idx_customer (customer_id),
            INDEX idx_seller (seller_id),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } else {
        // Adicionar colunas faltantes
        $columns = $pdo->query("SHOW COLUMNS FROM om_chat_conversations")->fetchAll(PDO::FETCH_COLUMN);

        if (!in_array('seller_id', $columns)) {
            $pdo->exec("ALTER TABLE om_chat_conversations ADD COLUMN seller_id INT DEFAULT NULL AFTER customer_id");
        }
        if (!in_array('product_id', $columns)) {
            $pdo->exec("ALTER TABLE om_chat_conversations ADD COLUMN product_id INT DEFAULT NULL AFTER seller_id");
        }
        if (!in_array('order_id', $columns)) {
            $pdo->exec("ALTER TABLE om_chat_conversations ADD COLUMN order_id INT DEFAULT NULL AFTER product_id");
        }
        if (!in_array('subject', $columns)) {
            $pdo->exec("ALTER TABLE om_chat_conversations ADD COLUMN subject VARCHAR(255) DEFAULT NULL AFTER order_id");
        }
        if (!in_array('customer_unread', $columns)) {
            $pdo->exec("ALTER TABLE om_chat_conversations ADD COLUMN customer_unread INT DEFAULT 0");
        }
        if (!in_array('seller_unread', $columns)) {
            $pdo->exec("ALTER TABLE om_chat_conversations ADD COLUMN seller_unread INT DEFAULT 0");
        }
        if (!in_array('last_message_at', $columns)) {
            $pdo->exec("ALTER TABLE om_chat_conversations ADD COLUMN last_message_at DATETIME DEFAULT NULL");
        }
        if (!in_array('closed_at', $columns)) {
            $pdo->exec("ALTER TABLE om_chat_conversations ADD COLUMN closed_at DATETIME DEFAULT NULL");
        }
    }

    // Tabela de mensagens
    $tableExists = $pdo->query("SHOW TABLES LIKE 'om_chat_messages'")->rowCount() > 0;

    if (!$tableExists) {
        $pdo->exec("CREATE TABLE om_chat_messages (
            message_id INT AUTO_INCREMENT PRIMARY KEY,
            conversation_id INT NOT NULL,
            sender_type ENUM('customer', 'seller', 'system') NOT NULL,
            sender_id INT NOT NULL,
            message TEXT NOT NULL,
            message_type ENUM('text', 'image', 'product', 'order') DEFAULT 'text',
            attachment_url VARCHAR(500) DEFAULT NULL,
            is_read TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_conversation (conversation_id),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } else {
        $columns = $pdo->query("SHOW COLUMNS FROM om_chat_messages")->fetchAll(PDO::FETCH_COLUMN);

        if (!in_array('sender_type', $columns)) {
            $pdo->exec("ALTER TABLE om_chat_messages ADD COLUMN sender_type ENUM('customer', 'seller', 'system') NOT NULL DEFAULT 'customer' AFTER conversation_id");
        }
        if (!in_array('sender_id', $columns)) {
            $pdo->exec("ALTER TABLE om_chat_messages ADD COLUMN sender_id INT NOT NULL DEFAULT 0 AFTER sender_type");
        }
        if (!in_array('message_type', $columns)) {
            $pdo->exec("ALTER TABLE om_chat_messages ADD COLUMN message_type ENUM('text', 'image', 'product', 'order') DEFAULT 'text' AFTER message");
        }
        if (!in_array('attachment_url', $columns)) {
            $pdo->exec("ALTER TABLE om_chat_messages ADD COLUMN attachment_url VARCHAR(500) DEFAULT NULL AFTER message_type");
        }
        if (!in_array('is_read', $columns)) {
            $pdo->exec("ALTER TABLE om_chat_messages ADD COLUMN is_read TINYINT(1) DEFAULT 0");
        }
    }

    // Tabela de status online
    $tableExists = $pdo->query("SHOW TABLES LIKE 'om_chat_online_status'")->rowCount() > 0;

    if (!$tableExists) {
        $pdo->exec("CREATE TABLE om_chat_online_status (
            user_id INT NOT NULL,
            user_type ENUM('customer', 'seller') NOT NULL,
            is_online TINYINT(1) DEFAULT 0,
            is_typing_in INT DEFAULT NULL,
            last_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, user_type),
            INDEX idx_online (is_online)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

// Garantir tabelas existem
try {
    ensureChatTables();
} catch (Exception $e) {
    // Ignora erros se tabelas já existem
}
