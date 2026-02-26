#!/usr/bin/env php
<?php
/**
 * CRON: Limpar carrinhos abandonados (> 7 dias)
 *
 * Executa diariamente as 03:00:
 *   0 3 * * * php /var/www/html/api/mercado/cron/limpar-carrinhos.php >> /var/log/superbora/cron-carrinhos.log 2>&1
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$startTime = microtime(true);
echo "[" . date('c') . "] START: limpar-carrinhos\n";

try {
    $envPath = __DIR__ . '/../../../.env';
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

    $dbHost = $_ENV["DB_HOSTNAME"] ?? getenv("DB_HOSTNAME") ?: "localhost";
    $dbPort = $_ENV["DB_PORT"] ?? getenv("DB_PORT") ?: "5432";
    $dbName = $_ENV["DB_NAME"] ?? getenv("DB_NAME") ?: "love1";
    $dbUser = $_ENV["DB_USERNAME"] ?? getenv("DB_USERNAME") ?: "";
    $dbPass = $_ENV["DB_PASSWORD"] ?? getenv("DB_PASSWORD") ?: "";

    $dsn = "pgsql:host={$dbHost};port={$dbPort};dbname={$dbName}";
    $db = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);

    // Delete cart item extras first (FK cascade should handle this, but be safe)
    $db->exec("
        DELETE FROM om_cart_item_extras
        WHERE cart_id IN (
            SELECT id FROM om_market_cart
            WHERE created_at < NOW() - INTERVAL '7 days'
        )
    ");

    // Delete abandoned carts older than 7 days
    $stmt = $db->exec("
        DELETE FROM om_market_cart
        WHERE created_at < NOW() - INTERVAL '7 days'
    ");

    $deleted = $stmt;
    $elapsed = round(microtime(true) - $startTime, 2);
    echo "[" . date('c') . "] DONE: {$deleted} carrinhos removidos, {$elapsed}s\n\n";

} catch (Exception $e) {
    echo "[" . date('c') . "] FATAL: " . $e->getMessage() . "\n";
    exit(1);
}
