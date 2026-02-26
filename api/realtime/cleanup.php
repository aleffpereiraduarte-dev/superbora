<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * CRON: /api/realtime/cleanup.php
 * Limpeza de Eventos Antigos
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Executar via cron a cada hora:
 * 0 * * * * php /var/www/html/api/realtime/cleanup.php
 *
 * Ou via curl:
 * 0 * * * * curl -s https://onemundo.com.br/api/realtime/cleanup.php?key=xxx
 */

// Verificar se esta sendo executado via CLI ou com chave correta
$isCli = (php_sapi_name() === 'cli');
$validKey = ($_GET['key'] ?? '') === ($_ENV['CRON_SECRET'] ?? 'om_cron_secret');

if (!$isCli && !$validKey) {
    http_response_code(403);
    exit('Acesso negado');
}

require_once dirname(__DIR__) . "/mercado/config/database.php";

try {
    $db = getDB();
    $stats = [];

    // 1. Remover eventos com mais de 24 horas
    $stmt = $db->exec("
        DELETE FROM om_realtime_events
        WHERE created_at < NOW() - INTERVAL '24 hours'
    ");
    $stats['events_deleted'] = $stmt;

    // 2. Remover subscriptions antigas
    $stmt = $db->exec("
        DELETE FROM om_realtime_subscriptions
        WHERE disconnected_at IS NOT NULL
        AND disconnected_at < NOW() - INTERVAL '7 days'
    ");
    $stats['subscriptions_deleted'] = $stmt;

    // 3. Marcar conexoes sem heartbeat como desconectadas
    $stmt = $db->exec("
        UPDATE om_realtime_subscriptions
        SET disconnected_at = NOW()
        WHERE disconnected_at IS NULL
        AND last_heartbeat < NOW() - INTERVAL '10 minutes'
    ");
    $stats['stale_connections_closed'] = $stmt;

    // 4. Estatisticas
    $stats['remaining_events'] = $db->query("SELECT COUNT(*) FROM om_realtime_events")->fetchColumn();
    $stats['active_connections'] = $db->query("
        SELECT COUNT(*) FROM om_realtime_subscriptions
        WHERE disconnected_at IS NULL
    ")->fetchColumn();

    $stats['timestamp'] = date('c');

    if ($isCli) {
        echo "Cleanup realizado:\n";
        print_r($stats);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'stats' => $stats], JSON_PRETTY_PRINT);
    }

} catch (Exception $e) {
    error_log("[realtime-cleanup] Erro: " . $e->getMessage());

    if ($isCli) {
        echo "Erro: " . $e->getMessage() . "\n";
        exit(1);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro interno do servidor']);
    }
}
