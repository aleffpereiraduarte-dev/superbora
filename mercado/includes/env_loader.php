<?php
require_once dirname(__DIR__) . '/config/database.php';
/**
 * ENV LOADER - Carrega variáveis do arquivo .env
 * Uso: require_once __DIR__ . '/env_loader.php';
 */

if (!function_exists('loadEnv')) {
    function loadEnv($path = null) {
        if ($path === null) {
            $path = dirname(__DIR__) . '/.env';
        }

        if (!file_exists($path)) {
            return false;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            // Ignorar comentários
            if (strpos(trim($line), '#') === 0) {
                continue;
            }

            // Processar linha KEY=VALUE
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);

                // Remover aspas se existirem
                if (preg_match('/^["\'](.*)["\']\s*$/', $value, $matches)) {
                    $value = $matches[1];
                }

                // Definir no ambiente
                if (!empty($key)) {
                    putenv("$key=$value");
                    $_ENV[$key] = $value;
                    $_SERVER[$key] = $value;
                }
            }
        }

        return true;
    }
}

if (!function_exists('env')) {
    function env($key, $default = null) {
        $value = getenv($key);
        if ($value === false) {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? $default;
        }
        return $value !== false ? $value : $default;
    }
}

// Carregar automaticamente
// 1. Primeiro carrega o .env do mercado (variáveis específicas)
loadEnv();

// 2. Depois carrega o .env principal do OpenCart (DB_HOSTNAME, DB_DATABASE, etc)
$mainEnv = dirname(dirname(__DIR__)) . '/.env';
if (file_exists($mainEnv)) {
    loadEnv($mainEnv);
}

// Funções de conveniência para conexão DB
if (!function_exists('getDbConnection')) {
    function getDbConnection() {
        // Usar getPDO() do database.php que respeita DB_DRIVER (pgsql/mysql)
        return getPDO();
    }
}

if (!function_exists('getDbConnectionMysqli')) {
    function getDbConnectionMysqli() {
        static $conn = null;

        if ($conn === null) {
            // Tentar carregar do OpenCart config primeiro
            $oc_config = dirname(dirname(__DIR__)) . '/config.php';
            if (file_exists($oc_config) && !defined('DB_HOSTNAME')) {
                @include_once($oc_config);
            }

            $host = defined('DB_HOSTNAME') ? DB_HOSTNAME : env('DB_HOST', 'localhost');
            $dbname = defined('DB_DATABASE') ? DB_DATABASE : env('DB_NAME', 'love1');
            $user = defined('DB_USERNAME') ? DB_USERNAME : env('DB_USER', 'love1');
            $pass = defined('DB_PASSWORD') ? DB_PASSWORD : DB_PASSWORD;

            $conn = new mysqli($host, $user, $pass, $dbname);
            if ($conn->connect_error) {
                throw new Exception("Database connection failed: " . $conn->connect_error);
            }
            $conn->set_charset(env('DB_CHARSET', 'utf8mb4'));
        }

        return $conn;
    }
}

// Configurar debug baseado no ambiente
if (env('APP_DEBUG', 'false') === 'false' || env('APP_ENV') === 'production') {
    ini_set('display_errors', 0);
    error_reporting(0);
}
