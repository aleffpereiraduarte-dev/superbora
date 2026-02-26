<?php
/**
 * Configuração do Banco de Dados - OneMundo
 * SEGURANÇA: Credenciais carregadas via variáveis de ambiente
 */

if (!defined("ONEMUNDO_ACCESS")) {
    define("ONEMUNDO_ACCESS", true);
}

// Carregar variáveis de ambiente
require_once __DIR__ . '/includes/env_loader.php';

// Configuração via variáveis de ambiente - PostgreSQL
$dbConfig = [
    "host" => env('DB_HOSTNAME', '147.93.12.236'),
    "port" => env('DB_PORT', '5432'),
    "name" => env('DB_DATABASE', 'love1'),
    "user" => env('DB_USERNAME', 'love1'),
    "pass" => env('DB_PASSWORD', ''),
];

if (!defined("DB_HOST")) define("DB_HOST", $dbConfig["host"]);
if (!defined("DB_PORT")) define("DB_PORT", $dbConfig["port"]);
if (!defined("DB_NAME")) define("DB_NAME", $dbConfig["name"]);
if (!defined("DB_USER")) define("DB_USER", $dbConfig["user"]);
if (!defined("DB_PASS")) define("DB_PASS", $dbConfig["pass"]);

function getDBConnection() {
    global $dbConfig;
    static $pdo = null;

    if ($pdo === null) {
        try {
            $dsn = "pgsql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['name']}";
            $pdo = new PDO($dsn, $dbConfig["user"], $dbConfig["pass"], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]);
            $pdo->exec("SET client_encoding TO 'UTF8'");
        } catch (PDOException $e) {
            error_log("Erro DB: " . $e->getMessage());
            die("Erro de conexão com o banco de dados");
        }
    }
    return $pdo;
}

function getPDO() { return getDBConnection(); }
function getDB() { return getDBConnection(); }
function getConnection() { return getDBConnection(); }

$pdo = getDBConnection();
$db = $pdo;

date_default_timezone_set("America/Sao_Paulo");
