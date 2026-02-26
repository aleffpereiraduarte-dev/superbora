<?php
/**
 * Includes Config - OneMundo Mercado
 * Configuração para includes
 */

// Carregar configuração principal se não foi carregada
if (!defined('DB_HOST')) {
    require_once dirname(__DIR__) . '/config/database.php';
}

// Iniciar sessão se não iniciada
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) ? 1 : 0);
    ini_set('session.use_strict_mode', 1);
    session_start();
}

// Timezone
date_default_timezone_set('America/Sao_Paulo');

// Error reporting baseado no ambiente
if (defined('APP_DEBUG') && APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
}
