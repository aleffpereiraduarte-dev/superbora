<?php
/**
 * Cron: Generate daily cesta delivery orders from active subscriptions.
 * Run daily at 00:05 via crontab
 *
 * For each active subscription whose next_delivery matches today,
 * creates an om_cesta_orders record and copies template items into
 * om_cesta_order_items. Then advances next_delivery based on frequency.
 */

// Suppress rate limiting for cron
$_SERVER['REQUEST_URI'] = '/cron/cesta-generate-orders';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../helpers/cesta-notify.php";

$today = date('Y-m-d');

try {
    $db = getDB();

    // Ensure tables exist
    $db->exec("
        CREATE TABLE IF NOT EXISTS om_cesta_orders (
            id SERIAL PRIMARY KEY,
            subscription_id INT NOT NULL,
            customer_id INT NOT NULL,
            box_id INT NOT NULL,
            variant_id INT,
            delivery_date DATE NOT NULL,
            status VARCHAR(30) DEFAULT 'scheduled',
            assembly_location VARCHAR(200),
            delivery_zone_id INT,
            driver_id INT,
            driver_name VARCHAR(100),
            delivery_photo TEXT,
            customer_confirmed BOOLEAN DEFAULT false,
            customer_confirmed_at TIMESTAMP,
            delivered_at TIMESTAMP,
            notes TEXT,
            created_at TIMESTAMP DEFAULT NOW(),
            updated_at TIMESTAMP DEFAULT NOW()
        );
        CREATE TABLE IF NOT EXISTS om_cesta_order_items (
            id SERIAL PRIMARY KEY,
            order_id INT NOT NULL,
            product_name VARCHAR(200) NOT NULL,
            quantity NUMERIC(10,2) NOT NULL DEFAULT 1,
            unit VARCHAR(30) NOT NULL DEFAULT 'un',
            cost_price NUMERIC(10,2) DEFAULT 0,
            was_substituted BOOLEAN DEFAULT false,
            substitution_note TEXT,
            created_at TIMESTAMP DEFAULT NOW()
        );
    ");

    // Find active subscriptions due today
    $stmt = $db->prepare("
        SELECT s.id AS subscription_id, s.customer_id, s.box_id, s.variant_id,
               s.frequency, s.delivery_day
        FROM om_box_subscriptions s
        WHERE s.status = 'active'
          AND s.next_delivery = ?
          AND NOT EXISTS (
              SELECT 1 FROM om_cesta_orders o
              WHERE o.subscription_id = s.id AND o.delivery_date = ?
          )
    ");
    $stmt->execute([$today, $today]);
    $subs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($subs)) {
        error_log("[cesta-cron] No orders to generate for {$today}");
        exit(0);
    }

    $stmtInsert = $db->prepare("
        INSERT INTO om_cesta_orders (subscription_id, customer_id, box_id, variant_id, delivery_date, status)
        VALUES (?, ?, ?, ?, ?, 'scheduled')
        RETURNING id
    ");

    $stmtCopyItems = $db->prepare("
        INSERT INTO om_cesta_order_items (order_id, product_name, quantity, unit, cost_price)
        SELECT ?, ci.product_name, ci.quantity, ci.unit, ci.cost_price
        FROM om_cesta_items ci WHERE ci.box_id = ?
    ");

    // Pull box name for notification message
    $stmtBoxName = $db->prepare("SELECT name FROM om_subscription_boxes WHERE id = ?");

    $created = 0;
    $notified = 0;
    $notifyQueue = [];
    $db->beginTransaction();

    foreach ($subs as $sub) {
        $stmtInsert->execute([
            $sub['subscription_id'],
            $sub['customer_id'],
            $sub['box_id'],
            $sub['variant_id'],
            $today
        ]);
        $orderId = (int)$stmtInsert->fetchColumn();

        // Copy template items
        $stmtCopyItems->execute([$orderId, $sub['box_id']]);

        // Advance next_delivery
        $daysAhead = match ($sub['frequency']) {
            'daily' => 1,
            '3x_week' => 2,
            'weekly' => 7,
            'biweekly' => 14,
            'monthly' => 28,
            default => 7
        };
        $nextDate = (new DateTime($today))->modify("+{$daysAhead} days")->format('Y-m-d');
        $db->prepare("
            UPDATE om_box_subscriptions
            SET next_delivery = ?, renewal_count = renewal_count + 1, last_payment_at = NOW()
            WHERE id = ?
        ")->execute([$nextDate, $sub['subscription_id']]);

        // Collect for post-commit notification dispatch
        $stmtBoxName->execute([$sub['box_id']]);
        $boxName = (string)($stmtBoxName->fetchColumn() ?: 'SuperBora');
        $notifyQueue[] = [
            'customer_id' => (int)$sub['customer_id'],
            'box_name' => $boxName,
            'order_id' => $orderId,
            'delivery_date' => $today,
        ];

        $created++;
    }

    $db->commit();

    // Dispatch notifications AFTER commit so failures don't rollback order creation
    foreach ($notifyQueue as $n) {
        try {
            $r = cestaNotifyOrderGenerated(
                $db,
                $n['customer_id'],
                $n['box_name'],
                $n['delivery_date'],
                $n['order_id']
            );
            if (($r['push'] ?? false) || ($r['whatsapp'] ?? false)) $notified++;
        } catch (\Throwable $e) {
            error_log("[cesta-cron] notify error for customer {$n['customer_id']}: " . $e->getMessage());
        }
    }

    error_log("[cesta-cron] Generated {$created} orders for {$today}, notified {$notified}");

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("[cesta-cron] ERROR: " . $e->getMessage());
    exit(1);
}
