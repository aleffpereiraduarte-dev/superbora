<?php
/**
 * ONEMUNDO BOOTSTRAP
 * Substitui o OpenCart - Sistema 100% proprio
 *
 * USO: require_once __DIR__ . '/includes/om_bootstrap.php';
 */

// Evitar dupla inclusao
if (defined('OM_BOOTSTRAP_LOADED')) return;
define('OM_BOOTSTRAP_LOADED', true);

// Timezone
date_default_timezone_set('America/Sao_Paulo');

// Carregar .env
require_once __DIR__ . '/env_loader.php';

// Configuracoes do banco
define('OM_DB_HOST', env('DB_HOSTNAME', 'localhost'));
define('OM_DB_NAME', env('DB_DATABASE', 'love1'));
define('OM_DB_USER', env('DB_USERNAME', ''));
define('OM_DB_PASS', env('DB_PASSWORD', ''));
define('OM_DB_PREFIX', 'oc_'); // Mantém compatibilidade com tabelas existentes

// Conexao PDO singleton
function om_db() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=" . OM_DB_HOST . ";dbname=" . OM_DB_NAME . ";charset=utf8mb4",
                OM_DB_USER,
                OM_DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch (PDOException $e) {
            error_log("OM DB Error: " . $e->getMessage());
            die("Erro de conexao com banco de dados");
        }
    }
    return $pdo;
}

// Sessao
function om_session_start() {
    if (session_status() === PHP_SESSION_NONE) {
        session_name('OMSESSID');
        session_start();
    }

    // Gerar session_id para o carrinho se nao existir
    if (empty($_SESSION['om_session_id'])) {
        $_SESSION['om_session_id'] = bin2hex(random_bytes(16));
    }

    return $_SESSION['om_session_id'];
}

// Iniciar sessao automaticamente
om_session_start();

// Carregar classes
require_once __DIR__ . '/classes/OmCustomer.php';
require_once __DIR__ . '/classes/OmCart.php';
require_once __DIR__ . '/classes/OmConfig.php';

// Instancias globais
$om_config = new OmConfig();
$om_customer = new OmCustomer();
$om_cart = new OmCart($om_customer);

// Funcoes helper
function om_config() {
    global $om_config;
    return $om_config;
}

function om_customer() {
    global $om_customer;
    return $om_customer;
}

function om_cart() {
    global $om_cart;
    return $om_cart;
}

function om_money($value) {
    return 'R$ ' . number_format((float)$value, 2, ',', '.');
}

function om_escape($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}
