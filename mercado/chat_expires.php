<?php
/**
 * ╔══════════════════════════════════════════════════════════════════════════════╗
 * ║  CRON EXPIRAR CHAT - 60 MINUTOS APÓS ENTREGA                                 ║
 * ║  Executar a cada 1 minuto: * * * * * php /path/to/cron_chat_expires.php      ║
 * ╚══════════════════════════════════════════════════════════════════════════════╝
 */

// Pode rodar via CLI ou HTTP
$is_cli = (php_sapi_name() === 'cli');

if (!$is_cli) {
    header('Content-Type: application/json; charset=utf-8');
}

$oc_root = dirname(dirname(__DIR__));
if (file_exists($oc_root . '/config.php')) {
    require_once($oc_root . '/config.php');
} else {
    $msg = "Config não encontrado";
    echo $is_cli ? "$msg\n" : json_encode(array('error' => $msg));
    exit;
}

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOSTNAME . ";dbname=" . DB_DATABASE . ";charset=utf8mb4",
        DB_USERNAME, DB_PASSWORD,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
    );
} catch (PDOException $e) {
    $msg = "Erro de conexão: " . $e->getMessage();
    echo $is_cli ? "$msg\n" : json_encode(array('error' => $msg));
    exit;
}

$log = array();
$expired_count = 0;

// 1. Buscar pedidos entregues com chat que expirou
$stmt = $pdo->prepare("
    SELECT order_id, order_number, customer_id, shopper_id, customer_name
    FROM om_market_orders
    WHERE status = 'delivered'
    AND chat_enabled = 1
    AND chat_expired = 0
    AND chat_expires_at IS NOT NULL
    AND chat_expires_at <= NOW()
");
$stmt->execute();
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($orders as $order) {
    try {
        // 2. Marcar chat como expirado
        $stmt = $pdo->prepare("
            UPDATE om_market_orders SET
                chat_enabled = 0,
                chat_expired = 1,
                chat_expired_at = NOW(),
                date_modified = NOW()
            WHERE order_id = ?
        ");
        $stmt->execute(array($order['order_id']));
        
        // 3. Enviar mensagem final no chat
        $stmt = $pdo->prepare("
            INSERT INTO om_market_chat (order_id, sender_type, sender_id, sender_name, message, message_type)
            VALUES (?, 'system', 0, 'Sistema', ?, 'status')
        ");
        $stmt->execute(array(
            $order['order_id'],
            "⏰ O chat foi encerrado.\n\nObrigado por comprar no OneMundo! Se precisar de ajuda, entre em contato pelo nosso suporte.\n\n⭐ Não esqueça de avaliar sua experiência!"
        ));
        
        // 4. Notificar cliente para avaliar
        $stmt = $pdo->prepare("
            INSERT INTO om_notifications_queue (user_type, user_id, title, body, icon, data, priority, status)
            VALUES ('customer', ?, '⭐ Avalie sua compra!', 'Como foi sua experiência? Sua opinião é importante!', '⭐', ?, 'normal', 'pending')
        ");
        $stmt->execute(array(
            $order['customer_id'],
            json_encode(array(
                'type' => 'request_rating',
                'order_id' => $order['order_id']
            ))
        ));
        
        $expired_count++;
        $log[] = "Chat expirado: Pedido #" . $order['order_number'];
        
    } catch (Exception $e) {
        $log[] = "Erro no pedido #" . $order['order_number'] . ": " . $e->getMessage();
    }
}

// 5. Resultado
$result = array(
    'success' => true,
    'expired_count' => $expired_count,
    'timestamp' => date('Y-m-d H:i:s'),
    'log' => $log
);

if ($is_cli) {
    echo "=== CRON CHAT EXPIRES ===\n";
    echo "Hora: " . date('Y-m-d H:i:s') . "\n";
    echo "Chats expirados: $expired_count\n";
    foreach ($log as $l) {
        echo "- $l\n";
    }
} else {
    echo json_encode($result);
}
