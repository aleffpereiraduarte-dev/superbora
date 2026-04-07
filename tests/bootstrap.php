<?php
/**
 * PHPUnit bootstrap for SuperBora.
 * Sets test environment, loads .env, and includes the autoloader.
 */

// Load test env file if present, otherwise main .env
$envPath = file_exists(__DIR__ . '/../.env.test')
    ? __DIR__ . '/../.env.test'
    : __DIR__ . '/../.env';

if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            [$k, $v] = explode('=', $line, 2);
            $_ENV[trim($k)] = trim(trim($v), '"\'');
        }
    }
}

// Force test mode
$_ENV['APP_ENV'] = 'test';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

require_once __DIR__ . '/../vendor/autoload.php';
