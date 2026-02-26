<?php
/**
 * Conexão com Banco de Dados - OneMundo Workers
 * Database: love1
 * Prefix: om_worker_
 */

// Carregar config centralizado
require_once dirname(__DIR__, 2) . '/config/database.php';
if (!defined('DB_HOST')) define('DB_HOST', DB_HOSTNAME);
if (!defined('DB_NAME')) define('DB_NAME', DB_DATABASE);
if (!defined('DB_USER')) define('DB_USER', DB_USERNAME);
if (!defined('DB_PASS')) define('DB_PASS', DB_PASSWORD);
define('DB_PREFIX', 'om_worker_');

// Conexão PDO
function getDB() {
    static $pdo = null;
    
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch (PDOException $e) {
            // Em produção, logar o erro
            error_log("Database connection failed: " . $e->getMessage());
            return null;
        }
    }
    
    return $pdo;
}

// Helper para resposta JSON
function jsonResponse($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Helper para erro
function jsonError($message, $status = 400) {
    jsonResponse(['success' => false, 'error' => $message], $status);
}

// Helper para sucesso
function jsonSuccess($data = [], $message = 'OK') {
    jsonResponse(array_merge(['success' => true, 'message' => $message], $data));
}

// Validar sessão
function requireAuth() {
    if (session_status() === PHP_SESSION_NONE) {
        session_name('WORKER_SESSID');
        session_start();
    }
    if (!isset($_SESSION['worker_id'])) {
        jsonError('Não autorizado', 401);
    }
    return $_SESSION['worker_id'];
}

// Obter input JSON
function getJsonInput() {
    $input = file_get_contents('php://input');
    return json_decode($input, true) ?? [];
}

// Tabelas do sistema
function table($name) {
    return DB_PREFIX . $name;
}
