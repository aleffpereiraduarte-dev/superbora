<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * PROACTIVE ORDER RESOLUTION
 * Detects late orders, sends customer/partner notifications, auto-compensates.
 * Designed to run from cron every 2 minutes.
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Usage:
 *   require_once __DIR__ . '/../helpers/proactive-resolution.php';
 *   $results = checkLateOrders($db);    // cron: every 2 minutes
 *   $dashboard = getProactiveAlertsDashboard($db, '7d');
 *
 * Dependencies:
 *   - zapi-whatsapp.php  (sendWhatsAppWithRetry)
 *   - cashback.php       (getCashbackBalance — only for reference; we credit directly)
 */

// Threshold constants (minutes)
define('THRESHOLD_NO_ACCEPT', 10);        // confirmado -> not aceito within 10 min
define('THRESHOLD_NO_PREPARATION', 20);   // aceito -> not preparando within 20 min
define('THRESHOLD_NO_DISPATCH', 15);      // pronto -> not em_entrega within 15 min
define('THRESHOLD_DELIVERY_STUCK', 45);   // em_entrega for over 45 min

// Compensation thresholds (minutes late)
define('COMPENSATION_WARNING_MIN', 10);
define('COMPENSATION_CRITICAL_MIN', 30);
define('COMPENSATION_SEVERE_MIN', 45);
define('COMPENSATION_AMOUNT_CRITICAL', 5.00);  // R$5 for >30min
define('COMPENSATION_AMOUNT_SEVERE', 10.00);   // R$10 for >45min

/**
 * Check for late orders across all active statuses and create alerts.
 * Intended to be called by cron every 2 minutes.
 *
 * @param PDO $db Database connection
 * @return array  Summary: alerts_created, notifications_sent, compensations_given
 */
function checkLateOrders(PDO $db): array {
    $alertsCreated      = 0;
    $notificationsSent  = 0;
    $compensationsGiven = 0;
    $errors             = 0;

    try {
        // ── 1. Orders not accepted within threshold ──
        $stmtNoAccept = $db->prepare("
            SELECT o.order_id, o.order_number, o.customer_id, o.customer_phone,
                   o.partner_id, o.partner_name, o.total, o.status, o.date_added,
                   EXTRACT(EPOCH FROM (NOW() - o.date_added)) / 60 as minutes_waiting,
                   p.trade_name, p.phone as partner_phone
            FROM om_market_orders o
            LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
            WHERE o.status IN ('pendente', 'confirmado')
              AND o.date_added < NOW() - INTERVAL '" . THRESHOLD_NO_ACCEPT . " minutes'
              AND o.date_added > NOW() - INTERVAL '3 hours'
              AND NOT EXISTS (
                  SELECT 1 FROM om_proactive_alerts a
                  WHERE a.order_id = o.order_id
                    AND a.alert_type = 'no_accept'
                    AND a.created_at > NOW() - INTERVAL '30 minutes'
              )
            ORDER BY o.date_added ASC
            LIMIT 50
        ");
        $stmtNoAccept->execute();
        $lateAccept = $stmtNoAccept->fetchAll(PDO::FETCH_ASSOC);

        foreach ($lateAccept as $order) {
            try {
                $result = processLateOrder($db, $order, 'no_accept', (float)$order['minutes_waiting']);
                $alertsCreated += $result['alert_created'] ? 1 : 0;
                $notificationsSent += $result['notifications_sent'];
                $compensationsGiven += $result['compensation_given'] ? 1 : 0;
            } catch (Exception $e) {
                error_log("[proactive] Error processing no_accept order #{$order['order_id']}: " . $e->getMessage());
                $errors++;
            }
        }

        // ── 2. Orders accepted but not being prepared ──
        $stmtNoPrepare = $db->prepare("
            SELECT o.order_id, o.order_number, o.customer_id, o.customer_phone,
                   o.partner_id, o.partner_name, o.total, o.status, o.date_added,
                   o.date_modified,
                   EXTRACT(EPOCH FROM (NOW() - o.date_modified)) / 60 as minutes_waiting,
                   p.trade_name, p.phone as partner_phone
            FROM om_market_orders o
            LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
            WHERE o.status = 'aceito'
              AND o.date_modified < NOW() - INTERVAL '" . THRESHOLD_NO_PREPARATION . " minutes'
              AND o.date_added > NOW() - INTERVAL '3 hours'
              AND NOT EXISTS (
                  SELECT 1 FROM om_proactive_alerts a
                  WHERE a.order_id = o.order_id
                    AND a.alert_type = 'no_preparation'
                    AND a.created_at > NOW() - INTERVAL '30 minutes'
              )
            ORDER BY o.date_modified ASC
            LIMIT 50
        ");
        $stmtNoPrepare->execute();
        $latePrepare = $stmtNoPrepare->fetchAll(PDO::FETCH_ASSOC);

        foreach ($latePrepare as $order) {
            try {
                $result = processLateOrder($db, $order, 'no_preparation', (float)$order['minutes_waiting']);
                $alertsCreated += $result['alert_created'] ? 1 : 0;
                $notificationsSent += $result['notifications_sent'];
                $compensationsGiven += $result['compensation_given'] ? 1 : 0;
            } catch (Exception $e) {
                error_log("[proactive] Error processing no_preparation order #{$order['order_id']}: " . $e->getMessage());
                $errors++;
            }
        }

        // ── 3. Orders ready but not dispatched ──
        $stmtNoDispatch = $db->prepare("
            SELECT o.order_id, o.order_number, o.customer_id, o.customer_phone,
                   o.partner_id, o.partner_name, o.total, o.status, o.date_added,
                   o.date_modified,
                   EXTRACT(EPOCH FROM (NOW() - o.date_modified)) / 60 as minutes_waiting,
                   p.trade_name, p.phone as partner_phone
            FROM om_market_orders o
            LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
            WHERE o.status = 'pronto'
              AND o.date_modified < NOW() - INTERVAL '" . THRESHOLD_NO_DISPATCH . " minutes'
              AND o.date_added > NOW() - INTERVAL '3 hours'
              AND NOT EXISTS (
                  SELECT 1 FROM om_proactive_alerts a
                  WHERE a.order_id = o.order_id
                    AND a.alert_type = 'delivery_stuck'
                    AND a.created_at > NOW() - INTERVAL '30 minutes'
              )
            ORDER BY o.date_modified ASC
            LIMIT 50
        ");
        $stmtNoDispatch->execute();
        $lateDispatch = $stmtNoDispatch->fetchAll(PDO::FETCH_ASSOC);

        foreach ($lateDispatch as $order) {
            try {
                $result = processLateOrder($db, $order, 'delivery_stuck', (float)$order['minutes_waiting']);
                $alertsCreated += $result['alert_created'] ? 1 : 0;
                $notificationsSent += $result['notifications_sent'];
                $compensationsGiven += $result['compensation_given'] ? 1 : 0;
            } catch (Exception $e) {
                error_log("[proactive] Error processing delivery_stuck order #{$order['order_id']}: " . $e->getMessage());
                $errors++;
            }
        }

        // ── 4. Deliveries taking too long ──
        $stmtLongDelivery = $db->prepare("
            SELECT o.order_id, o.order_number, o.customer_id, o.customer_phone,
                   o.partner_id, o.partner_name, o.total, o.status, o.date_added,
                   o.date_modified,
                   EXTRACT(EPOCH FROM (NOW() - o.date_modified)) / 60 as minutes_waiting,
                   p.trade_name, p.phone as partner_phone
            FROM om_market_orders o
            LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
            WHERE o.status IN ('em_entrega', 'saiu_entrega')
              AND o.date_modified < NOW() - INTERVAL '" . THRESHOLD_DELIVERY_STUCK . " minutes'
              AND o.date_added > NOW() - INTERVAL '4 hours'
              AND NOT EXISTS (
                  SELECT 1 FROM om_proactive_alerts a
                  WHERE a.order_id = o.order_id
                    AND a.alert_type = 'late_order'
                    AND a.created_at > NOW() - INTERVAL '30 minutes'
              )
            ORDER BY o.date_modified ASC
            LIMIT 50
        ");
        $stmtLongDelivery->execute();
        $lateDelivery = $stmtLongDelivery->fetchAll(PDO::FETCH_ASSOC);

        foreach ($lateDelivery as $order) {
            try {
                $result = processLateOrder($db, $order, 'late_order', (float)$order['minutes_waiting']);
                $alertsCreated += $result['alert_created'] ? 1 : 0;
                $notificationsSent += $result['notifications_sent'];
                $compensationsGiven += $result['compensation_given'] ? 1 : 0;
            } catch (Exception $e) {
                error_log("[proactive] Error processing late_order #{$order['order_id']}: " . $e->getMessage());
                $errors++;
            }
        }

    } catch (Exception $e) {
        error_log("[proactive] checkLateOrders critical error: " . $e->getMessage());
        $errors++;
    }

    return [
        'alerts_created' => $alertsCreated,
        'notifications_sent' => $notificationsSent,
        'compensations_given' => $compensationsGiven,
        'errors' => $errors,
        'checked_at' => date('Y-m-d H:i:s'),
    ];
}

/**
 * Process a single late order: create alert, notify, compensate.
 *
 * @param PDO   $db           Database connection
 * @param array $order        Order row with join data
 * @param string $alertType   Alert type (no_accept, no_preparation, delivery_stuck, late_order)
 * @param float $minutesLate  How many minutes late
 * @return array              Result summary
 */
function processLateOrder(PDO $db, array $order, string $alertType, float $minutesLate): array {
    $result = [
        'alert_created' => false,
        'notifications_sent' => 0,
        'compensation_given' => false,
    ];

    $orderId    = (int)$order['order_id'];
    $customerId = (int)($order['customer_id'] ?? 0);
    $minutesLate = (int)round($minutesLate);

    // Determine severity
    $severity = 'info';
    if ($minutesLate >= COMPENSATION_CRITICAL_MIN) {
        $severity = 'critical';
    } elseif ($minutesLate >= COMPENSATION_WARNING_MIN) {
        $severity = 'warning';
    }

    // ── Create alert ──
    $autoAction = null;
    $compensationType = 'none';
    $compensationValue = 0;

    try {
        // Determine auto-action based on severity
        if ($severity === 'critical') {
            $autoAction = 'offered_compensation';
            $compensationType = 'cashback';
            $compensationValue = $minutesLate >= COMPENSATION_SEVERE_MIN
                ? COMPENSATION_AMOUNT_SEVERE
                : COMPENSATION_AMOUNT_CRITICAL;
        } elseif ($severity === 'warning') {
            $autoAction = 'notified_customer';
        } else {
            $autoAction = 'logged';
        }

        $stmt = $db->prepare("
            INSERT INTO om_proactive_alerts (
                order_id, alert_type, severity, actual_delay_minutes,
                auto_action_taken, compensation_type, compensation_value
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
            RETURNING id
        ");
        $stmt->execute([
            $orderId,
            $alertType,
            $severity,
            $minutesLate,
            $autoAction,
            $compensationType,
            $compensationValue,
        ]);
        $alertId = $stmt->fetchColumn();
        $result['alert_created'] = true;

    } catch (Exception $e) {
        error_log("[proactive] Error creating alert for order #{$orderId}: " . $e->getMessage());
        return $result;
    }

    // ── Send customer notification (warning and critical) ──
    if ($severity !== 'info') {
        $customerNotified = false;
        try {
            $customerNotified = sendDelayNotification($db, $orderId, [
                'alert_type' => $alertType,
                'severity' => $severity,
                'minutes_late' => $minutesLate,
                'customer_phone' => $order['customer_phone'] ?? '',
                'order_number' => $order['order_number'] ?? $orderId,
                'partner_name' => $order['trade_name'] ?? $order['partner_name'] ?? 'a loja',
                'compensation_value' => $compensationValue,
            ]);
            if ($customerNotified) {
                $result['notifications_sent']++;
            }
        } catch (Exception $e) {
            error_log("[proactive] Error notifying customer for order #{$orderId}: " . $e->getMessage());
        }

        // Update alert with notification status
        try {
            $db->prepare("UPDATE om_proactive_alerts SET customer_notified = ? WHERE id = ?")
               ->execute([$customerNotified, $alertId]);
        } catch (Exception $e) {
            // non-critical
        }
    }

    // ── Notify partner if they haven't acted ──
    if (in_array($alertType, ['no_accept', 'no_preparation']) && !empty($order['partner_phone'])) {
        try {
            $partnerPhone = preg_replace('/[^0-9]/', '', $order['partner_phone']);
            if (strlen($partnerPhone) >= 10) {
                $partnerName = $order['trade_name'] ?? $order['partner_name'] ?? 'Parceiro';
                $orderNum = $order['order_number'] ?? $orderId;

                if ($alertType === 'no_accept') {
                    $partnerMsg = "Ola {$partnerName}! O pedido #{$orderNum} esta aguardando aceite ha {$minutesLate} minutos. "
                                . "Por favor, aceite ou recuse o pedido para nao deixar o cliente esperando.";
                } else {
                    $partnerMsg = "Ola {$partnerName}! O pedido #{$orderNum} foi aceito ha {$minutesLate} minutos mas ainda nao esta sendo preparado. "
                                . "Atualize o status quando iniciar o preparo.";
                }

                if (function_exists('sendWhatsAppWithRetry')) {
                    $waResult = sendWhatsAppWithRetry($partnerPhone, $partnerMsg);
                    if ($waResult['success'] ?? false) {
                        $result['notifications_sent']++;
                        try {
                            $db->prepare("UPDATE om_proactive_alerts SET partner_notified = TRUE WHERE id = ?")
                               ->execute([$alertId]);
                        } catch (Exception $e) {
                            // non-critical
                        }
                    }
                }
            }
        } catch (Exception $e) {
            error_log("[proactive] Error notifying partner for order #{$orderId}: " . $e->getMessage());
        }
    }

    // ── Auto-compensate (critical only) ──
    if ($severity === 'critical' && $customerId > 0 && $compensationValue > 0) {
        try {
            $compensated = autoCompensate($db, $orderId, $customerId, $compensationValue, 'cashback');
            $result['compensation_given'] = $compensated;
        } catch (Exception $e) {
            error_log("[proactive] Error compensating for order #{$orderId}: " . $e->getMessage());
        }
    }

    return $result;
}

/**
 * Send delay notification to customer via WhatsApp.
 *
 * @param PDO   $db      Database connection
 * @param int   $orderId Order ID
 * @param array $alert   Alert data with: customer_phone, order_number, partner_name, severity, minutes_late, compensation_value
 * @return bool           Whether notification was sent successfully
 */
function sendDelayNotification(PDO $db, int $orderId, array $alert): bool {
    $phone = preg_replace('/[^0-9]/', '', $alert['customer_phone'] ?? '');
    if (strlen($phone) < 10) {
        return false;
    }

    $orderNum    = $alert['order_number'] ?? $orderId;
    $partnerName = $alert['partner_name'] ?? 'a loja';
    $severity    = $alert['severity'] ?? 'warning';
    $minutesLate = (int)($alert['minutes_late'] ?? 0);
    $compensation = (float)($alert['compensation_value'] ?? 0);

    // Build message based on severity
    if ($severity === 'critical' && $compensation > 0) {
        $compFormatted = 'R$ ' . number_format($compensation, 2, ',', '.');
        $message = "Oi! Sabemos que seu pedido #{$orderNum} de {$partnerName} esta demorando mais que o esperado. "
                 . "Pedimos desculpas pelo atraso de {$minutesLate} minutos. "
                 . "Como compensacao, creditamos {$compFormatted} de cashback na sua conta. "
                 . "Estamos acompanhando de perto para garantir que chegue o mais rapido possivel!";
    } elseif ($severity === 'critical') {
        $message = "Oi! Seu pedido #{$orderNum} de {$partnerName} esta com um atraso de {$minutesLate} minutos. "
                 . "Pedimos desculpas! Ja estamos acompanhando e tomando providencias para resolver o mais rapido possivel.";
    } else {
        $message = "Oi! Seu pedido #{$orderNum} de {$partnerName} esta demorando um pouquinho, ja estamos resolvendo! "
                 . "Obrigado pela paciencia.";
    }

    try {
        if (function_exists('sendWhatsAppWithRetry')) {
            $waResult = sendWhatsAppWithRetry($phone, $message);
            return $waResult['success'] ?? false;
        }
        return false;
    } catch (Exception $e) {
        error_log("[proactive] sendDelayNotification error for order #{$orderId}: " . $e->getMessage());
        return false;
    }
}

/**
 * Auto-compensate a customer for order delays.
 * Creates cashback credit and logs to audit.
 *
 * @param PDO    $db         Database connection
 * @param int    $orderId    Order ID
 * @param int    $customerId Customer ID
 * @param float  $amount     Compensation amount in BRL
 * @param string $type       Compensation type: 'cashback' (default)
 * @return bool              Whether compensation was successfully applied
 */
function autoCompensate(PDO $db, int $orderId, int $customerId, float $amount, string $type = 'cashback'): bool {
    if ($amount <= 0 || $customerId <= 0) {
        return false;
    }

    try {
        // Idempotency: check if we already compensated this order
        $stmtCheck = $db->prepare("
            SELECT id FROM om_proactive_alerts
            WHERE order_id = ?
              AND compensation_type = 'cashback'
              AND compensation_value > 0
              AND resolved = TRUE
            LIMIT 1
        ");
        $stmtCheck->execute([$orderId]);
        if ($stmtCheck->fetch()) {
            error_log("[proactive] Compensation already given for order #{$orderId}, skipping");
            return false;
        }

        $db->beginTransaction();

        if ($type === 'cashback') {
            // Credit to cashback wallet (same pattern as cashback.php)
            $description = "Compensacao automatica - atraso pedido #{$orderId}";

            $stmt = $db->prepare("
                INSERT INTO om_cashback_wallet (customer_id, balance, total_earned)
                VALUES (?, ?, ?)
                ON CONFLICT (customer_id) DO UPDATE SET
                    balance = om_cashback_wallet.balance + EXCLUDED.balance,
                    total_earned = om_cashback_wallet.total_earned + EXCLUDED.total_earned
            ");
            $stmt->execute([$customerId, $amount, $amount]);

            // Record the transaction
            $stmt = $db->prepare("
                INSERT INTO om_cashback_transactions
                (customer_id, order_id, type, amount, balance_after, description, expires_at)
                VALUES (?, ?, 'credit', ?, 0, ?, NOW() + INTERVAL '90 days')
            ");
            $stmt->execute([$customerId, $orderId, $amount, $description]);

            // Update balance_after
            $stmtBal = $db->prepare("SELECT balance FROM om_cashback_wallet WHERE customer_id = ?");
            $stmtBal->execute([$customerId]);
            $newBalance = (float)$stmtBal->fetchColumn();

            $db->prepare("
                UPDATE om_cashback_transactions
                SET balance_after = ?
                WHERE customer_id = ? AND order_id = ? AND type = 'credit'
                  AND description LIKE 'Compensacao automatica%'
            ")->execute([$newBalance, $customerId, $orderId]);
        }

        // Mark alert as resolved with compensation
        $db->prepare("
            UPDATE om_proactive_alerts
            SET resolved = TRUE, resolved_at = NOW(),
                notes = 'Auto-compensated R$ ' || ? || ' via ' || ?
            WHERE order_id = ?
              AND compensation_type = ?
              AND resolved = FALSE
        ")->execute([number_format($amount, 2), $type, $orderId, $type]);

        $db->commit();

        // Audit log (non-transactional, best-effort)
        try {
            $db->prepare("
                INSERT INTO om_audit_log (event_type, actor_type, actor_id, customer_id, resource_type, resource_id, action, details)
                VALUES ('compensation', 'system', 'proactive-resolution', ?, 'order', ?, 'create', ?)
            ")->execute([
                $customerId,
                (string)$orderId,
                json_encode([
                    'amount' => $amount,
                    'type' => $type,
                    'reason' => 'auto_delay_compensation',
                ], JSON_UNESCAPED_UNICODE),
            ]);
        } catch (Exception $e) {
            error_log("[proactive] Audit log failed for compensation on order #{$orderId}: " . $e->getMessage());
        }

        error_log("[proactive] Auto-compensated R$ " . number_format($amount, 2) . " ({$type}) for order #{$orderId}, customer #{$customerId}");
        return true;

    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("[proactive] autoCompensate error for order #{$orderId}: " . $e->getMessage());
        return false;
    }
}

/**
 * Get proactive alerts dashboard data.
 *
 * @param PDO    $db     Database connection
 * @param string $period Period: '24h', '7d', '30d'
 * @return array         Dashboard metrics
 */
function getProactiveAlertsDashboard(PDO $db, string $period = '7d'): array {
    try {
        // Parse period into PostgreSQL interval
        $intervalMap = [
            '24h' => '24 hours',
            '7d'  => '7 days',
            '30d' => '30 days',
            '90d' => '90 days',
        ];
        $interval = $intervalMap[$period] ?? '7 days';

        // ── Alerts by type ──
        $stmtByType = $db->prepare("
            SELECT alert_type, severity, COUNT(*) as count
            FROM om_proactive_alerts
            WHERE created_at > NOW() - INTERVAL '{$interval}'
            GROUP BY alert_type, severity
            ORDER BY count DESC
        ");
        $stmtByType->execute();
        $alertsByType = $stmtByType->fetchAll(PDO::FETCH_ASSOC);

        // ── Auto-resolved count ──
        $stmtResolved = $db->prepare("
            SELECT
                COUNT(*) FILTER (WHERE resolved = TRUE) as auto_resolved,
                COUNT(*) FILTER (WHERE resolved = FALSE) as unresolved,
                COUNT(*) as total
            FROM om_proactive_alerts
            WHERE created_at > NOW() - INTERVAL '{$interval}'
        ");
        $stmtResolved->execute();
        $resolvedStats = $stmtResolved->fetch(PDO::FETCH_ASSOC);

        // ── Compensation total ──
        $stmtComp = $db->prepare("
            SELECT
                COUNT(*) FILTER (WHERE compensation_value > 0) as compensations_given,
                COALESCE(SUM(compensation_value) FILTER (WHERE compensation_value > 0), 0) as total_compensation,
                COALESCE(AVG(compensation_value) FILTER (WHERE compensation_value > 0), 0) as avg_compensation
            FROM om_proactive_alerts
            WHERE created_at > NOW() - INTERVAL '{$interval}'
        ");
        $stmtComp->execute();
        $compStats = $stmtComp->fetch(PDO::FETCH_ASSOC);

        // ── Avg delay by partner (worst offenders) ──
        $stmtPartner = $db->prepare("
            SELECT
                o.partner_id,
                COALESCE(p.trade_name, p.name, 'Desconhecido') as partner_name,
                COUNT(a.id) as alert_count,
                AVG(a.actual_delay_minutes) as avg_delay_minutes,
                MAX(a.actual_delay_minutes) as max_delay_minutes,
                SUM(a.compensation_value) as total_compensation
            FROM om_proactive_alerts a
            JOIN om_market_orders o ON a.order_id = o.order_id
            LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
            WHERE a.created_at > NOW() - INTERVAL '{$interval}'
            GROUP BY o.partner_id, p.trade_name, p.name
            ORDER BY alert_count DESC
            LIMIT 20
        ");
        $stmtPartner->execute();
        $worstPartners = $stmtPartner->fetchAll(PDO::FETCH_ASSOC);

        foreach ($worstPartners as &$wp) {
            $wp['partner_id'] = (int)$wp['partner_id'];
            $wp['alert_count'] = (int)$wp['alert_count'];
            $wp['avg_delay_minutes'] = round((float)$wp['avg_delay_minutes'], 1);
            $wp['max_delay_minutes'] = (int)$wp['max_delay_minutes'];
            $wp['total_compensation'] = round((float)$wp['total_compensation'], 2);
        }
        unset($wp);

        // ── Customer notification success rate ──
        $stmtNotif = $db->prepare("
            SELECT
                COUNT(*) FILTER (WHERE customer_notified = TRUE) as customer_notified,
                COUNT(*) FILTER (WHERE partner_notified = TRUE) as partner_notified,
                COUNT(*) as total_alerts
            FROM om_proactive_alerts
            WHERE created_at > NOW() - INTERVAL '{$interval}'
              AND severity IN ('warning', 'critical')
        ");
        $stmtNotif->execute();
        $notifStats = $stmtNotif->fetch(PDO::FETCH_ASSOC);

        // ── Daily trend (last 7 days) ──
        $stmtTrend = $db->query("
            SELECT
                DATE(created_at) as day,
                COUNT(*) as alerts,
                COUNT(*) FILTER (WHERE severity = 'critical') as critical,
                COALESCE(SUM(compensation_value), 0) as compensation
            FROM om_proactive_alerts
            WHERE created_at > NOW() - INTERVAL '7 days'
            GROUP BY DATE(created_at)
            ORDER BY day DESC
        ");
        $dailyTrend = $stmtTrend->fetchAll(PDO::FETCH_ASSOC);

        return [
            'period' => $period,
            'alerts_by_type' => $alertsByType,
            'resolution' => [
                'total' => (int)($resolvedStats['total'] ?? 0),
                'auto_resolved' => (int)($resolvedStats['auto_resolved'] ?? 0),
                'unresolved' => (int)($resolvedStats['unresolved'] ?? 0),
                'resolution_rate' => ($resolvedStats['total'] ?? 0) > 0
                    ? round(((int)$resolvedStats['auto_resolved'] / (int)$resolvedStats['total']) * 100, 1)
                    : 0,
            ],
            'compensation' => [
                'count' => (int)($compStats['compensations_given'] ?? 0),
                'total' => round((float)($compStats['total_compensation'] ?? 0), 2),
                'average' => round((float)($compStats['avg_compensation'] ?? 0), 2),
            ],
            'worst_partners' => $worstPartners,
            'notifications' => [
                'customer_notified' => (int)($notifStats['customer_notified'] ?? 0),
                'partner_notified' => (int)($notifStats['partner_notified'] ?? 0),
                'total_actionable' => (int)($notifStats['total_alerts'] ?? 0),
            ],
            'daily_trend' => $dailyTrend,
        ];

    } catch (Exception $e) {
        error_log("[proactive] getProactiveAlertsDashboard error: " . $e->getMessage());
        return [
            'error' => 'dashboard_failed',
            'period' => $period,
            'alerts_by_type' => [],
            'resolution' => [],
            'compensation' => [],
            'worst_partners' => [],
        ];
    }
}
