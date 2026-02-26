<?php
require_once dirname(__DIR__, 2) . '/config/database.php';
/**
 * CRON: Verificar timers expirados
 * Executar a cada minuto: * * * * * php /path/to/check_timers.php
 */

try {
    $db = new PDO("mysql:host=localhost;dbname=love1;charset=utf8mb4",
        "love1",
        DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    exit("DB Error: " . $e->getMessage());
}

// Buscar pedidos com timer expirado
$stmt = $db->query("
    SELECT order_id, shopper_id, timer_started, timer_duration, timer_extra_time
    FROM om_market_orders
    WHERE timer_started IS NOT NULL
    AND shopping_completed IS NULL
    AND timer_paused = 0
    AND DATE_ADD(timer_started, INTERVAL (timer_duration + timer_extra_time) SECOND) < NOW()
");

$expired = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($expired as $order) {
    // Logar expiração
    $db->prepare("
        INSERT INTO om_timer_log (order_id, worker_id, action, reason)
        VALUES (?, ?, 'expired', 'Timer expirado automaticamente')
    ")->execute([$order["order_id"], $order["shopper_id"]]);
    
    // Atualizar status do pedido (opcional: pode notificar admin)
    $db->prepare("
        UPDATE om_market_orders 
        SET order_status_id = 15 
        WHERE order_id = ? AND shopping_completed IS NULL
    ")->execute([$order["order_id"]]);
    
    echo "Pedido #{$order['order_id']} expirado\n";
}

echo "Verificação concluída: " . count($expired) . " pedidos expirados\n";
