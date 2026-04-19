<?php
/**
 * Cron: Send "your cesta arrives tomorrow" reminders.
 * Run daily at 18:00 via crontab:
 *   0 18 * * * php /var/www/html/api/mercado/cron/cesta-delivery-reminders.php
 *
 * For each cesta order with delivery_date = TOMORROW and status in
 * ('scheduled', 'preparing'), sends a WhatsApp + push notification:
 *   "📦 Sua cesta [box] chega amanha entre [time window]."
 *
 * Idempotent: skips customers already notified today for the same order
 * by checking om_market_notifications for a matching cesta_day_before entry.
 */

// Suppress rate limiting for cron
$_SERVER['REQUEST_URI'] = '/cron/cesta-delivery-reminders';
$_SERVER['REMOTE_ADDR']  = '127.0.0.1';

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../helpers/cesta-notify.php";

$tomorrow = (new DateTime('tomorrow'))->format('Y-m-d');
$today    = date('Y-m-d');

try {
    $db = getDB();

    $stmt = $db->prepare("
        SELECT o.id          AS order_id,
               o.customer_id,
               o.delivery_date,
               b.name        AS box_name,
               s.delivery_time_pref
        FROM om_cesta_orders o
        LEFT JOIN om_subscription_boxes b ON o.box_id = b.id
        LEFT JOIN om_box_subscriptions s ON o.subscription_id = s.id
        WHERE o.delivery_date = ?
          AND o.status IN ('scheduled', 'preparing', 'ready')
    ");
    $stmt->execute([$tomorrow]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($orders)) {
        error_log("[cesta-reminders] No orders scheduled for {$tomorrow}");
        exit(0);
    }

    // Dedupe: skip orders that already got a day_before notification today
    $dedupe = $db->prepare("
        SELECT COUNT(*) FROM om_market_notifications
        WHERE recipient_id = ?
          AND recipient_type = 'customer'
          AND (data::jsonb->>'type') = 'cesta_day_before'
          AND (data::jsonb->>'order_id')::int = ?
          AND sent_at >= ?::date
    ");

    $sent = 0;
    $skipped = 0;
    $failed = 0;

    foreach ($orders as $ord) {
        $customerId = (int)$ord['customer_id'];
        $orderId    = (int)$ord['order_id'];
        if (!$customerId) {
            $skipped++;
            continue;
        }

        $dedupe->execute([$customerId, $orderId, $today]);
        if ((int)$dedupe->fetchColumn() > 0) {
            $skipped++;
            continue;
        }

        // Convert delivery_time_pref into a readable window
        $pref = strtolower((string)($ord['delivery_time_pref'] ?? 'morning'));
        $window = match ($pref) {
            'morning'   => '08:00 e 12:00',
            'afternoon' => '12:00 e 18:00',
            'evening'   => '18:00 e 22:00',
            'anytime'   => '08:00 e 22:00',
            default     => '08:00 e 12:00',
        };

        $boxName = (string)($ord['box_name'] ?? 'SuperBora');

        try {
            $r = cestaNotifyDayBefore(
                $db,
                $customerId,
                $boxName,
                $ord['delivery_date'],
                $window,
                $orderId
            );
            if (($r['push'] ?? false) || ($r['whatsapp'] ?? false)) {
                $sent++;
            } else {
                $failed++;
            }
        } catch (\Throwable $e) {
            $failed++;
            error_log("[cesta-reminders] notify error for customer {$customerId} order {$orderId}: " . $e->getMessage());
        }
    }

    error_log("[cesta-reminders] Processed " . count($orders) . " orders for {$tomorrow} | sent={$sent} skipped={$skipped} failed={$failed}");

} catch (Exception $e) {
    error_log("[cesta-reminders] ERROR: " . $e->getMessage());
    exit(1);
}
