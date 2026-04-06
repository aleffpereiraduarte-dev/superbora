<?php
/**
 * Post-delivery follow-up — runs every 30 minutes
 * 1. Orders delivered 2-4 hours ago → ask for rating via WhatsApp
 * 2. Orders delivered 5-7 days ago → suggest reorder via WhatsApp
 *
 * crontab: every 30 min — php /var/www/html/api/mercado/cron/post-delivery-followup.php
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/helpers/zapi-whatsapp.php';

// Concurrent execution guard
$lockFile = '/tmp/superbora_cron_post_delivery_followup.lock';
$lockFp = fopen($lockFile, 'w');
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    exit(0);
}

$db = getDB();
$log = function (string $msg) { echo "[" . date('H:i:s') . "] $msg\n"; };
$log("=== Post-delivery follow-up started ===");

// Business hours only (8:00-22:00)
$hour = (int) date('H');
if ($hour < 8 || $hour >= 22) {
    $log("Outside business hours. Exiting.");
    flock($lockFp, LOCK_UN); fclose($lockFp);
    exit(0);
}

$sent = 0;
$maxPerRun = 50;

// ─── 1. Rating request: delivered 2-4 hours ago ─────────────
$ratingStmt = $db->prepare("
    SELECT o.order_id, o.order_number, o.customer_id, o.delivered_at,
           c.name AS customer_name, c.phone,
           p.name AS store_name
    FROM om_market_orders o
    JOIN om_customers c ON c.customer_id = o.customer_id
    JOIN om_market_partners p ON p.partner_id = o.partner_id
    WHERE o.status IN ('entregue')
      AND o.delivered_at BETWEEN NOW() - INTERVAL '4 hours' AND NOW() - INTERVAL '2 hours'
      AND c.phone IS NOT NULL AND c.phone != ''
      AND NOT EXISTS (
          SELECT 1 FROM om_whatsapp_proactive_log l
          WHERE l.customer_id = o.customer_id AND l.message_type = 'post_rating'
            AND l.sent_at > o.delivered_at - INTERVAL '1 hour'
      )
      AND NOT EXISTS (
          SELECT 1 FROM om_whatsapp_proactive_optout oo WHERE oo.customer_id = o.customer_id
      )
    ORDER BY o.delivered_at ASC
    LIMIT ?
");
$ratingStmt->execute([$maxPerRun]);
$ratingOrders = $ratingStmt->fetchAll();
$log("Rating candidates: " . count($ratingOrders));

foreach ($ratingOrders as $order) {
    if ($sent >= $maxPerRun) break;
    $firstName = explode(' ', trim($order['customer_name']))[0];
    $msg = "Oi {$firstName}! Tudo certo com seu pedido da {$order['store_name']}? "
         . "Avalie de 1 a 5 quanto curtiu! Sua opiniao ajuda demais."
         . "\n\n_Pra parar de receber essas msgs, responda *parar*_";

    $result = sendWhatsApp($order['phone'], $msg);
    if ($result['success'] ?? false) {
        $db->prepare("
            INSERT INTO om_whatsapp_proactive_log (customer_id, phone, message_type, message, sent_at)
            VALUES (?, ?, 'post_rating', ?, NOW())
        ")->execute([$order['customer_id'], $order['phone'], $msg]);
        $sent++;
        $log("Rating sent to {$firstName} (#{$order['order_number']})");
    }
    usleep(1500000); // 1.5s throttle
}

// ─── 2. Reorder suggestion: delivered 5-7 days ago ──────────
$remaining = $maxPerRun - $sent;
if ($remaining > 0) {
    $reorderStmt = $db->prepare("
        SELECT o.order_id, o.order_number, o.customer_id, o.delivered_at,
               c.name AS customer_name, c.phone,
               p.name AS store_name
        FROM om_market_orders o
        JOIN om_customers c ON c.customer_id = o.customer_id
        JOIN om_market_partners p ON p.partner_id = o.partner_id
        WHERE o.status IN ('entregue')
          AND o.delivered_at BETWEEN NOW() - INTERVAL '7 days' AND NOW() - INTERVAL '5 days'
          AND c.phone IS NOT NULL AND c.phone != ''
          AND NOT EXISTS (
              SELECT 1 FROM om_whatsapp_proactive_log l
              WHERE l.customer_id = o.customer_id AND l.message_type = 'post_reorder'
                AND l.sent_at > NOW() - INTERVAL '7 days'
          )
          AND NOT EXISTS (
              SELECT 1 FROM om_market_orders o2
              WHERE o2.customer_id = o.customer_id AND o2.status NOT IN ('cancelado','reembolsado')
                AND o2.created_at > o.delivered_at
          )
          AND NOT EXISTS (
              SELECT 1 FROM om_whatsapp_proactive_optout oo WHERE oo.customer_id = o.customer_id
          )
        ORDER BY o.delivered_at ASC
        LIMIT ?
    ");
    $reorderStmt->execute([$remaining]);
    $reorderOrders = $reorderStmt->fetchAll();
    $log("Reorder candidates: " . count($reorderOrders));

    foreach ($reorderOrders as $order) {
        if ($sent >= $maxPerRun) break;
        $firstName = explode(' ', trim($order['customer_name']))[0];
        $msg = "Oi {$firstName}! Que tal pedir da {$order['store_name']} de novo? "
             . "Abre o app e repete seu ultimo pedido em 2 toques!"
             . "\n\n_Pra parar de receber essas msgs, responda *parar*_";

        $result = sendWhatsApp($order['phone'], $msg);
        if ($result['success'] ?? false) {
            $db->prepare("
                INSERT INTO om_whatsapp_proactive_log (customer_id, phone, message_type, message, sent_at)
                VALUES (?, ?, 'post_reorder', ?, NOW())
            ")->execute([$order['customer_id'], $order['phone'], $msg]);
            $sent++;
            $log("Reorder sent to {$firstName} (#{$order['order_number']})");
        }
        usleep(1500000);
    }
}

$log("=== Done. Sent {$sent} messages ===");
flock($lockFp, LOCK_UN);
fclose($lockFp);
