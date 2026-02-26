#!/usr/bin/env php
<?php
/**
 * CRON: Liberar repasses expirados (hold de 2h)
 *
 * Executa a cada 5 minutos via crontab:
 *   every-5-min php /var/www/html/api/mercado/cron/liberar-repasses.php
 *
 * O que faz:
 * 1. Busca repasses com status='hold' onde hold_until <= NOW()
 * 2. Libera cada um (move saldo_pendente → saldo_disponivel)
 * 3. Envia notificacao push ao destinatario
 * 4. Loga resultados
 */

// Prevent web access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

// Lock file to prevent overlapping executions
$lockFile = sys_get_temp_dir() . '/superbora_liberar_repasses.lock';
$lockFp = fopen($lockFile, 'w');
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    echo "[" . date('c') . "] SKIP: Another instance is running\n";
    exit(0);
}

$startTime = microtime(true);
echo "[" . date('c') . "] START: liberar-repasses\n";

try {
    // Load environment & database (without rate limiting)
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
    $db->exec("SET client_encoding TO 'UTF8'");

    // Load OmRepasse
    require_once __DIR__ . '/../../../includes/classes/OmRepasse.php';
    om_repasse()->setDb($db);

    // Load notification helpers
    $notifyFile = __DIR__ . '/../helpers/notify.php';
    $hasNotify = file_exists($notifyFile);
    if ($hasNotify) {
        require_once $notifyFile;
    }

    // Fetch repasses ready to release (max 100 per run)
    $pendentes = om_repasse()->buscarProntosParaLiberar(100);
    $total = count($pendentes);
    $liberados = 0;
    $erros = 0;

    echo "[" . date('c') . "] Found {$total} repasses to release\n";

    foreach ($pendentes as $repasse) {
        $repasseId = (int)$repasse['id'];
        $orderId = (int)$repasse['order_id'];
        $tipo = $repasse['tipo'];
        $destinatarioId = (int)$repasse['destinatario_id'];
        $valor = (float)$repasse['valor_liquido'];

        try {
            $result = om_repasse()->liberar($repasseId);

            if ($result['success']) {
                $liberados++;
                echo "  OK: repasse #{$repasseId} (pedido #{$orderId}, {$tipo} #{$destinatarioId}, R$" . number_format($valor, 2) . ")\n";

                // Send push notification
                if ($hasNotify && om_repasse()->isNotificarLiberacao()) {
                    $valorFormatado = 'R$ ' . number_format($valor, 2, ',', '.');
                    $title = 'Pagamento liberado!';
                    $body = "Seu pagamento de {$valorFormatado} do pedido #{$orderId} foi liberado.";

                    try {
                        switch ($tipo) {
                            case 'mercado':
                                notifyPartner($db, $destinatarioId, $title, $body, '/painel/mercado/financeiro.php', [
                                    'type' => 'repasse_liberado',
                                    'order_id' => $orderId,
                                    'valor' => $valor
                                ]);
                                break;

                            case 'shopper':
                            case 'motorista':
                                // Shoppers/drivers get customer-style push
                                _sendExpoPush($db, $destinatarioId, $tipo === 'shopper' ? 'shopper' : 'motorista', $title, $body, [
                                    'type' => 'repasse_liberado',
                                    'order_id' => $orderId,
                                    'valor' => $valor
                                ]);
                                break;
                        }
                    } catch (Exception $notifErr) {
                        echo "  WARN: notification failed for repasse #{$repasseId}: " . $notifErr->getMessage() . "\n";
                    }
                }
            } else {
                $erros++;
                echo "  FAIL: repasse #{$repasseId}: " . ($result['error'] ?? 'unknown') . "\n";
            }
        } catch (Exception $e) {
            $erros++;
            echo "  ERROR: repasse #{$repasseId}: " . $e->getMessage() . "\n";
            error_log("[cron-repasses] Erro repasse #{$repasseId}: " . $e->getMessage());
        }
    }

    $elapsed = round(microtime(true) - $startTime, 2);
    echo "[" . date('c') . "] DONE: {$liberados}/{$total} liberados, {$erros} erros, {$elapsed}s\n\n";

} catch (Exception $e) {
    echo "[" . date('c') . "] FATAL: " . $e->getMessage() . "\n";
    error_log("[cron-repasses] FATAL: " . $e->getMessage());
    exit(1);
} finally {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    @unlink($lockFile);
}
