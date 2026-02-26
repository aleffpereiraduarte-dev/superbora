<?php
require_once dirname(__DIR__, 3) . '/config/database.php';
/**
 * Database Connection - OneMundo Delivery
 * Conexão segura com o banco de dados
 */

// Credenciais do banco
define('DB_HOST', 'localhost');
define('DB_NAME', 'love1');
define('DB_USER', 'love1');
define('DB_PASS', DB_PASSWORD);

// Singleton para conexão PDO
function getDB() {
    static $pdo = null;
    
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ];
            
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            
        } catch (PDOException $e) {
            error_log('Database connection error: ' . $e->getMessage());
            throw new Exception('Erro de conexão com banco de dados');
        }
    }
    
    return $pdo;
}

// Alias para compatibilidade
function getPDO() {
    return getDB();
}

// Verificar se tabela existe
function tableExists($tableName) {
    $pdo = getDB();
    $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
    $stmt->execute([$tableName]);
    return $stmt->rowCount() > 0;
}
