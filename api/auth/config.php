<?php
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/env_loader.php';

// JWT Secret - DEVE ser configurado no .env
$jwtSecret = env('JWT_SECRET', '');
if (empty($jwtSecret) || $jwtSecret === 'CHANGE_ME_IN_PRODUCTION_USE_RANDOM_64_CHAR_STRING') {
    // Fallback apenas para desenvolvimento - NUNCA usar em produção
    $jwtSecret = hash('sha256', DB_PASSWORD . DB_DATABASE . 'onemundo_fallback_2024');
}
define("JWT_SECRET", $jwtSecret);
define("TOKEN_EXPIRY", 86400 * 7); // 7 dias

function getDB() {
    static $db = null;
    if ($db === null) {
        // Detectar driver correto via .env (servidores remotos usam PostgreSQL via PgCat)
        $dbHost = env('DB_HOSTNAME', DB_HOSTNAME);
        $dbPort = env('DB_PORT', defined('DB_PORT') ? DB_PORT : '3306');
        $dbName = env('DB_DATABASE', DB_DATABASE);
        $dbUser = env('DB_USERNAME', DB_USERNAME);
        $dbPass = env('DB_PASSWORD', DB_PASSWORD);
        $dbDriver = env('DB_DRIVER', 'mysqli');

        try {
            if ($dbDriver === 'pgsql' || $dbPort == '6432' || $dbPort == '5432' || $dbPort == '5433') {
                // PostgreSQL (servidores remotos ou quando PgCat/PgBouncer esta configurado)
                $dsn = "pgsql:host={$dbHost};port={$dbPort};dbname={$dbName}";
                $db = new PDO($dsn, $dbUser, $dbPass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]);
                $db->exec("SET client_encoding TO 'UTF8'");
            } else {
                // MySQL/MariaDB (servidor SP original)
                $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
                $db = new PDO($dsn, $dbUser, $dbPass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]);
            }
        } catch (PDOException $e) {
            error_log("[Auth DB] Connection failed: " . $e->getMessage());
            http_response_code(503);
            header("Content-Type: application/json; charset=utf-8");
            echo json_encode(["success" => false, "message" => "Servico temporariamente indisponivel", "data" => null], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    return $db;
}

function response($success, $data = null, $message = "", $code = 200) {
    http_response_code($code);
    header("Content-Type: application/json; charset=utf-8");
    header("Access-Control-Allow-Origin: *");
    echo json_encode(["success" => $success, "message" => $message, "data" => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function getInput() {
    return json_decode(file_get_contents("php://input"), true) ?: $_POST;
}

function gerarToken($user_id, $tipo) {
    $payload = base64_encode(json_encode([
        "user_id" => $user_id,
        "tipo" => $tipo,
        "exp" => time() + TOKEN_EXPIRY
    ]));
    $signature = hash_hmac("sha256", $payload, JWT_SECRET);
    return $payload . "." . $signature;
}
