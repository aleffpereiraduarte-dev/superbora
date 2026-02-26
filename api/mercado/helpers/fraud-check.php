<?php
/**
 * Fraud Detection for Checkout
 *
 * Analyzes order signals and returns a risk score with recommended action.
 *
 * Usage:
 *   require_once __DIR__ . '/../helpers/fraud-check.php';
 *   $result = checkFraudSignals($db, $customerId, $ip, $orderTotal);
 *   // $result = {score: 0-100, signals: [], action: 'allow'|'review'|'block'}
 */

/**
 * Check fraud signals for a customer order.
 *
 * @param PDO    $db          Database connection
 * @param int    $customerId  Customer ID
 * @param string $ip          Client IP address
 * @param float  $orderTotal  Order total in BRL
 * @return array {score: int, signals: array, action: string}
 */
function checkFraudSignals(PDO $db, int $customerId, string $ip, float $orderTotal): array {
    $score = 0;
    $signals = [];

    try {
        // ── Signal 1: Multiple orders in 1 hour ──
        $orderVelocity = checkOrderVelocity($db, $customerId);
        if ($orderVelocity['count'] >= 5) {
            $score += 40;
            $signals[] = [
                'type' => 'high_order_velocity',
                'severity' => 'high',
                'detail' => "{$orderVelocity['count']} pedidos na ultima hora",
                'points' => 40,
            ];
        } elseif ($orderVelocity['count'] >= 3) {
            $score += 20;
            $signals[] = [
                'type' => 'moderate_order_velocity',
                'severity' => 'medium',
                'detail' => "{$orderVelocity['count']} pedidos na ultima hora",
                'points' => 20,
            ];
        }

        // ── Signal 2: Failed payments in 24h ──
        $failedPayments = checkFailedPayments($db, $customerId);
        if ($failedPayments >= 3) {
            $score += 30;
            $signals[] = [
                'type' => 'failed_payments',
                'severity' => 'high',
                'detail' => "{$failedPayments} pagamentos falhados em 24h",
                'points' => 30,
            ];
        }

        // ── Signal 3: Unusual order value (>5x average) ──
        $valueCheck = checkUnusualValue($db, $customerId, $orderTotal);
        if ($valueCheck['unusual']) {
            $score += 25;
            $signals[] = [
                'type' => 'unusual_order_value',
                'severity' => 'medium',
                'detail' => "Pedido R$ " . number_format($orderTotal, 2, ',', '.') .
                           " vs media R$ " . number_format($valueCheck['avg'], 2, ',', '.'),
                'points' => 25,
            ];
        }

        // ── Signal 4: New account + high value ──
        $newAccountCheck = checkNewAccountHighValue($db, $customerId, $orderTotal);
        if ($newAccountCheck) {
            $score += 20;
            $signals[] = [
                'type' => 'new_account_high_value',
                'severity' => 'medium',
                'detail' => "Conta criada ha menos de 24h com pedido > R$ 200",
                'points' => 20,
            ];
        }

        // ── Signal 5: Frequent cancellations ──
        $cancellations = checkFrequentCancellations($db, $customerId);
        if ($cancellations >= 5) {
            $score += 25;
            $signals[] = [
                'type' => 'frequent_cancellations',
                'severity' => 'medium',
                'detail' => "{$cancellations} cancelamentos nos ultimos 7 dias",
                'points' => 25,
            ];
        }

        // ── Signal 6: Multiple IPs for same customer in short time ──
        $ipCheck = checkMultipleIPs($db, $customerId, $ip);
        if ($ipCheck >= 3) {
            $score += 15;
            $signals[] = [
                'type' => 'multiple_ips',
                'severity' => 'low',
                'detail' => "{$ipCheck} IPs diferentes em 24h",
                'points' => 15,
            ];
        }

    } catch (Exception $e) {
        error_log("[fraud-check] Error checking signals for customer {$customerId}: " . $e->getMessage());
        // SECURITY: Fail-closed — on error, flag for review instead of allowing through
        return [
            'score' => 50,
            'signals' => [['type' => 'check_error', 'severity' => 'medium', 'detail' => 'Fraud check failed — flagged for review', 'points' => 50]],
            'action' => 'review',
            'error' => 'check_failed',
        ];
    }

    // Cap score at 100
    $score = min(100, $score);

    // Determine action
    $action = 'allow';
    if ($score >= 70) {
        $action = 'block';
    } elseif ($score >= 40) {
        $action = 'review';
    }

    // Log the fraud check result
    try {
        logFraudCheck($db, $customerId, $ip, $orderTotal, $score, $signals, $action);
    } catch (Exception $e) {
        error_log("[fraud-check] Error logging result: " . $e->getMessage());
    }

    return [
        'score' => $score,
        'signals' => $signals,
        'action' => $action,
    ];
}

/**
 * Check how many orders this customer placed in the last hour.
 */
function checkOrderVelocity(PDO $db, int $customerId): array {
    $stmt = $db->prepare("
        SELECT COUNT(*) as cnt
        FROM om_market_orders
        WHERE customer_id = ?
          AND date_added > NOW() - INTERVAL '1 hour'
    ");
    $stmt->execute([$customerId]);
    $result = $stmt->fetch();

    return ['count' => (int)($result['cnt'] ?? 0)];
}

/**
 * Check failed payment attempts in last 24h.
 */
function checkFailedPayments(PDO $db, int $customerId): int {
    try {
        // Use only status column (always exists) to avoid missing column errors
        // that would silently disable this fraud signal
        $stmt = $db->prepare("
            SELECT COUNT(*) as cnt
            FROM om_market_orders
            WHERE customer_id = ?
              AND date_added > NOW() - INTERVAL '24 hours'
              AND (payment_status = 'failed' OR status = 'pagamento_falhou')
        ");
        $stmt->execute([$customerId]);
        $result = $stmt->fetch();
        return (int)($result['cnt'] ?? 0);
    } catch (Exception $e) {
        error_log("[fraud-check] checkFailedPayments error: " . $e->getMessage());
        return 0;
    }
}

/**
 * Check if order value is unusually high (>5x customer average).
 */
function checkUnusualValue(PDO $db, int $customerId, float $orderTotal): array {
    $stmt = $db->prepare("
        SELECT AVG(total) as avg_total, COUNT(*) as cnt
        FROM om_market_orders
        WHERE customer_id = ?
          AND status NOT IN ('cancelado', 'cancelled')
          AND date_added > NOW() - INTERVAL '90 days'
    ");
    $stmt->execute([$customerId]);
    $result = $stmt->fetch();

    $avgTotal = (float)($result['avg_total'] ?? 0);
    $orderCount = (int)($result['cnt'] ?? 0);

    // Only flag if customer has history (3+ orders) and value is >5x average
    if ($orderCount >= 3 && $avgTotal > 0 && $orderTotal > ($avgTotal * 5)) {
        return ['unusual' => true, 'avg' => $avgTotal];
    }

    return ['unusual' => false, 'avg' => $avgTotal];
}

/**
 * Check if account is new (<24h) and order is high value (>R$200).
 */
function checkNewAccountHighValue(PDO $db, int $customerId, float $orderTotal): bool {
    if ($orderTotal <= 200) {
        return false;
    }

    try {
        // Check customer account age
        $stmt = $db->prepare("
            SELECT created_at FROM om_customers
            WHERE customer_id = ?
        ");
        $stmt->execute([$customerId]);
        $user = $stmt->fetch();

        if ($user && !empty($user['created_at'])) {
            $createdAt = strtotime($user['created_at']);
            $hoursOld = (time() - $createdAt) / 3600;
            return $hoursOld < 24;
        }
    } catch (Exception $e) {
        // Table might not exist — skip
    }

    return false;
}

/**
 * Check frequent cancellations in last 7 days.
 */
function checkFrequentCancellations(PDO $db, int $customerId): int {
    $stmt = $db->prepare("
        SELECT COUNT(*) as cnt
        FROM om_market_orders
        WHERE customer_id = ?
          AND status IN ('cancelado', 'cancelled')
          AND date_added > NOW() - INTERVAL '7 days'
    ");
    $stmt->execute([$customerId]);
    $result = $stmt->fetch();

    return (int)($result['cnt'] ?? 0);
}

/**
 * Check how many distinct IPs this customer used in 24h.
 */
function checkMultipleIPs(PDO $db, int $customerId, string $currentIp): int {
    try {
        $stmt = $db->prepare("
            SELECT COUNT(DISTINCT ip_address) as cnt
            FROM om_market_orders
            WHERE customer_id = ?
              AND date_added > NOW() - INTERVAL '24 hours'
              AND ip_address IS NOT NULL
              AND ip_address != ''
        ");
        $stmt->execute([$customerId]);
        $result = $stmt->fetch();
        return (int)($result['cnt'] ?? 0);
    } catch (Exception $e) {
        // ip_address column may not exist
        return 0;
    }
}

/**
 * Log fraud check result to om_fraud_signals table.
 */
function logFraudCheck(PDO $db, int $customerId, string $ip, float $orderTotal, int $score, array $signals, string $action): void {
    // Only log if score > 0 (don't fill table with clean orders)
    if ($score === 0) {
        return;
    }

    try {
        // Table om_fraud_signals created via migration

        $stmt = $db->prepare("
            INSERT INTO om_fraud_signals (customer_id, score, signals, action, ip_address)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $customerId,
            $score,
            json_encode([
                'signals' => $signals,
                'order_total' => $orderTotal,
            ], JSON_UNESCAPED_UNICODE),
            $action,
            $ip,
        ]);
    } catch (Exception $e) {
        error_log("[fraud-check] Error logging to om_fraud_signals: " . $e->getMessage());
    }
}
