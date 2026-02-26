<?php
/**
 * SuperBora.com.br - Config Mínimo
 * Credenciais para APIs do Mercado
 */

// DB
define('DB_DRIVER', 'mysqli');
define('DB_HOSTNAME', '147.93.12.236');
define('DB_USERNAME', 'love1');
define('DB_PASSWORD', 'Aleff2009@');
define('DB_DATABASE', 'love1');
define('DB_PORT', '3306');
define('DB_PREFIX', 'oc_');

// URLs
define('HTTP_SERVER', 'https://superbora.com.br/');
define('HTTPS_SERVER', 'https://superbora.com.br/');

// Diretórios
define('DIR_APPLICATION', '/var/www/html/mercado/');
define('DIR_SYSTEM', '/var/www/html/mercado/');
define('DIR_IMAGE', '/var/www/html/image/');
define('DIR_STORAGE', '/var/www/html/mercado/storage/');
define('DIR_CACHE', DIR_STORAGE . 'cache/');
define('DIR_LOGS', DIR_STORAGE . 'logs/');
