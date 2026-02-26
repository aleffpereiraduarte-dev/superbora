<?php
/**
 * CRON PROCESSAR NOTIFICACOES
 * Executar a cada 1 minuto: * * * * * php /path/to/cron/notifications.php
 */

$is_cli = (php_sapi_name() === 'cli');
if (!$is_cli) header('Content-Type: application/json; charset=utf-8');

$oc_root = dirname(dirname(__DIR__));
if (file_exists($oc_root . '/config.php')) {
    require_once($oc_root . '/config.php');
} else {
    echo $is_cli ? "Config nao encontrado\n" : json_encode(array('error' => 'Config nao encontrado'));
    exit;
}

try {
    $pdo = new PDO(
        "pgsql:host=147.93.12.236;port=5432;dbname=love1",
        'love1', 'Aleff2009@',
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
    );
} catch (PDOException $e) {
    echo $is_cli ? "Erro: " . $e->getMessage() . "\n" : json_encode(array('error' => $e->getMessage()));
    exit;
}

$sent_count = 0;

$stmt = $pdo->prepare("SELECT * FROM om_notifications_queue WHERE status = 'pending' ORDER BY priority DESC, created_at ASC LIMIT 50");
$stmt->execute();
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($notifications as $notif) {
    // TODO: Implementar envio real (Web Push, Firebase, etc)
    $stmt = $pdo->prepare("UPDATE om_notifications_queue SET status = 'sent', sent_at = NOW() WHERE id = ?");
    $stmt->execute(array($notif['id']));
    $sent_count++;
}

// Limpar antigas
$pdo->exec("DELETE FROM om_notifications_queue WHERE created_at < NOW() - INTERVAL '7 days' AND status != 'pending'");

$result = array('success' => true, 'sent' => $sent_count, 'timestamp' => date('Y-m-d H:i:s'));
echo $is_cli ? "Notificacoes processadas: $sent_count\n" : json_encode($result);
