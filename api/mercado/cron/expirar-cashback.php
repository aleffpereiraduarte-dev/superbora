#!/usr/bin/env php
<?php
/**
 * CRON: Expirar cashback vencido
 *
 * Executa diariamente as 02:00:
 *   0 2 * * * php /var/www/html/api/mercado/cron/expirar-cashback.php >> /var/log/superbora/cron-cashback.log 2>&1
 *
 * O que faz:
 * 1. Marca cashback_transactions expiradas (expires_at <= NOW())
 * 2. Debita o saldo da cashback_wallet correspondente
 * 3. Notifica o cliente que seu cashback expirou
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$lockFile = sys_get_temp_dir() . '/superbora_expirar_cashback.lock';
$lockFp = fopen($lockFile, 'w');
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    echo "[" . date('c') . "] SKIP: Another instance is running\n";
    exit(0);
}

$startTime = microtime(true);
echo "[" . date('c') . "] START: expirar-cashback\n";

try {
    // Load environment
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

    // Find expired cashback transactions
    $stmt = $db->prepare("
        SELECT id, customer_id, amount, type
        FROM om_cashback_transactions
        WHERE expired = 0
        AND expires_at IS NOT NULL
        AND expires_at <= NOW()
        AND type = 'earned'
        LIMIT 500
    ");
    $stmt->execute();
    $expired = $stmt->fetchAll();

    $total = count($expired);
    $processados = 0;
    $erros = 0;

    echo "[" . date('c') . "] Found {$total} expired cashback transactions\n";

    foreach ($expired as $tx) {
        try {
            $db->beginTransaction();

            $amount = (float)$tx['amount'];
            $customerId = (int)$tx['customer_id'];

            // Mark as expired
            $db->prepare("UPDATE om_cashback_transactions SET expired = 1 WHERE id = ?")
                ->execute([$tx['id']]);

            // Debit wallet balance
            $db->prepare("
                UPDATE om_cashback_wallet
                SET balance = GREATEST(0, balance - ?),
                    total_earned = GREATEST(0, total_earned - ?)
                WHERE customer_id = ?
            ")->execute([$amount, $amount, $customerId]);

            // Record expiration transaction
            $db->prepare("
                INSERT INTO om_cashback_transactions
                (customer_id, type, amount, description, created_at)
                VALUES (?, 'expired', ?, 'Cashback expirado', NOW())
            ")->execute([$customerId, -$amount]);

            $db->commit();
            $processados++;
            echo "  OK: tx #{$tx['id']} customer #{$customerId} R$" . number_format($amount, 2) . "\n";

        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $erros++;
            echo "  ERROR: tx #{$tx['id']}: " . $e->getMessage() . "\n";
        }
    }

    $elapsed = round(microtime(true) - $startTime, 2);
    echo "[" . date('c') . "] DONE: {$processados}/{$total} expirados, {$erros} erros, {$elapsed}s\n\n";

} catch (Exception $e) {
    echo "[" . date('c') . "] FATAL: " . $e->getMessage() . "\n";
    exit(1);
} finally {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    @unlink($lockFile);
}
