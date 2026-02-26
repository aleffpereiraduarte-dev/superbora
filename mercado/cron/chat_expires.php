<?php
// CRON: Expirar chats apos 60 minutos
$oc_root = dirname(dirname(__DIR__));
require_once($oc_root . "/config.php");

$pdo = new PDO("pgsql:host=147.93.12.236;port=5432;dbname=love1", 'love1', 'Aleff2009@', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$stmt = $pdo->query("SELECT order_id FROM om_market_orders WHERE status = 'delivered' AND chat_enabled = 1 AND chat_expired = 0 AND chat_expires_at IS NOT NULL AND chat_expires_at < NOW()");

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $pdo->prepare("UPDATE om_market_orders SET chat_enabled = 0, chat_expired = 1 WHERE order_id = ?")->execute([$row["order_id"]]);
    $pdo->prepare("INSERT INTO om_market_chat (order_id, sender_type, sender_id, sender_name, message, message_type, date_added) VALUES (?, 'system', 0, 'Sistema', 'O chat foi encerrado.', 'text', NOW())")->execute([$row["order_id"]]);
}

echo "OK: " . date("Y-m-d H:i:s");
