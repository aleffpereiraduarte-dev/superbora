<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * CUSTOMER LIFETIME VALUE (CLV) CALCULATOR
 * Calculates CLV scores, tiers, churn risk, and treatment rules per customer.
 * Data source: om_market_orders. Persisted to om_customer_clv.
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Usage:
 *   require_once __DIR__ . '/../helpers/customer-clv.php';
 *   $clv = calculateCustomerCLV($db, $customerId);
 *   $dashboard = getCLVDashboard($db);
 */

/**
 * Calculate CLV metrics for a single customer and upsert into om_customer_clv.
 *
 * @param PDO $db         Database connection
 * @param int $customerId Customer ID
 * @return array          Full CLV data array
 */
function calculateCustomerCLV(PDO $db, int $customerId): array {
    try {
        // ── 1. Aggregate order history ──
        $stmt = $db->prepare("
            SELECT
                COUNT(*) as total_orders,
                COALESCE(SUM(total), 0) as total_spent,
                COALESCE(AVG(total), 0) as avg_order_value,
                MIN(date_added) as first_order_at,
                MAX(date_added) as last_order_at,
                EXTRACT(EPOCH FROM (MAX(date_added) - MIN(date_added))) / 86400 as days_as_customer
            FROM om_market_orders
            WHERE customer_id = ?
              AND status NOT IN ('cancelado', 'cancelled', 'recusado', 'pagamento_falhou')
        ");
        $stmt->execute([$customerId]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);

        $totalOrders    = (int)($stats['total_orders'] ?? 0);
        $totalSpent     = round((float)($stats['total_spent'] ?? 0), 2);
        $avgOrderValue  = round((float)($stats['avg_order_value'] ?? 0), 2);
        $firstOrderAt   = $stats['first_order_at'];
        $lastOrderAt    = $stats['last_order_at'];
        $daysAsCustomer = max(0, (int)($stats['days_as_customer'] ?? 0));

        // If customer has no orders, return empty CLV
        if ($totalOrders === 0) {
            $emptyData = [
                'customer_id' => $customerId,
                'total_orders' => 0,
                'total_spent' => 0,
                'avg_order_value' => 0,
                'first_order_at' => null,
                'last_order_at' => null,
                'days_as_customer' => 0,
                'order_frequency_days' => 0,
                'days_since_last_order' => 0,
                'predicted_monthly_value' => 0,
                'predicted_annual_value' => 0,
                'clv_score' => 0,
                'clv_tier' => 'new',
                'churn_risk' => 0,
                'preferred_channel' => null,
                'preferred_payment' => null,
                'favorite_partner_id' => null,
                'favorite_category' => null,
            ];
            upsertCustomerCLV($db, $customerId, $emptyData);
            return $emptyData;
        }

        // ── 2. Order frequency (avg gap between orders) ──
        $orderFrequencyDays = ($totalOrders > 1 && $daysAsCustomer > 0)
            ? round($daysAsCustomer / ($totalOrders - 1), 2)
            : 0;

        // ── 3. Days since last order ──
        $daysSinceLastOrder = 0;
        if ($lastOrderAt) {
            $daysSinceLastOrder = max(0, (int)floor((time() - strtotime($lastOrderAt)) / 86400));
        }

        // ── 4. Predicted values ──
        $effectiveFrequency = max($orderFrequencyDays, 1);
        $predictedMonthlyValue = round($avgOrderValue * (30 / $effectiveFrequency), 2);
        $predictedAnnualValue  = round($predictedMonthlyValue * 12, 2);

        // ── 5. CLV Score (0-100) ──
        // Recency: 100 if ordered today, -5 per day, min 0
        $recencyScore = max(0, 100 - ($daysSinceLastOrder * 5));

        // Frequency: orders in last 90 days * 10, capped at 100
        $stmtFreq = $db->prepare("
            SELECT COUNT(*) as cnt FROM om_market_orders
            WHERE customer_id = ?
              AND date_added > NOW() - INTERVAL '90 days'
              AND status NOT IN ('cancelado', 'cancelled', 'recusado', 'pagamento_falhou')
        ");
        $stmtFreq->execute([$customerId]);
        $orders90d = (int)$stmtFreq->fetchColumn();
        $frequencyScore = min($orders90d * 10, 100);

        // Monetary: avg_order_value * 2, capped at 100
        $monetaryScore = min((int)($avgOrderValue * 2), 100);

        // Tenure: days_as_customer / 3, capped at 100
        $tenureScore = min((int)($daysAsCustomer / 3), 100);

        // Weighted: recency 30%, frequency 30%, monetary 25%, tenure 15%
        $clvScore = (int)round(
            ($recencyScore * 0.30) +
            ($frequencyScore * 0.30) +
            ($monetaryScore * 0.25) +
            ($tenureScore * 0.15)
        );
        $clvScore = max(0, min(100, $clvScore));

        // ── 6. CLV Tier ──
        $clvTier = determineCLVTier($clvScore, $daysSinceLastOrder, $daysAsCustomer, $totalOrders, $db, $customerId);

        // ── 7. Churn risk (0-100) ──
        $churnRisk = calculateChurnRisk($daysSinceLastOrder, $orderFrequencyDays);

        // ── 8. Preferred channel ──
        $preferredChannel = null;
        try {
            $stmtCh = $db->prepare("
                SELECT source, COUNT(*) as cnt FROM om_market_orders
                WHERE customer_id = ? AND source IS NOT NULL AND source != ''
                GROUP BY source ORDER BY cnt DESC LIMIT 1
            ");
            $stmtCh->execute([$customerId]);
            $chRow = $stmtCh->fetch(PDO::FETCH_ASSOC);
            $preferredChannel = $chRow['source'] ?? null;
        } catch (Exception $e) {
            // source column may not exist
        }

        // ── 9. Preferred payment ──
        $preferredPayment = null;
        try {
            $stmtPay = $db->prepare("
                SELECT forma_pagamento, COUNT(*) as cnt FROM om_market_orders
                WHERE customer_id = ? AND forma_pagamento IS NOT NULL AND forma_pagamento != ''
                GROUP BY forma_pagamento ORDER BY cnt DESC LIMIT 1
            ");
            $stmtPay->execute([$customerId]);
            $payRow = $stmtPay->fetch(PDO::FETCH_ASSOC);
            $preferredPayment = $payRow['forma_pagamento'] ?? null;
        } catch (Exception $e) {
            // column may not exist
        }

        // ── 10. Favorite partner ──
        $favoritePartnerId = null;
        try {
            $stmtPart = $db->prepare("
                SELECT partner_id, COUNT(*) as cnt FROM om_market_orders
                WHERE customer_id = ? AND partner_id IS NOT NULL
                  AND status NOT IN ('cancelado', 'cancelled', 'recusado')
                GROUP BY partner_id ORDER BY cnt DESC LIMIT 1
            ");
            $stmtPart->execute([$customerId]);
            $partRow = $stmtPart->fetch(PDO::FETCH_ASSOC);
            $favoritePartnerId = $partRow ? (int)$partRow['partner_id'] : null;
        } catch (Exception $e) {
            // skip
        }

        // ── 11. Favorite category ──
        $favoriteCategory = null;
        try {
            $stmtCat = $db->prepare("
                SELECT p.categoria, COUNT(*) as cnt
                FROM om_market_orders o
                JOIN om_market_partners p ON o.partner_id = p.partner_id
                WHERE o.customer_id = ?
                  AND o.status NOT IN ('cancelado', 'cancelled', 'recusado')
                  AND p.categoria IS NOT NULL
                GROUP BY p.categoria ORDER BY cnt DESC LIMIT 1
            ");
            $stmtCat->execute([$customerId]);
            $catRow = $stmtCat->fetch(PDO::FETCH_ASSOC);
            $favoriteCategory = $catRow['categoria'] ?? null;
        } catch (Exception $e) {
            // skip
        }

        // ── 12. Build result and upsert ──
        $data = [
            'customer_id'           => $customerId,
            'total_orders'          => $totalOrders,
            'total_spent'           => $totalSpent,
            'avg_order_value'       => $avgOrderValue,
            'first_order_at'        => $firstOrderAt,
            'last_order_at'         => $lastOrderAt,
            'days_as_customer'      => $daysAsCustomer,
            'order_frequency_days'  => $orderFrequencyDays,
            'days_since_last_order' => $daysSinceLastOrder,
            'predicted_monthly_value' => $predictedMonthlyValue,
            'predicted_annual_value'  => $predictedAnnualValue,
            'clv_score'             => $clvScore,
            'clv_tier'              => $clvTier,
            'churn_risk'            => $churnRisk,
            'preferred_channel'     => $preferredChannel,
            'preferred_payment'     => $preferredPayment,
            'favorite_partner_id'   => $favoritePartnerId,
            'favorite_category'     => $favoriteCategory,
        ];

        upsertCustomerCLV($db, $customerId, $data);

        return $data;

    } catch (Exception $e) {
        error_log("[customer-clv] Error calculating CLV for customer {$customerId}: " . $e->getMessage());
        return [
            'customer_id' => $customerId,
            'error' => 'calculation_failed',
            'clv_score' => 0,
            'clv_tier' => 'new',
            'churn_risk' => 0,
        ];
    }
}

/**
 * Determine CLV tier based on score and behavior.
 */
function determineCLVTier(int $score, int $daysSinceLastOrder, int $daysAsCustomer, int $totalOrders, PDO $db, int $customerId): string {
    // Churned: no order in >90 days
    if ($daysSinceLastOrder > 90) {
        return 'churned';
    }

    // At risk: no order in >45 days
    if ($daysSinceLastOrder > 45) {
        return 'at_risk';
    }

    // VIP: score > 80
    if ($score > 80) {
        return 'vip';
    }

    // New: less than 30 days tenure and fewer than 3 orders
    if ($daysAsCustomer < 30 && $totalOrders < 3) {
        return 'new';
    }

    // Growing: check if frequency is increasing (more orders in last 30d vs previous 30d)
    try {
        $stmtRecent = $db->prepare("
            SELECT COUNT(*) as cnt FROM om_market_orders
            WHERE customer_id = ?
              AND date_added > NOW() - INTERVAL '30 days'
              AND status NOT IN ('cancelado', 'cancelled', 'recusado')
        ");
        $stmtRecent->execute([$customerId]);
        $recent30d = (int)$stmtRecent->fetchColumn();

        $stmtPrev = $db->prepare("
            SELECT COUNT(*) as cnt FROM om_market_orders
            WHERE customer_id = ?
              AND date_added BETWEEN NOW() - INTERVAL '60 days' AND NOW() - INTERVAL '30 days'
              AND status NOT IN ('cancelado', 'cancelled', 'recusado')
        ");
        $stmtPrev->execute([$customerId]);
        $prev30d = (int)$stmtPrev->fetchColumn();

        if ($recent30d > $prev30d && $recent30d >= 2) {
            return 'growing';
        }
    } catch (Exception $e) {
        // Fall through to stable
    }

    return 'stable';
}

/**
 * Calculate churn risk (0-100) based on days since last order vs avg frequency.
 */
function calculateChurnRisk(int $daysSinceLastOrder, float $orderFrequencyDays): float {
    if ($daysSinceLastOrder <= 0) {
        return 0;
    }

    // If no established frequency, use 14 days as baseline
    $baseline = $orderFrequencyDays > 0 ? $orderFrequencyDays : 14;

    // Ratio of silence to expected frequency
    $ratio = $daysSinceLastOrder / $baseline;

    // Sigmoid-like curve: 0 at ratio=0, ~50 at ratio=2, ~90 at ratio=4
    $risk = 100 * (1 - exp(-0.35 * $ratio));

    return round(min(100, max(0, $risk)), 2);
}

/**
 * Upsert CLV data into om_customer_clv.
 */
function upsertCustomerCLV(PDO $db, int $customerId, array $data): void {
    try {
        // Lookup customer phone
        $phone = null;
        try {
            $stmtPhone = $db->prepare("SELECT phone FROM om_customers WHERE customer_id = ?");
            $stmtPhone->execute([$customerId]);
            $phone = $stmtPhone->fetchColumn() ?: null;
        } catch (Exception $e) {
            // skip
        }

        $stmt = $db->prepare("
            INSERT INTO om_customer_clv (
                customer_id, phone, total_orders, total_spent, avg_order_value,
                first_order_at, last_order_at, days_as_customer, order_frequency_days,
                predicted_monthly_value, predicted_annual_value,
                clv_score, clv_tier, churn_risk, days_since_last_order,
                preferred_channel, preferred_payment, favorite_partner_id, favorite_category,
                last_calculated_at
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?,
                NOW()
            )
            ON CONFLICT (customer_id) DO UPDATE SET
                phone = EXCLUDED.phone,
                total_orders = EXCLUDED.total_orders,
                total_spent = EXCLUDED.total_spent,
                avg_order_value = EXCLUDED.avg_order_value,
                first_order_at = EXCLUDED.first_order_at,
                last_order_at = EXCLUDED.last_order_at,
                days_as_customer = EXCLUDED.days_as_customer,
                order_frequency_days = EXCLUDED.order_frequency_days,
                predicted_monthly_value = EXCLUDED.predicted_monthly_value,
                predicted_annual_value = EXCLUDED.predicted_annual_value,
                clv_score = EXCLUDED.clv_score,
                clv_tier = EXCLUDED.clv_tier,
                churn_risk = EXCLUDED.churn_risk,
                days_since_last_order = EXCLUDED.days_since_last_order,
                preferred_channel = EXCLUDED.preferred_channel,
                preferred_payment = EXCLUDED.preferred_payment,
                favorite_partner_id = EXCLUDED.favorite_partner_id,
                favorite_category = EXCLUDED.favorite_category,
                last_calculated_at = NOW()
        ");
        $stmt->execute([
            $customerId,
            $phone,
            $data['total_orders'] ?? 0,
            $data['total_spent'] ?? 0,
            $data['avg_order_value'] ?? 0,
            $data['first_order_at'] ?? null,
            $data['last_order_at'] ?? null,
            $data['days_as_customer'] ?? 0,
            $data['order_frequency_days'] ?? 0,
            $data['predicted_monthly_value'] ?? 0,
            $data['predicted_annual_value'] ?? 0,
            $data['clv_score'] ?? 0,
            $data['clv_tier'] ?? 'new',
            $data['churn_risk'] ?? 0,
            $data['days_since_last_order'] ?? 0,
            $data['preferred_channel'] ?? null,
            $data['preferred_payment'] ?? null,
            $data['favorite_partner_id'] ?? null,
            $data['favorite_category'] ?? null,
        ]);
    } catch (Exception $e) {
        error_log("[customer-clv] Error upserting CLV for customer {$customerId}: " . $e->getMessage());
    }
}

/**
 * Recalculate CLV for all active customers (for cron usage).
 * Targets customers who ordered in the last 90 days.
 *
 * @param PDO $db    Database connection
 * @param int $limit Max customers to process per run
 * @return array     Stats: processed, errors, duration_ms
 */
function recalculateAllCLV(PDO $db, int $limit = 500): array {
    $startTime = microtime(true);
    $processed = 0;
    $errors    = 0;

    try {
        // Get distinct customers who ordered recently, prioritizing those not calculated recently
        $stmt = $db->prepare("
            SELECT DISTINCT o.customer_id
            FROM om_market_orders o
            LEFT JOIN om_customer_clv c ON o.customer_id = c.customer_id
            WHERE o.date_added > NOW() - INTERVAL '90 days'
              AND o.customer_id IS NOT NULL
              AND o.status NOT IN ('cancelado', 'cancelled', 'recusado', 'pagamento_falhou')
            ORDER BY COALESCE(c.last_calculated_at, '2000-01-01') ASC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        $customers = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($customers as $cid) {
            try {
                calculateCustomerCLV($db, (int)$cid);
                $processed++;
            } catch (Exception $e) {
                error_log("[customer-clv] Recalc error for customer {$cid}: " . $e->getMessage());
                $errors++;
            }
        }
    } catch (Exception $e) {
        error_log("[customer-clv] recalculateAllCLV error: " . $e->getMessage());
    }

    $durationMs = (int)((microtime(true) - $startTime) * 1000);

    return [
        'processed' => $processed,
        'errors' => $errors,
        'duration_ms' => $durationMs,
        'total_candidates' => count($customers ?? []),
    ];
}

/**
 * Get CLV dashboard data: tier distribution, top VIPs, at-risk, revenue concentration.
 *
 * @param PDO $db Database connection
 * @return array  Dashboard metrics
 */
function getCLVDashboard(PDO $db): array {
    try {
        // ── Tier distribution ──
        $stmtTier = $db->query("
            SELECT clv_tier, COUNT(*) as count, AVG(clv_score) as avg_score,
                   AVG(predicted_annual_value) as avg_annual_value,
                   SUM(total_spent) as tier_total_spent
            FROM om_customer_clv
            GROUP BY clv_tier
            ORDER BY avg_score DESC
        ");
        $tierDistribution = $stmtTier->fetchAll(PDO::FETCH_ASSOC);

        // Format tier data
        foreach ($tierDistribution as &$tier) {
            $tier['count'] = (int)$tier['count'];
            $tier['avg_score'] = round((float)$tier['avg_score'], 1);
            $tier['avg_annual_value'] = round((float)$tier['avg_annual_value'], 2);
            $tier['tier_total_spent'] = round((float)$tier['tier_total_spent'], 2);
        }
        unset($tier);

        // ── Top 20 VIPs ──
        $stmtVip = $db->query("
            SELECT c.customer_id, c.phone, c.clv_score, c.clv_tier, c.total_orders,
                   c.total_spent, c.avg_order_value, c.predicted_annual_value,
                   c.days_since_last_order, c.churn_risk, c.favorite_partner_id
            FROM om_customer_clv c
            ORDER BY c.clv_score DESC, c.total_spent DESC
            LIMIT 20
        ");
        $topVips = $stmtVip->fetchAll(PDO::FETCH_ASSOC);

        // ── At-risk customers (tier = at_risk or churn_risk > 60) ──
        $stmtRisk = $db->query("
            SELECT c.customer_id, c.phone, c.clv_score, c.clv_tier, c.total_orders,
                   c.total_spent, c.days_since_last_order, c.churn_risk,
                   c.predicted_annual_value, c.last_order_at
            FROM om_customer_clv c
            WHERE c.clv_tier IN ('at_risk', 'churned') OR c.churn_risk > 60
            ORDER BY c.predicted_annual_value DESC
            LIMIT 50
        ");
        $atRiskCustomers = $stmtRisk->fetchAll(PDO::FETCH_ASSOC);

        // ── Revenue concentration (top 10% vs rest) ──
        $stmtTotal = $db->query("
            SELECT
                COUNT(*) as total_customers,
                SUM(total_spent) as total_revenue,
                AVG(clv_score) as avg_clv_score,
                AVG(churn_risk) as avg_churn_risk
            FROM om_customer_clv
        ");
        $totals = $stmtTotal->fetch(PDO::FETCH_ASSOC);
        $totalCustomers = (int)($totals['total_customers'] ?? 0);
        $totalRevenue   = (float)($totals['total_revenue'] ?? 0);

        $top10Pct = 0;
        if ($totalCustomers > 0) {
            $top10Limit = max(1, (int)ceil($totalCustomers * 0.1));
            $stmtTop = $db->prepare("
                SELECT SUM(total_spent) as top_revenue
                FROM (
                    SELECT total_spent FROM om_customer_clv
                    ORDER BY total_spent DESC LIMIT ?
                ) sub
            ");
            $stmtTop->execute([$top10Limit]);
            $topRevenue = (float)$stmtTop->fetchColumn();
            $top10Pct = $totalRevenue > 0 ? round(($topRevenue / $totalRevenue) * 100, 1) : 0;
        }

        // ── Churn predictions (expected revenue loss from at-risk) ──
        $stmtChurnLoss = $db->query("
            SELECT SUM(predicted_annual_value) as at_risk_revenue,
                   COUNT(*) as at_risk_count
            FROM om_customer_clv
            WHERE clv_tier IN ('at_risk', 'churned')
        ");
        $churnLoss = $stmtChurnLoss->fetch(PDO::FETCH_ASSOC);

        return [
            'tier_distribution' => $tierDistribution,
            'top_vips' => $topVips,
            'at_risk_customers' => $atRiskCustomers,
            'totals' => [
                'total_customers' => $totalCustomers,
                'total_revenue' => round($totalRevenue, 2),
                'avg_clv_score' => round((float)($totals['avg_clv_score'] ?? 0), 1),
                'avg_churn_risk' => round((float)($totals['avg_churn_risk'] ?? 0), 1),
            ],
            'revenue_concentration' => [
                'top_10_percent_share' => $top10Pct,
                'total_revenue' => round($totalRevenue, 2),
            ],
            'churn_predictions' => [
                'at_risk_count' => (int)($churnLoss['at_risk_count'] ?? 0),
                'at_risk_annual_revenue' => round((float)($churnLoss['at_risk_revenue'] ?? 0), 2),
            ],
        ];
    } catch (Exception $e) {
        error_log("[customer-clv] getCLVDashboard error: " . $e->getMessage());
        return [
            'error' => 'dashboard_failed',
            'tier_distribution' => [],
            'top_vips' => [],
            'at_risk_customers' => [],
        ];
    }
}

/**
 * Get treatment instructions for a customer based on CLV tier.
 * Used by call center, support, and proactive outreach to decide how to treat each customer.
 *
 * @param PDO $db         Database connection
 * @param int $customerId Customer ID
 * @return array          Treatment rules
 */
function getCustomerCLVTreatment(PDO $db, int $customerId): array {
    try {
        $stmt = $db->prepare("
            SELECT clv_score, clv_tier, churn_risk, total_orders, total_spent,
                   predicted_annual_value, days_since_last_order, avg_order_value
            FROM om_customer_clv
            WHERE customer_id = ?
        ");
        $stmt->execute([$customerId]);
        $clv = $stmt->fetch(PDO::FETCH_ASSOC);

        // If no CLV record, calculate it first
        if (!$clv) {
            $calculated = calculateCustomerCLV($db, $customerId);
            $clv = [
                'clv_score' => $calculated['clv_score'] ?? 0,
                'clv_tier' => $calculated['clv_tier'] ?? 'new',
                'churn_risk' => $calculated['churn_risk'] ?? 0,
                'total_orders' => $calculated['total_orders'] ?? 0,
                'total_spent' => $calculated['total_spent'] ?? 0,
                'predicted_annual_value' => $calculated['predicted_annual_value'] ?? 0,
                'days_since_last_order' => $calculated['days_since_last_order'] ?? 0,
                'avg_order_value' => $calculated['avg_order_value'] ?? 0,
            ];
        }

        $tier = $clv['clv_tier'] ?? 'new';
        $score = (int)($clv['clv_score'] ?? 0);
        $churnRisk = (float)($clv['churn_risk'] ?? 0);

        // Base treatment rules per tier
        $treatments = [
            'vip' => [
                'priority_level' => 'highest',
                'discount_eligible' => true,
                'max_discount_percent' => 15,
                'proactive_messaging' => 'weekly',
                'compensation_generosity' => 'high',
                'max_auto_compensation' => 20.00,
                'support_queue_priority' => 1,
                'free_delivery_eligible' => true,
                'personalized_offers' => true,
                'dedicated_support' => true,
                'description' => 'Cliente VIP - prioridade maxima, compensacao generosa, ofertas personalizadas',
            ],
            'stable' => [
                'priority_level' => 'high',
                'discount_eligible' => true,
                'max_discount_percent' => 10,
                'proactive_messaging' => 'biweekly',
                'compensation_generosity' => 'medium',
                'max_auto_compensation' => 15.00,
                'support_queue_priority' => 2,
                'free_delivery_eligible' => false,
                'personalized_offers' => true,
                'dedicated_support' => false,
                'description' => 'Cliente estavel - boa prioridade, manter engajamento',
            ],
            'growing' => [
                'priority_level' => 'medium',
                'discount_eligible' => true,
                'max_discount_percent' => 10,
                'proactive_messaging' => 'weekly',
                'compensation_generosity' => 'medium',
                'max_auto_compensation' => 12.00,
                'support_queue_priority' => 2,
                'free_delivery_eligible' => false,
                'personalized_offers' => true,
                'dedicated_support' => false,
                'description' => 'Cliente crescendo - investir para fidelizar',
            ],
            'new' => [
                'priority_level' => 'medium',
                'discount_eligible' => true,
                'max_discount_percent' => 20,
                'proactive_messaging' => 'weekly',
                'compensation_generosity' => 'high',
                'max_auto_compensation' => 15.00,
                'support_queue_priority' => 3,
                'free_delivery_eligible' => true,
                'personalized_offers' => false,
                'dedicated_support' => false,
                'description' => 'Cliente novo - primeira impressao e crucial, ser generoso',
            ],
            'at_risk' => [
                'priority_level' => 'high',
                'discount_eligible' => true,
                'max_discount_percent' => 20,
                'proactive_messaging' => 'weekly',
                'compensation_generosity' => 'very_high',
                'max_auto_compensation' => 25.00,
                'support_queue_priority' => 1,
                'free_delivery_eligible' => true,
                'personalized_offers' => true,
                'dedicated_support' => false,
                'winback_campaign' => true,
                'description' => 'Cliente em risco - acionar campanha de winback, compensacao generosa',
            ],
            'churned' => [
                'priority_level' => 'low',
                'discount_eligible' => true,
                'max_discount_percent' => 25,
                'proactive_messaging' => 'monthly',
                'compensation_generosity' => 'very_high',
                'max_auto_compensation' => 30.00,
                'support_queue_priority' => 4,
                'free_delivery_eligible' => true,
                'personalized_offers' => false,
                'dedicated_support' => false,
                'winback_campaign' => true,
                'description' => 'Cliente perdido - desconto agressivo para reconquistar',
            ],
        ];

        $treatment = $treatments[$tier] ?? $treatments['new'];

        return [
            'customer_id' => $customerId,
            'clv_score' => $score,
            'clv_tier' => $tier,
            'churn_risk' => $churnRisk,
            'total_orders' => (int)($clv['total_orders'] ?? 0),
            'total_spent' => round((float)($clv['total_spent'] ?? 0), 2),
            'predicted_annual_value' => round((float)($clv['predicted_annual_value'] ?? 0), 2),
            'treatment' => $treatment,
        ];

    } catch (Exception $e) {
        error_log("[customer-clv] getCustomerCLVTreatment error for {$customerId}: " . $e->getMessage());
        return [
            'customer_id' => $customerId,
            'clv_score' => 0,
            'clv_tier' => 'new',
            'churn_risk' => 0,
            'treatment' => [
                'priority_level' => 'medium',
                'discount_eligible' => false,
                'max_discount_percent' => 5,
                'proactive_messaging' => 'none',
                'compensation_generosity' => 'low',
                'max_auto_compensation' => 5.00,
                'support_queue_priority' => 5,
                'description' => 'Fallback - dados CLV indisponiveis',
            ],
        ];
    }
}
