<?php
/**
 * /api/mercado/admin/cestas/financeiro.php
 * Financial reports for basket subscriptions (admin only).
 *
 * GET ?view=overview&period=month      — Revenue, costs, margins summary
 * GET ?view=by_type                    — Revenue breakdown by basket type
 * GET ?view=payments&status=paid       — Payment collection status
 * GET ?view=churn                      — Churn rate over time
 * GET ?view=costs&date=YYYY-MM-DD      — Cost breakdown for a date/period
 */
require_once __DIR__ . "/../../config/database.php";
require_once dirname(__DIR__, 4) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    $payload = om_auth()->requireAdmin();

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        response(false, null, 'Metodo nao permitido', 405);
    }

    // Ensure tables
    $db->exec("
        CREATE TABLE IF NOT EXISTS om_cesta_payments (
            id SERIAL PRIMARY KEY,
            subscription_id INT NOT NULL,
            order_id INT,
            customer_id INT NOT NULL,
            amount NUMERIC(10,2) NOT NULL,
            payment_method VARCHAR(30) DEFAULT 'credit_card',
            status VARCHAR(20) DEFAULT 'pending',
            stripe_payment_id VARCHAR(100),
            paid_at TIMESTAMP,
            failed_reason TEXT,
            created_at TIMESTAMP DEFAULT NOW()
        );
        CREATE TABLE IF NOT EXISTS om_cesta_costs (
            id SERIAL PRIMARY KEY,
            order_id INT NOT NULL,
            items_cost NUMERIC(10,2) DEFAULT 0,
            packaging_cost NUMERIC(10,2) DEFAULT 0,
            delivery_cost NUMERIC(10,2) DEFAULT 0,
            total_cost NUMERIC(10,2) DEFAULT 0,
            notes TEXT,
            created_at TIMESTAMP DEFAULT NOW()
        );
        CREATE TABLE IF NOT EXISTS om_cesta_recipe (
            id SERIAL PRIMARY KEY,
            cesta_type_id INTEGER NOT NULL,
            variant_id INTEGER,
            ingrediente_id INTEGER NOT NULL,
            quantity NUMERIC(12,2) NOT NULL,
            is_optional BOOLEAN DEFAULT false,
            created_at TIMESTAMP DEFAULT NOW()
        );
        CREATE TABLE IF NOT EXISTS om_cesta_ingredientes (
            id SERIAL PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            unit VARCHAR(20) NOT NULL,
            category VARCHAR(50),
            fornecedor_id INTEGER,
            cost_per_unit NUMERIC(12,4) NOT NULL DEFAULT 0,
            minimum_stock NUMERIC(12,2) DEFAULT 0,
            shelf_life_days INTEGER DEFAULT 7,
            storage_location VARCHAR(100),
            image_url TEXT,
            barcode VARCHAR(50),
            is_active BOOLEAN DEFAULT true,
            created_at TIMESTAMP DEFAULT NOW(),
            updated_at TIMESTAMP DEFAULT NOW()
        );
    ");

    // Fixed per-basket overheads used when no entry in om_cesta_costs exists.
    $PACKAGING_COST = 3.50;
    $DELIVERY_COST  = 8.00;

    $view = $_GET['view'] ?? 'overview';
    $period = $_GET['period'] ?? 'month'; // week, month, quarter, year
    $dateFrom = $_GET['date_from'] ?? null;
    $dateTo = $_GET['date_to'] ?? null;

    // Calculate date range from period
    if (!$dateFrom || !$dateTo) {
        $now = new DateTime();
        switch ($period) {
            case 'week':
                $dateFrom = (clone $now)->modify('-7 days')->format('Y-m-d');
                $dateTo = $now->format('Y-m-d');
                break;
            case 'quarter':
                $dateFrom = (clone $now)->modify('-90 days')->format('Y-m-d');
                $dateTo = $now->format('Y-m-d');
                break;
            case 'year':
                $dateFrom = (clone $now)->modify('-365 days')->format('Y-m-d');
                $dateTo = $now->format('Y-m-d');
                break;
            default: // month
                $dateFrom = (clone $now)->modify('-30 days')->format('Y-m-d');
                $dateTo = $now->format('Y-m-d');
        }
    }

    // ── Overview ─────────────────────────────────────────────────
    if ($view === 'overview') {
        // Revenue from payments
        $stmt = $db->prepare("
            SELECT
                COALESCE(SUM(amount), 0) AS total_revenue,
                COUNT(*) AS total_payments,
                COUNT(*) FILTER (WHERE status = 'paid') AS paid_count,
                COUNT(*) FILTER (WHERE status = 'pending') AS pending_count,
                COUNT(*) FILTER (WHERE status = 'failed') AS failed_count,
                COALESCE(SUM(amount) FILTER (WHERE status = 'paid'), 0) AS paid_revenue,
                COALESCE(SUM(amount) FILTER (WHERE status = 'pending'), 0) AS pending_revenue
            FROM om_cesta_payments
            WHERE created_at::date BETWEEN ? AND ?
        ");
        $stmt->execute([$dateFrom, $dateTo]);
        $revenue = $stmt->fetch(PDO::FETCH_ASSOC);

        // Estimated revenue from active subscriptions (base_price * active count)
        $stmt = $db->query("
            SELECT
                COALESCE(SUM(b.base_price), 0) AS estimated_monthly_revenue,
                COUNT(*) AS active_subscriptions
            FROM om_box_subscriptions s
            JOIN om_subscription_boxes b ON s.box_id = b.id
            WHERE s.status = 'active'
        ");
        $estimated = $stmt->fetch(PDO::FETCH_ASSOC);

        // Total costs
        $stmt = $db->prepare("
            SELECT
                COALESCE(SUM(items_cost), 0) AS total_items_cost,
                COALESCE(SUM(packaging_cost), 0) AS total_packaging_cost,
                COALESCE(SUM(delivery_cost), 0) AS total_delivery_cost,
                COALESCE(SUM(total_cost), 0) AS total_cost
            FROM om_cesta_costs co
            JOIN om_cesta_orders o ON co.order_id = o.id
            WHERE o.delivery_date BETWEEN ? AND ?
        ");
        $stmt->execute([$dateFrom, $dateTo]);
        $costs = $stmt->fetch(PDO::FETCH_ASSOC);

        $paidRevenue = (float)$revenue['paid_revenue'];
        $totalCost = (float)$costs['total_cost'];
        $profit = $paidRevenue - $totalCost;
        $margin = $paidRevenue > 0 ? round(($profit / $paidRevenue) * 100, 1) : 0;

        // Daily revenue trend
        $stmt = $db->prepare("
            SELECT
                created_at::date AS date,
                COALESCE(SUM(amount) FILTER (WHERE status = 'paid'), 0) AS daily_revenue
            FROM om_cesta_payments
            WHERE created_at::date BETWEEN ? AND ?
            GROUP BY created_at::date
            ORDER BY date ASC
        ");
        $stmt->execute([$dateFrom, $dateTo]);
        $dailyRevenue = $stmt->fetchAll(PDO::FETCH_ASSOC);

        response(true, [
            'revenue' => [
                'total' => round((float)$revenue['total_revenue'], 2),
                'paid' => round($paidRevenue, 2),
                'pending' => round((float)$revenue['pending_revenue'], 2),
                'paid_count' => (int)$revenue['paid_count'],
                'pending_count' => (int)$revenue['pending_count'],
                'failed_count' => (int)$revenue['failed_count'],
            ],
            'costs' => [
                'items' => round((float)$costs['total_items_cost'], 2),
                'packaging' => round((float)$costs['total_packaging_cost'], 2),
                'delivery' => round((float)$costs['total_delivery_cost'], 2),
                'total' => round($totalCost, 2),
            ],
            'profit' => round($profit, 2),
            'margin_percent' => $margin,
            'estimated_monthly' => round((float)$estimated['estimated_monthly_revenue'], 2),
            'active_subscriptions' => (int)$estimated['active_subscriptions'],
            'daily_revenue' => $dailyRevenue,
            'period' => ['from' => $dateFrom, 'to' => $dateTo],
        ]);
    }

    // ── Revenue by basket type ───────────────────────────────────
    if ($view === 'by_type') {
        $stmt = $db->prepare("
            SELECT
                b.id AS box_id,
                b.name AS box_name,
                b.category,
                b.base_price,
                COUNT(DISTINCT s.id) FILTER (WHERE s.status = 'active') AS active_subscribers,
                COUNT(DISTINCT s.id) AS total_subscribers,
                COALESCE(SUM(p.amount) FILTER (WHERE p.status = 'paid'), 0) AS total_revenue,
                COUNT(p.id) FILTER (WHERE p.status = 'paid') AS paid_orders,
                COALESCE(
                    (SELECT SUM(co.total_cost) FROM om_cesta_costs co
                     JOIN om_cesta_orders o2 ON co.order_id = o2.id
                     WHERE o2.box_id = b.id AND o2.delivery_date BETWEEN ? AND ?),
                0) AS actual_cost,
                COALESCE((
                    SELECT SUM(r.quantity * i.cost_per_unit)
                    FROM om_cesta_recipe r
                    JOIN om_cesta_ingredientes i ON i.id = r.ingrediente_id
                    WHERE r.cesta_type_id = b.id AND r.variant_id IS NULL AND r.is_optional = false
                ), 0) AS recipe_items_cost,
                (SELECT COUNT(*) FROM om_cesta_orders o3 WHERE o3.box_id = b.id AND o3.delivery_date BETWEEN ? AND ? AND o3.status NOT IN ('cancelled','failed')) AS delivered_count
            FROM om_subscription_boxes b
            LEFT JOIN om_box_subscriptions s ON s.box_id = b.id
            LEFT JOIN om_cesta_payments p ON p.subscription_id = s.id
                AND p.created_at::date BETWEEN ? AND ?
            WHERE b.is_active = true
            GROUP BY b.id, b.name, b.category, b.base_price
            ORDER BY total_revenue DESC
        ");
        $stmt->execute([$dateFrom, $dateTo, $dateFrom, $dateTo, $dateFrom, $dateTo]);
        $types = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($types as &$t) {
            $t['base_price']     = (float)$t['base_price'];
            $t['total_revenue']  = round((float)$t['total_revenue'], 2);
            $t['actual_cost']    = round((float)$t['actual_cost'], 2);
            $t['recipe_items_cost'] = round((float)$t['recipe_items_cost'], 2);

            // Per-basket cost (ingredients + packaging + delivery)
            $t['per_basket_items_cost'] = $t['recipe_items_cost'];
            $t['per_basket_total_cost'] = round($t['recipe_items_cost'] + $PACKAGING_COST + $DELIVERY_COST, 2);
            $t['per_basket_profit']     = round($t['base_price'] - $t['per_basket_total_cost'], 2);
            $t['per_basket_margin']     = $t['base_price'] > 0
                ? round(($t['per_basket_profit'] / $t['base_price']) * 100, 1) : 0;

            // Use actual cost if recorded, else project from recipe*delivered_count
            $projectedCost = $t['per_basket_total_cost'] * (int)$t['delivered_count'];
            $t['total_cost'] = $t['actual_cost'] > 0
                ? $t['actual_cost']
                : round($projectedCost, 2);
            $t['profit']     = round($t['total_revenue'] - $t['total_cost'], 2);
            $t['margin_percent'] = $t['total_revenue'] > 0
                ? round(($t['profit'] / $t['total_revenue']) * 100, 1)
                : 0;

            $t['active_subscribers'] = (int)$t['active_subscribers'];
            $t['total_subscribers']  = (int)$t['total_subscribers'];
            $t['paid_orders']        = (int)$t['paid_orders'];
            $t['delivered_count']    = (int)$t['delivered_count'];
        }

        response(true, ['by_type' => $types, 'period' => ['from' => $dateFrom, 'to' => $dateTo]]);
    }

    // ── Profitability: per-basket cost/profit breakdown from recipes ──
    if ($view === 'profitability') {
        $stmt = $db->query("
            SELECT
                b.id, b.name, b.category, b.base_price,
                COALESCE((
                    SELECT SUM(r.quantity * i.cost_per_unit)
                    FROM om_cesta_recipe r
                    JOIN om_cesta_ingredientes i ON i.id = r.ingrediente_id
                    WHERE r.cesta_type_id = b.id AND r.variant_id IS NULL AND r.is_optional = false
                ), 0) AS items_cost,
                (SELECT COUNT(*) FROM om_cesta_recipe r WHERE r.cesta_type_id = b.id) AS ingredient_count
            FROM om_subscription_boxes b
            WHERE b.is_active = true
            ORDER BY b.sort_order ASC, b.name ASC
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['base_price']       = (float)$r['base_price'];
            $r['items_cost']       = round((float)$r['items_cost'], 2);
            $r['packaging_cost']   = $PACKAGING_COST;
            $r['delivery_cost']    = $DELIVERY_COST;
            $r['total_cost']       = round($r['items_cost'] + $PACKAGING_COST + $DELIVERY_COST, 2);
            $r['profit']           = round($r['base_price'] - $r['total_cost'], 2);
            $r['margin']           = $r['base_price'] > 0
                ? round(($r['profit'] / $r['base_price']) * 100, 1) : 0;
            $r['ingredient_count'] = (int)$r['ingredient_count'];
        }

        response(true, [
            'baskets' => $rows,
            'assumptions' => [
                'packaging_cost' => $PACKAGING_COST,
                'delivery_cost'  => $DELIVERY_COST,
            ],
        ]);
    }

    // ── Payments list ────────────────────────────────────────────
    if ($view === 'payments') {
        $payStatus = $_GET['status'] ?? null;
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $where = ["p.created_at::date BETWEEN ? AND ?"];
        $params = [$dateFrom, $dateTo];

        if ($payStatus) {
            $where[] = "p.status = ?";
            $params[] = $payStatus;
        }

        $whereSql = implode(' AND ', $where);
        $countParams = $params;
        $stmt = $db->prepare("SELECT COUNT(*) FROM om_cesta_payments p WHERE {$whereSql}");
        $stmt->execute($countParams);
        $total = (int)$stmt->fetchColumn();

        $fetchParams = $params;
        $fetchParams[] = $limit;
        $fetchParams[] = $offset;

        $stmt = $db->prepare("
            SELECT p.*, c.name AS customer_name, c.email AS customer_email,
                   b.name AS box_name
            FROM om_cesta_payments p
            LEFT JOIN om_customers c ON p.customer_id = c.customer_id
            LEFT JOIN om_box_subscriptions s ON p.subscription_id = s.id
            LEFT JOIN om_subscription_boxes b ON s.box_id = b.id
            WHERE {$whereSql}
            ORDER BY p.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($fetchParams);
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($payments as &$p) {
            $p['amount'] = (float)$p['amount'];
        }

        response(true, [
            'payments' => $payments,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => max(1, ceil($total / $limit)),
            ],
        ]);
    }

    // ── Churn analysis ───────────────────────────────────────────
    if ($view === 'churn') {
        // Cancellations over time
        $stmt = $db->prepare("
            SELECT
                DATE_TRUNC('week', cancelled_at)::date AS week_start,
                COUNT(*) AS cancellations
            FROM om_box_subscriptions
            WHERE cancelled_at IS NOT NULL
              AND cancelled_at::date BETWEEN ? AND ?
            GROUP BY week_start
            ORDER BY week_start ASC
        ");
        $stmt->execute([$dateFrom, $dateTo]);
        $cancellations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // New subscriptions over time
        $stmt = $db->prepare("
            SELECT
                DATE_TRUNC('week', created_at)::date AS week_start,
                COUNT(*) AS new_subscriptions
            FROM om_box_subscriptions
            WHERE created_at::date BETWEEN ? AND ?
            GROUP BY week_start
            ORDER BY week_start ASC
        ");
        $stmt->execute([$dateFrom, $dateTo]);
        $newSubs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Current totals
        $stmt = $db->query("
            SELECT
                COUNT(*) AS total,
                COUNT(*) FILTER (WHERE status = 'active') AS active,
                COUNT(*) FILTER (WHERE status = 'paused') AS paused,
                COUNT(*) FILTER (WHERE status = 'cancelled') AS cancelled
            FROM om_box_subscriptions
        ");
        $totals = $stmt->fetch(PDO::FETCH_ASSOC);

        $churnRate = 0;
        $totalSubs = (int)$totals['total'];
        $cancelledSubs = (int)$totals['cancelled'];
        if ($totalSubs > 0) {
            $churnRate = round(($cancelledSubs / $totalSubs) * 100, 1);
        }

        // Cancellation reasons (from pause_reason field on cancelled ones)
        $stmt = $db->query("
            SELECT
                COALESCE(pause_reason, 'Nao informado') AS reason,
                COUNT(*) AS count
            FROM om_box_subscriptions
            WHERE status = 'cancelled'
            GROUP BY pause_reason
            ORDER BY count DESC
            LIMIT 10
        ");
        $reasons = $stmt->fetchAll(PDO::FETCH_ASSOC);

        response(true, [
            'churn_rate' => $churnRate,
            'totals' => $totals,
            'cancellations_over_time' => $cancellations,
            'new_subscriptions_over_time' => $newSubs,
            'cancellation_reasons' => $reasons,
            'period' => ['from' => $dateFrom, 'to' => $dateTo],
        ]);
    }

    response(false, null, 'View invalida. Use: overview, by_type, payments, churn, profitability', 400);
} catch (Exception $e) {
    error_log('[cestas/financeiro] ' . $e->getMessage());
    response(false, null, 'Erro interno', 500);
}
