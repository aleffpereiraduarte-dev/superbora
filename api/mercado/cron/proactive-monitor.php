#!/usr/bin/env php
<?php
/**
 * Proactive Order Monitor — runs every 2 minutes via cron
 * Detects late/stuck orders, notifies customers, offers compensation.
 *
 * Crontab: STAR/2 * * * * php /var/www/html/api/mercado/cron/proactive-monitor.php
 *   (onde STAR = asterisco)
 *
 * Checks:
 * 1. No Accept (>10 min)        — status='confirmado', no partner acceptance
 * 2. No Preparation (>25 min)   — status='aceito', partner accepted but not preparing
 * 3. Delivery Stuck (>40 min)   — status='pronto', ready but not picked up
 * 4. Very Late (>60 min total)  — any non-delivered order older than 60 min
 *
 * Actions: WhatsApp notification, push notification, auto-cashback compensation
 */

// Prevent web access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

// Set DOCUMENT_ROOT for helpers that depend on it (e.g., zapi-whatsapp.php env loading)
if (empty($_SERVER['DOCUMENT_ROOT'])) {
    $_SERVER['DOCUMENT_ROOT'] = '/var/www/html';
}

require_once dirname(__DIR__) . '/config/database.php';

// Optional helpers — wrapped in file_exists to never break
if (file_exists(dirname(__DIR__) . '/helpers/zapi-whatsapp.php')) {
    require_once dirname(__DIR__) . '/helpers/zapi-whatsapp.php';
}
if (file_exists(dirname(__DIR__) . '/helpers/eta-calculator.php')) {
    require_once dirname(__DIR__) . '/helpers/eta-calculator.php';
}
if (file_exists(dirname(__DIR__) . '/helpers/cashback.php')) {
    require_once dirname(__DIR__) . '/helpers/cashback.php';
}
if (file_exists(dirname(__DIR__) . '/helpers/notify.php')) {
    require_once dirname(__DIR__) . '/helpers/notify.php';
}

// ─── Concurrent execution guard ─────────────────────────────
$lockFile = '/tmp/superbora_cron_proactive_monitor.lock';
$lockFp = fopen($lockFile, 'w');
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    echo "[" . date('H:i:s') . "] Another proactive-monitor instance is running. Exiting.\n";
    exit(0);
}

$db = getDB();
$log = function (string $msg) {
    $formatted = "[" . date('H:i:s') . "] $msg";
    echo $formatted . "\n";
    error_log("[proactive-monitor] $msg");
};

$log("=== Proactive order monitor started ===");

$totalChecked = 0;
$totalAlerts = 0;
$totalNotifications = 0;

// ═══════════════════════════════════════════════════════════════
// Helper: Check if alert already exists for this order + type
// ═══════════════════════════════════════════════════════════════
function alertExists(PDO $db, int $orderId, string $alertType): bool
{
    try {
        $stmt = $db->prepare("
            SELECT id FROM om_proactive_alerts
            WHERE order_id = ? AND alert_type = ?
            LIMIT 1
        ");
        $stmt->execute([$orderId, $alertType]);
        return (bool) $stmt->fetch();
    } catch (Exception $e) {
        error_log("[proactive-monitor] alertExists error: " . $e->getMessage());
        return false;
    }
}

// ═══════════════════════════════════════════════════════════════
// Helper: Create alert record
// ═══════════════════════════════════════════════════════════════
function createAlert(
    PDO $db,
    int $orderId,
    string $alertType,
    string $severity,
    string $autoAction,
    bool $customerNotified,
    ?string $compensationType = null,
    float $compensationValue = 0,
    ?string $notes = null
): ?int {
    try {
        $stmt = $db->prepare("
            INSERT INTO om_proactive_alerts
            (order_id, alert_type, severity, auto_action_taken, customer_notified,
             compensation_type, compensation_value, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            RETURNING id
        ");
        $stmt->execute([
            $orderId, $alertType, $severity, $autoAction,
            $customerNotified ? 1 : 0,
            $compensationType, $compensationValue, $notes
        ]);
        $row = $stmt->fetch();
        return $row ? (int) $row['id'] : null;
    } catch (Exception $e) {
        error_log("[proactive-monitor] createAlert error: " . $e->getMessage());
        return null;
    }
}

// ═══════════════════════════════════════════════════════════════
// Helper: Send WhatsApp notification (safe wrapper)
// ═══════════════════════════════════════════════════════════════
function sendProactiveWhatsApp(string $phone, string $message): bool
{
    if (empty($phone) || strlen($phone) < 8) return false;

    try {
        if (function_exists('sendWhatsAppWithRetry')) {
            $result = sendWhatsAppWithRetry($phone, $message, 2);
            return !empty($result['success']);
        } elseif (function_exists('sendWhatsApp')) {
            $result = sendWhatsApp($phone, $message);
            return !empty($result['success']);
        }
    } catch (Exception $e) {
        error_log("[proactive-monitor] WhatsApp send error: " . $e->getMessage());
    }
    return false;
}

// ═══════════════════════════════════════════════════════════════
// Helper: Auto-compensate with cashback
// ═══════════════════════════════════════════════════════════════
function autoCompensate(PDO $db, int $customerId, int $orderId, float $amount, string $reason): bool
{
    if ($customerId <= 0 || $amount <= 0) return false;

    try {
        // Ensure wallet exists
        $db->prepare("
            INSERT INTO om_cashback_wallet (customer_id, balance, total_earned)
            VALUES (?, 0, 0)
            ON CONFLICT (customer_id) DO NOTHING
        ")->execute([$customerId]);

        // Insert compensation credit transaction
        $db->prepare("
            INSERT INTO om_cashback_transactions
            (customer_id, order_id, type, amount, balance_after, description, expires_at)
            VALUES (?, ?, 'credit', ?, 0, ?, NOW() + INTERVAL '90 days')
        ")->execute([$customerId, $orderId, $amount, $reason]);

        // Update wallet balance
        $db->prepare("
            UPDATE om_cashback_wallet
            SET balance = balance + ?, total_earned = total_earned + ?
            WHERE customer_id = ?
        ")->execute([$amount, $amount, $customerId]);

        // Update balance_after in the transaction
        $stmt = $db->prepare("SELECT balance FROM om_cashback_wallet WHERE customer_id = ?");
        $stmt->execute([$customerId]);
        $newBalance = (float) $stmt->fetchColumn();

        $db->prepare("
            UPDATE om_cashback_transactions
            SET balance_after = ?
            WHERE order_id = ? AND type = 'credit' AND description = ?
        ")->execute([$newBalance, $orderId, $reason]);

        return true;
    } catch (Exception $e) {
        error_log("[proactive-monitor] autoCompensate error for order #{$orderId}: " . $e->getMessage());
        return false;
    }
}

// ═══════════════════════════════════════════════════════════════
// Helper: Send push notification to customer (safe wrapper)
// ═══════════════════════════════════════════════════════════════
function sendProactivePush(PDO $db, int $customerId, string $title, string $body, array $extra = []): void
{
    if ($customerId <= 0) return;
    try {
        if (function_exists('notifyCustomer')) {
            notifyCustomer($db, $customerId, $title, $body, '/mercado/', $extra);
        }
    } catch (Exception $e) {
        error_log("[proactive-monitor] Push notification error: " . $e->getMessage());
    }
}

// ═══════════════════════════════════════════════════════════════
// 1. No Accept (>10 min after confirmado)
// ═══════════════════════════════════════════════════════════════
try {
    $stmt = $db->prepare("
        SELECT o.order_id, o.order_number, o.customer_id, o.customer_phone,
               o.partner_id, COALESCE(o.partner_name, p.trade_name, p.name) AS partner_name,
               o.date_added
        FROM om_market_orders o
        LEFT JOIN om_market_partners p ON p.partner_id = o.partner_id
        WHERE o.status = 'confirmado'
          AND o.date_added < NOW() - INTERVAL '10 minutes'
        LIMIT 100
    ");
    $stmt->execute();
    $noAcceptOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $totalChecked += count($noAcceptOrders);

    foreach ($noAcceptOrders as $order) {
        try {
            $orderId = (int) $order['order_id'];

            // Skip if alert already exists
            if (alertExists($db, $orderId, 'no_accept')) continue;

            $orderNum = $order['order_number'] ?? $orderId;
            $partnerName = $order['partner_name'] ?? 'o restaurante';
            $phone = $order['customer_phone'] ?? '';
            $customerId = (int) ($order['customer_id'] ?? 0);

            // Send WhatsApp to customer
            $message = "Oi! Seu pedido #{$orderNum} da {$partnerName} esta sendo processado. Ja notificamos o restaurante!";
            $whatsappSent = sendProactiveWhatsApp($phone, $message);

            // Create alert
            createAlert($db, $orderId, 'no_accept', 'warning',
                'notified_customer', $whatsappSent,
                'none', 0,
                "Order not accepted after 10 min. Customer notified via WhatsApp."
            );

            // Push notification to customer
            sendProactivePush($db, $customerId,
                'Pedido em processamento',
                "Seu pedido #{$orderNum} esta sendo processado. Ja notificamos {$partnerName}!",
                ['type' => 'proactive_alert', 'order_id' => $orderId, 'alert_type' => 'no_accept']
            );

            // Notify partner
            try {
                if (function_exists('notifyPartner')) {
                    notifyPartner($db, (int) $order['partner_id'],
                        'Pedido aguardando aceite!',
                        "Pedido #{$orderNum} esta esperando ha mais de 10 minutos. Por favor aceite ou recuse.",
                        '/painel/mercado/pedidos.php',
                        ['type' => 'proactive_alert', 'order_id' => $orderId]
                    );
                }
            } catch (Exception $e) { /* non-critical */ }

            $totalAlerts++;
            if ($whatsappSent) $totalNotifications++;

            $log("NO_ACCEPT: Order #{$orderNum} (ID:{$orderId}) — customer notified: " . ($whatsappSent ? 'yes' : 'no'));
        } catch (Exception $e) {
            $log("ERROR no_accept order #{$order['order_id']}: " . $e->getMessage());
        }
    }
} catch (Exception $e) {
    $log("ERROR no_accept query: " . $e->getMessage());
}

// ═══════════════════════════════════════════════════════════════
// 2. No Preparation (>25 min after aceito)
// ═══════════════════════════════════════════════════════════════
try {
    $stmt = $db->prepare("
        SELECT o.order_id, o.order_number, o.customer_id, o.customer_phone,
               o.partner_id, COALESCE(o.partner_name, p.trade_name, p.name) AS partner_name,
               o.date_modified, o.accepted_at, o.distancia_km
        FROM om_market_orders o
        LEFT JOIN om_market_partners p ON p.partner_id = o.partner_id
        WHERE o.status = 'aceito'
          AND COALESCE(o.accepted_at, o.date_modified) < NOW() - INTERVAL '25 minutes'
        LIMIT 100
    ");
    $stmt->execute();
    $noPrepOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $totalChecked += count($noPrepOrders);

    foreach ($noPrepOrders as $order) {
        try {
            $orderId = (int) $order['order_id'];

            if (alertExists($db, $orderId, 'no_preparation')) continue;

            $orderNum = $order['order_number'] ?? $orderId;
            $partnerName = $order['partner_name'] ?? 'o restaurante';
            $phone = $order['customer_phone'] ?? '';
            $customerId = (int) ($order['customer_id'] ?? 0);
            $partnerId = (int) ($order['partner_id'] ?? 0);
            $distKm = (float) ($order['distancia_km'] ?? 3.0);

            // Calculate ETA
            $etaMinutes = 30; // fallback
            try {
                if (function_exists('calculateSmartETA') && $partnerId > 0) {
                    $etaMinutes = calculateSmartETA($db, $partnerId, $distKm, 'aceito');
                }
            } catch (Exception $e) { /* use fallback */ }

            // Send WhatsApp to customer
            $message = "Seu pedido #{$orderNum} ja foi aceito e esta sendo preparado! Previsao: {$etaMinutes} minutos.";
            $whatsappSent = sendProactiveWhatsApp($phone, $message);

            createAlert($db, $orderId, 'no_preparation', 'warning',
                'notified_customer', $whatsappSent,
                'none', 0,
                "Order accepted but not preparing after 25 min. ETA={$etaMinutes}min."
            );

            sendProactivePush($db, $customerId,
                'Pedido em preparacao',
                "Seu pedido #{$orderNum} esta sendo preparado! Previsao: ~{$etaMinutes} min.",
                ['type' => 'proactive_alert', 'order_id' => $orderId, 'alert_type' => 'no_preparation']
            );

            $totalAlerts++;
            if ($whatsappSent) $totalNotifications++;

            $log("NO_PREP: Order #{$orderNum} (ID:{$orderId}) — ETA={$etaMinutes}min, notified: " . ($whatsappSent ? 'yes' : 'no'));
        } catch (Exception $e) {
            $log("ERROR no_preparation order #{$order['order_id']}: " . $e->getMessage());
        }
    }
} catch (Exception $e) {
    $log("ERROR no_preparation query: " . $e->getMessage());
}

// ═══════════════════════════════════════════════════════════════
// 3. Delivery Stuck (>40 min after pronto)
// ═══════════════════════════════════════════════════════════════
try {
    $stmt = $db->prepare("
        SELECT o.order_id, o.order_number, o.customer_id, o.customer_phone,
               o.partner_id, COALESCE(o.partner_name, p.trade_name, p.name) AS partner_name,
               o.date_modified
        FROM om_market_orders o
        LEFT JOIN om_market_partners p ON p.partner_id = o.partner_id
        WHERE o.status = 'pronto'
          AND o.date_modified < NOW() - INTERVAL '40 minutes'
        LIMIT 100
    ");
    $stmt->execute();
    $stuckOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $totalChecked += count($stuckOrders);

    foreach ($stuckOrders as $order) {
        try {
            $orderId = (int) $order['order_id'];

            if (alertExists($db, $orderId, 'delivery_stuck')) continue;

            $orderNum = $order['order_number'] ?? $orderId;
            $partnerName = $order['partner_name'] ?? 'o restaurante';
            $phone = $order['customer_phone'] ?? '';
            $customerId = (int) ($order['customer_id'] ?? 0);
            $compensationAmount = 5.00;

            // Auto-compensate with R$5 cashback
            $compensated = false;
            if ($customerId > 0) {
                try {
                    $compensated = autoCompensate($db, $customerId, $orderId, $compensationAmount,
                        "Compensacao automatica - atraso na entrega do pedido #{$orderNum}"
                    );
                } catch (Exception $e) {
                    $log("ERROR compensate delivery_stuck #{$orderId}: " . $e->getMessage());
                }
            }

            $cashbackText = $compensated
                ? " Como compensacao, voce ganhou R\$" . number_format($compensationAmount, 2, ',', '.') . " de cashback!"
                : "";

            // Send WhatsApp to customer
            $message = "Desculpe pela demora! Seu pedido #{$orderNum} ja esta pronto e estamos agilizando a entrega.{$cashbackText}";
            $whatsappSent = sendProactiveWhatsApp($phone, $message);

            createAlert($db, $orderId, 'delivery_stuck', 'critical',
                'offered_compensation', $whatsappSent,
                $compensated ? 'cashback' : 'none',
                $compensated ? $compensationAmount : 0,
                "Delivery stuck >40 min after ready. Compensated: " . ($compensated ? "R\${$compensationAmount}" : "no")
            );

            sendProactivePush($db, $customerId,
                'Atraso na entrega',
                "Seu pedido #{$orderNum} esta pronto e a entrega esta sendo agilizada." . ($compensated ? " Voce ganhou cashback!" : ""),
                ['type' => 'proactive_alert', 'order_id' => $orderId, 'alert_type' => 'delivery_stuck']
            );

            $totalAlerts++;
            if ($whatsappSent) $totalNotifications++;

            $log("DELIVERY_STUCK: Order #{$orderNum} (ID:{$orderId}) — compensated: " . ($compensated ? "R\${$compensationAmount}" : "no"));
        } catch (Exception $e) {
            $log("ERROR delivery_stuck order #{$order['order_id']}: " . $e->getMessage());
        }
    }
} catch (Exception $e) {
    $log("ERROR delivery_stuck query: " . $e->getMessage());
}

// ═══════════════════════════════════════════════════════════════
// 4. Very Late (>60 min total since order creation)
// ═══════════════════════════════════════════════════════════════
try {
    $stmt = $db->prepare("
        SELECT o.order_id, o.order_number, o.customer_id, o.customer_phone,
               o.partner_id, COALESCE(o.partner_name, p.trade_name, p.name) AS partner_name,
               o.status, o.date_added
        FROM om_market_orders o
        LEFT JOIN om_market_partners p ON p.partner_id = o.partner_id
        WHERE o.status NOT IN ('entregue', 'retirado', 'finalizado', 'cancelado', 'cancelled', 'recusado')
          AND o.date_added < NOW() - INTERVAL '60 minutes'
        LIMIT 100
    ");
    $stmt->execute();
    $lateOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $totalChecked += count($lateOrders);

    foreach ($lateOrders as $order) {
        try {
            $orderId = (int) $order['order_id'];

            if (alertExists($db, $orderId, 'late_order')) continue;

            $orderNum = $order['order_number'] ?? $orderId;
            $partnerName = $order['partner_name'] ?? 'o restaurante';
            $phone = $order['customer_phone'] ?? '';
            $customerId = (int) ($order['customer_id'] ?? 0);
            $compensationAmount = 10.00;

            // Higher compensation: R$10 cashback
            $compensated = false;
            if ($customerId > 0) {
                try {
                    $compensated = autoCompensate($db, $customerId, $orderId, $compensationAmount,
                        "Compensacao automatica - atraso significativo no pedido #{$orderNum}"
                    );
                } catch (Exception $e) {
                    $log("ERROR compensate late_order #{$orderId}: " . $e->getMessage());
                }
            }

            $cashbackText = $compensated
                ? " Creditamos R\$" . number_format($compensationAmount, 2, ',', '.') . " de cashback na sua conta como compensacao."
                : "";

            // Send WhatsApp to customer
            $message = "Pedimos mil desculpas pelo atraso no pedido #{$orderNum}!{$cashbackText}";
            $whatsappSent = sendProactiveWhatsApp($phone, $message);

            createAlert($db, $orderId, 'late_order', 'critical',
                'offered_compensation', $whatsappSent,
                $compensated ? 'cashback' : 'none',
                $compensated ? $compensationAmount : 0,
                "Order >60 min total. Status={$order['status']}. Compensated: " . ($compensated ? "R\${$compensationAmount}" : "no")
            );

            sendProactivePush($db, $customerId,
                'Pedimos desculpas pelo atraso',
                "Seu pedido #{$orderNum} esta atrasado. " . ($compensated ? "Creditamos R\$10 de cashback!" : "Estamos trabalhando para resolver."),
                ['type' => 'proactive_alert', 'order_id' => $orderId, 'alert_type' => 'late_order']
            );

            $totalAlerts++;
            if ($whatsappSent) $totalNotifications++;

            $log("LATE_ORDER: Order #{$orderNum} (ID:{$orderId}) status={$order['status']} — compensated: " . ($compensated ? "R\${$compensationAmount}" : "no"));
        } catch (Exception $e) {
            $log("ERROR late_order #{$order['order_id']}: " . $e->getMessage());
        }
    }
} catch (Exception $e) {
    $log("ERROR late_order query: " . $e->getMessage());
}

// ═══════════════════════════════════════════════════════════════
// Summary
// ═══════════════════════════════════════════════════════════════
$log("=== Proactive monitor finished: checked {$totalChecked} orders, {$totalAlerts} alerts created, {$totalNotifications} notifications sent ===");

// Release file lock
flock($lockFp, LOCK_UN);
fclose($lockFp);
