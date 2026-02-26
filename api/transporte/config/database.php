<?php
/**
 * Configuração do Banco de Dados - API Transporte
 */

// Carrega variáveis de ambiente
$envPath = dirname(__DIR__, 3) . '/.env';
if (file_exists($envPath)) {
    $envFile = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($envFile as $line) {
        if (strpos($line, '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($value);
        }
    }
}

define("DB_HOST", $_ENV['DB_HOSTNAME'] ?? "localhost");
define("DB_NAME", $_ENV['DB_DATABASE'] ?? "love1");
define("DB_USER", $_ENV['DB_USERNAME'] ?? "love1");
define("DB_PASS", $_ENV['DB_PASSWORD'] ?? "");

function getDB() {
    static $db = null;
    if ($db === null) {
        $db = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER, DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]
        );
    }
    return $db;
}

function response($success, $data = null, $message = "", $code = 200) {
    http_response_code($code);
    header("Content-Type: application/json; charset=utf-8");

    // CORS - Domínios permitidos
    $allowedOrigins = explode(',', $_ENV['CORS_ALLOWED_ORIGINS'] ?? 'https://onemundo.com.br,https://www.onemundo.com.br');
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (in_array($origin, $allowedOrigins)) {
        header("Access-Control-Allow-Origin: " . $origin);
    }
    header("Access-Control-Allow-Credentials: true");

    // Sanitizar mensagens de erro 500
    if ($code >= 500 && !empty($message)) {
        error_log("[API Transporte Error {$code}] " . $message);
        $message = "Erro interno do servidor. Tente novamente.";
    }

    echo json_encode([
        "success" => $success,
        "message" => $message,
        "data" => $data,
        "timestamp" => date("c")
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function getInput() {
    $json = file_get_contents("php://input");
    return json_decode($json, true) ?: $_POST;
}

function validarToken($token) {
    // Implementar validação JWT aqui
    return !empty($token);
}
