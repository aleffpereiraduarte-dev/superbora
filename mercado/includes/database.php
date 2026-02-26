<?php
/**
 * Database Include - OneMundo Mercado
 * Wrapper para conexão com banco
 */

// Incluir configuração do banco se não definida
if (!defined('DB_HOST')) {
    require_once dirname(__DIR__) . '/config/database.php';
}

// Se getDB não existe, criar
if (!function_exists('getDB')) {
    function getDB() {
        static $pdo = null;
        
        if ($pdo === null) {
            try {
                $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
                $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]);
            } catch (PDOException $e) {
                error_log('Database error: ' . $e->getMessage());
                throw new Exception('Erro de conexão');
            }
        }
        
        return $pdo;
    }
}

// Conexão global para compatibilidade
$pdo = getDB();
