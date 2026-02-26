<?php
/**
 * Carregador de variáveis de ambiente
 * Lê o arquivo .env e define as variáveis
 */

if (!function_exists('loadEnv')) {
function loadEnv($path = null) {
    $path = $path ?? dirname(__DIR__) . '/.env';

    if (!file_exists($path)) {
        return false;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        // Ignora comentários
        $line = trim($line);
        if (empty($line) || $line[0] === '#') {
            continue;
        }

        // Separa chave=valor
        if (strpos($line, '=') === false) {
            continue;
        }

        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        // Remove aspas do valor
        if (preg_match('/^(["\'])(.*)\\1$/', $value, $matches)) {
            $value = $matches[2];
        }

        // Define apenas se não existir
        if (!getenv($key)) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }

    return true;
}
} // end if !function_exists('loadEnv')

/**
 * Obtém variável de ambiente com fallback
 */
if (!function_exists('env')) {
function env($key, $default = null) {
    $value = getenv($key);

    if ($value === false) {
        return $default;
    }

    // Converte valores especiais
    switch (strtolower($value)) {
        case 'true':
        case '(true)':
            return true;
        case 'false':
        case '(false)':
            return false;
        case 'null':
        case '(null)':
            return null;
        case 'empty':
        case '(empty)':
            return '';
    }

    return $value;
}
} // end if !function_exists('env')

// Auto-carrega se existir .env
loadEnv();
