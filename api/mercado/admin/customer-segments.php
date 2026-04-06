<?php
/**
 * /api/mercado/admin/customer-segments.php
 *
 * Advanced customer segmentation with revenue metrics.
 *
 * GET (no segment param): Returns all segment summaries with counts, revenue, avg order value, top 5 customers.
 *   Cached in Redis for 10 minutes.
 *
 * GET (?segment=champions&page=1&limit=20): Returns paginated customer list for a specific segment.
 *
 * Segments:
 *   champions  — 10+ orders in last 90 days, avg rating given >= 4
 *   loyal      — 5-9 orders in last 90 days
 *   potential  — 2-4 orders in last 90 days
 *   new        — First order in last 30 days
 *   at_risk    — 3+ orders total but none in last 30 days
 *   lost       — Had orders but none in last 90 days
 *   high_value — Average order > R$80
 *   recurring  — Orders on 3+ different weeks in last month
 */
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../config/cache.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    response(false, null, "Metodo nao permitido", 405);
}

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();

    $segment = trim($_GET['segment'] ?? '');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    // Whitelist valid segment keys
    $validSegments = ['champions', 'loyal', 'potential', 'new', 'at_risk', 'lost', 'high_value', 'recurring'];

    // ── Segment CTE definitions ──
    // Each returns customer_id rows that belong to that segment.
    // We use a base CTE that pre-computes per-customer order stats to avoid redundant scans.

    $baseCTE = "
        customer_stats AS (
            SELECT
                o.customer_id,
                COUNT(*) AS total_orders,
                COUNT(*) FILTER (WHERE o.created_at >= CURRENT_DATE - INTERVAL '90 days') AS orders_90d,
                COUNT(*) FILTER (WHERE o.created_at >= CURRENT_DATE - INTERVAL '30 days') AS orders_30d,
                COALESCE(SUM(o.total) FILTER (WHERE o.status NOT IN ('cancelado','reembolsado')), 0) AS total_revenue,
                COALESCE(AVG(o.total) FILTER (WHERE o.status NOT IN ('cancelado','reembolsado')), 0) AS avg_order_value,
                MAX(o.created_at) AS last_order_date,
                MIN(o.created_at) AS first_order_date,
                COUNT(DISTINCT DATE_TRUNC('week', o.created_at)) FILTER (WHERE o.created_at >= CURRENT_DATE - INTERVAL '30 days') AS distinct_weeks_30d
            FROM om_market_orders o
            WHERE o.status NOT IN ('cancelado', 'reembolsado')
            GROUP BY o.customer_id
        ),
        customer_ratings AS (
            SELECT
                r.rater_id AS customer_id,
                AVG(r.rating) AS avg_rating_given
            FROM om_market_ratings r
            WHERE r.rater_type = 'customer' AND r.rating > 0
            GROUP BY r.rater_id
        )
    ";

    // Segment filter conditions (applied to customer_stats joined with customer_ratings)
    $segmentConditions = [
        'champions'  => "cs.orders_90d >= 10 AND COALESCE(cr.avg_rating_given, 5) >= 4",
        'loyal'      => "cs.orders_90d BETWEEN 5 AND 9",
        'potential'  => "cs.orders_90d BETWEEN 2 AND 4",
        'new'        => "cs.first_order_date >= CURRENT_DATE - INTERVAL '30 days' AND cs.total_orders = 1",
        'at_risk'    => "cs.total_orders >= 3 AND cs.orders_30d = 0 AND cs.last_order_date >= CURRENT_DATE - INTERVAL '90 days'",
        'lost'       => "cs.total_orders >= 1 AND cs.last_order_date < CURRENT_DATE - INTERVAL '90 days'",
        'high_value' => "cs.avg_order_value > 80 AND cs.total_orders >= 2",
        'recurring'  => "cs.distinct_weeks_30d >= 3",
    ];

    $segmentLabels = [
        'champions'  => ['label' => 'Campeoes', 'description' => '10+ pedidos em 90 dias, avaliacao media >= 4', 'icon' => 'trophy', 'color' => '#FFD700'],
        'loyal'      => ['label' => 'Leais', 'description' => '5-9 pedidos nos ultimos 90 dias', 'icon' => 'heart', 'color' => '#E91E63'],
        'potential'  => ['label' => 'Potenciais', 'description' => '2-4 pedidos nos ultimos 90 dias', 'icon' => 'trending-up', 'color' => '#4CAF50'],
        'new'        => ['label' => 'Novos', 'description' => 'Primeiro pedido nos ultimos 30 dias', 'icon' => 'user-plus', 'color' => '#2196F3'],
        'at_risk'    => ['label' => 'Em Risco', 'description' => '3+ pedidos total, nenhum nos ultimos 30 dias', 'icon' => 'alert-triangle', 'color' => '#FF9800'],
        'lost'       => ['label' => 'Perdidos', 'description' => 'Sem pedidos nos ultimos 90 dias', 'icon' => 'user-x', 'color' => '#F44336'],
        'high_value' => ['label' => 'Alto Valor', 'description' => 'Ticket medio > R$80', 'icon' => 'dollar-sign', 'color' => '#9C27B0'],
        'recurring'  => ['label' => 'Recorrentes', 'description' => 'Pedidos em 3+ semanas diferentes no ultimo mes', 'icon' => 'repeat', 'color' => '#00BCD4'],
    ];

    // ══════════════════════════════════════════════════════════════
    // If a specific segment is requested, return paginated customers
    // ══════════════════════════════════════════════════════════════
    if ($segment !== '') {
        if (!in_array($segment, $validSegments, true)) {
            response(false, null, "Segmento invalido. Validos: " . implode(', ', $validSegments), 400);
        }

        $condition = $segmentConditions[$segment];

        // Count total
        $countSQL = "
            WITH {$baseCTE}
            SELECT COUNT(*) AS total
            FROM customer_stats cs
            LEFT JOIN customer_ratings cr ON cr.customer_id = cs.customer_id
            WHERE {$condition}
        ";
        $stmt = dbQuery($db, $countSQL);
        $total = (int)$stmt->fetch()['total'];

        // Fetch paginated customers
        $listSQL = "
            WITH {$baseCTE}
            SELECT
                c.customer_id, c.name, c.email, c.phone, c.foto, c.created_at AS registered_at,
                cs.total_orders, cs.orders_90d, cs.orders_30d,
                ROUND(cs.total_revenue::numeric, 2) AS total_revenue,
                ROUND(cs.avg_order_value::numeric, 2) AS avg_order_value,
                cs.last_order_date, cs.first_order_date,
                ROUND(COALESCE(cr.avg_rating_given, 0)::numeric, 2) AS avg_rating_given,
                COALESCE(cb.balance, 0) AS cashback_balance
            FROM customer_stats cs
            JOIN om_customers c ON c.customer_id = cs.customer_id
            LEFT JOIN customer_ratings cr ON cr.customer_id = cs.customer_id
            LEFT JOIN om_cashback_wallet cb ON cb.customer_id = cs.customer_id
            WHERE {$condition}
            ORDER BY cs.total_revenue DESC
            LIMIT ? OFFSET ?
        ";
        $stmt = dbQuery($db, $listSQL, [$limit, $offset]);
        $customers = $stmt->fetchAll();

        // Format numeric fields
        foreach ($customers as &$cust) {
            $cust['total_orders'] = (int)$cust['total_orders'];
            $cust['orders_90d'] = (int)$cust['orders_90d'];
            $cust['orders_30d'] = (int)$cust['orders_30d'];
            $cust['total_revenue'] = round((float)$cust['total_revenue'], 2);
            $cust['avg_order_value'] = round((float)$cust['avg_order_value'], 2);
            $cust['avg_rating_given'] = round((float)$cust['avg_rating_given'], 2);
            $cust['cashback_balance'] = round((float)$cust['cashback_balance'], 2);
        }
        unset($cust);

        $meta = $segmentLabels[$segment];

        response(true, [
            'segment' => $segment,
            'label' => $meta['label'],
            'description' => $meta['description'],
            'customers' => $customers,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int)ceil($total / max($limit, 1)),
            ],
        ], "Clientes do segmento '{$meta['label']}'");
    }

    // ══════════════════════════════════════════════════════════════
    // No segment param: return all segment summaries (cached 10 min)
    // ══════════════════════════════════════════════════════════════
    $data = om_cache()->remember('admin_customer_segments_v2', 600, function () use ($db, $baseCTE, $segmentConditions, $segmentLabels) {

        // Total customers baseline
        $stmt = $db->query("SELECT COUNT(*) AS cnt FROM om_customers");
        $totalCustomers = (int)$stmt->fetch()['cnt'];

        $segments = [];

        foreach ($segmentConditions as $key => $condition) {
            $meta = $segmentLabels[$key];

            // Get count, total_revenue, avg_order_value in one query
            $sql = "
                WITH {$baseCTE}
                SELECT
                    COUNT(*) AS count,
                    COALESCE(SUM(cs.total_revenue), 0) AS total_revenue,
                    COALESCE(AVG(cs.avg_order_value), 0) AS avg_order_value
                FROM customer_stats cs
                LEFT JOIN customer_ratings cr ON cr.customer_id = cs.customer_id
                WHERE {$condition}
            ";
            $stmt = dbQuery($db, $sql);
            $row = $stmt->fetch();

            // Get top 5 example customers by revenue
            $topSQL = "
                WITH {$baseCTE}
                SELECT
                    c.customer_id, c.name, c.email, c.foto,
                    ROUND(cs.total_revenue::numeric, 2) AS total_revenue,
                    cs.total_orders,
                    ROUND(cs.avg_order_value::numeric, 2) AS avg_order_value,
                    cs.last_order_date
                FROM customer_stats cs
                JOIN om_customers c ON c.customer_id = cs.customer_id
                LEFT JOIN customer_ratings cr ON cr.customer_id = cs.customer_id
                WHERE {$condition}
                ORDER BY cs.total_revenue DESC
                LIMIT 5
            ";
            $stmtTop = dbQuery($db, $topSQL);
            $topCustomers = $stmtTop->fetchAll();

            foreach ($topCustomers as &$tc) {
                $tc['total_revenue'] = round((float)$tc['total_revenue'], 2);
                $tc['total_orders'] = (int)$tc['total_orders'];
                $tc['avg_order_value'] = round((float)$tc['avg_order_value'], 2);
            }
            unset($tc);

            $segments[] = [
                'key' => $key,
                'label' => $meta['label'],
                'description' => $meta['description'],
                'icon' => $meta['icon'],
                'color' => $meta['color'],
                'count' => (int)$row['count'],
                'total_revenue' => round((float)$row['total_revenue'], 2),
                'avg_order_value' => round((float)$row['avg_order_value'], 2),
                'example_customers' => $topCustomers,
            ];
        }

        return [
            'total_customers' => $totalCustomers,
            'segments' => $segments,
        ];
    });

    response(true, $data, "Segmentos de clientes carregados");

} catch (Exception $e) {
    error_log("[admin/customer-segments] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
