<?php
/**
 * Cron: Daily churn analysis
 * Run once daily: 0 6 * * * php /var/www/html/api/mercado/cron/churn-analysis.php
 *
 * Scores all customers with at least 1 order, updates om_churn_scores table.
 * Auto-sends retention push to critical risk customers who haven't been contacted.
 */

require_once __DIR__ . '/../config/database.php';

$db = getDB();

// 1. Find all customers with orders in the last 6 months
$customers = $db->query("
    SELECT DISTINCT customer_id
    FROM om_market_orders
    WHERE status IN ('entregue', 'delivered', 'finalizado')
    AND created_at > NOW() - INTERVAL '180 days'
    ORDER BY customer_id
")->fetchAll();

$scored = 0;
$critical = 0;
$actioned = 0;

foreach ($customers as $c) {
    $cid = (int)$c['customer_id'];

    // Get order stats
    $stmt = $db->prepare("
        SELECT
            COUNT(*) as total_orders,
            MAX(created_at) as last_order_at,
            AVG(total) as avg_ticket,
            MIN(created_at) as first_order_at
        FROM om_market_orders
        WHERE customer_id = ? AND status IN ('entregue', 'delivered', 'finalizado')
    ");
    $stmt->execute([$cid]);
    $orders = $stmt->fetch();

    $totalOrders = (int)$orders['total_orders'];
    $lastOrderAt = $orders['last_order_at'];
    $avgTicket = round((float)($orders['avg_ticket'] ?? 0), 2);

    $daysSinceLast = $lastOrderAt
        ? max(0, (int)((time() - strtotime($lastOrderAt)) / 86400))
        : 999;

    $firstOrderAt = $orders['first_order_at'];
    $daysSinceFirst = $firstOrderAt ? max(1, (int)((time() - strtotime($firstOrderAt)) / 86400)) : 1;
    $frequency = round(($totalOrders / $daysSinceFirst) * 30, 2);

    // Cancelamentos
    $stmtC = $db->prepare("SELECT COUNT(*) FROM om_market_orders WHERE customer_id = ? AND status = 'cancelado'");
    $stmtC->execute([$cid]);
    $complaints = (int)$stmtC->fetchColumn();

    // Low ratings
    $stmtR = $db->prepare("SELECT COUNT(*) FROM om_market_orders WHERE customer_id = ? AND avaliacao_cliente IS NOT NULL AND avaliacao_cliente <= 2");
    $stmtR->execute([$cid]);
    $lowRatings = (int)$stmtR->fetchColumn();

    // Score
    $score = 0;
    if ($daysSinceLast >= 90) $score += 40;
    elseif ($daysSinceLast >= 60) $score += 30;
    elseif ($daysSinceLast >= 30) $score += 20;
    elseif ($daysSinceLast >= 14) $score += 10;
    elseif ($daysSinceLast >= 7) $score += 5;

    if ($frequency < 0.5) $score += 25;
    elseif ($frequency < 1.0) $score += 15;
    elseif ($frequency < 2.0) $score += 8;

    if ($totalOrders <= 1) $score += 15;
    elseif ($totalOrders <= 3) $score += 8;

    $score += min(10, $complaints * 3);
    $score += min(10, $lowRatings * 5);
    $score = min(100, max(0, $score));

    if ($score >= 70) $riskLevel = 'critical';
    elseif ($score >= 50) $riskLevel = 'high';
    elseif ($score >= 30) $riskLevel = 'medium';
    else $riskLevel = 'low';

    // Upsert score
    $db->prepare("
        INSERT INTO om_churn_scores (customer_id, score, risk_level, last_order_days, order_frequency, avg_ticket, action_taken, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, 'none', NOW(), NOW())
        ON CONFLICT (customer_id) DO UPDATE SET
            score = EXCLUDED.score,
            risk_level = EXCLUDED.risk_level,
            last_order_days = EXCLUDED.last_order_days,
            order_frequency = EXCLUDED.order_frequency,
            avg_ticket = EXCLUDED.avg_ticket,
            updated_at = NOW()
    ")->execute([$cid, $score, $riskLevel, $daysSinceLast, $frequency, $avgTicket]);

    $scored++;
    if ($riskLevel === 'critical') $critical++;
}

// 2. Auto-send retention push to critical customers not contacted in 14 days
$autoTargets = $db->query("
    SELECT cs.customer_id
    FROM om_churn_scores cs
    WHERE cs.risk_level = 'critical'
    AND (cs.action_at IS NULL OR cs.action_at < NOW() - INTERVAL '14 days')
    AND cs.action_taken IN ('none', 'push_sent')
    LIMIT 50
")->fetchAll();

if (!empty($autoTargets)) {
    require_once __DIR__ . '/../helpers/NotificationSender.php';
    $sender = NotificationSender::getInstance($db);

    foreach ($autoTargets as $t) {
        try {
            $sender->notifyCustomer(
                (int)$t['customer_id'],
                'Sentimos sua falta! 💛',
                'Faz tempo que voce nao pede no SuperBora. Volte e confira nossas novidades e ofertas!',
                ['type' => 'churn_retention_auto']
            );

            $db->prepare("UPDATE om_churn_scores SET action_taken = 'push_sent', action_at = NOW() WHERE customer_id = ?")
                ->execute([$t['customer_id']]);

            $actioned++;
        } catch (Exception $e) {
            error_log("[ChurnCron] Push error for #{$t['customer_id']}: " . $e->getMessage());
        }
    }
}

error_log("[ChurnCron] Scored {$scored} customers, {$critical} critical, {$actioned} auto-actioned");
