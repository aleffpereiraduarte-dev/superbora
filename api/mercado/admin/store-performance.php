<?php
/**
 * Store Performance Dashboard API
 *
 * GET: Returns detailed performance metrics per store (ranking or single store)
 *
 * Query params:
 *   partner_id: single store detail (optional)
 *   period: today|week|month|quarter (default: month)
 *   category: filter by store category (optional)
 *   min_orders: minimum orders to include in ranking (default: 1)
 *   sort: revenue|rating|health_score|orders|cancel_rate|prep_time (default: revenue)
 *   direction: asc|desc (default: desc)
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();

    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        handleGet($db);
    } else {
        response(false, null, 'Metodo nao permitido', 405);
    }
} catch (PDOException $e) {
    error_log("[Store Performance] DB error: " . $e->getMessage());
    response(false, null, 'Erro interno', 500);
} catch (Exception $e) {
    if (in_array(http_response_code(), [401, 403], true)) {
        exit;
    }
    error_log("[Store Performance] Error: " . $e->getMessage());
    response(false, null, 'Erro interno', 500);
}

/* ===================== GET ===================== */

function handleGet(PDO $db): void {
    $partnerId = !empty($_GET['partner_id']) ? (int)$_GET['partner_id'] : null;
    $period = $_GET['period'] ?? 'month';
    $category = $_GET['category'] ?? '';
    $minOrders = max(1, (int)($_GET['min_orders'] ?? 1));
    $sortBy = $_GET['sort'] ?? 'revenue';
    $direction = strtolower($_GET['direction'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

    // Build date conditions for current and previous period
    $current = buildPeriodCondition($period, 'current');
    $previous = buildPeriodCondition($period, 'previous');

    // Get store ranking
    $ranking = getStoreRanking($db, $current, $previous, $partnerId, $category, $minOrders, $sortBy, $direction);

    // Get overall summary
    $overall = getOverallSummary($db, $current);

    response(true, [
        'ranking' => $ranking,
        'overall' => $overall,
        'period' => $period,
    ]);
}

/* ===================== Helpers ===================== */

function buildPeriodCondition(string $period, string $which): array {
    // $which: 'current' or 'previous'
    switch ($period) {
        case 'today':
            if ($which === 'current') {
                return [
                    'where' => "DATE(o.date_added) = CURRENT_DATE",
                    'params' => [],
                ];
            }
            return [
                'where' => "DATE(o.date_added) = CURRENT_DATE - INTERVAL '1 day'",
                'params' => [],
            ];

        case 'week':
            if ($which === 'current') {
                return [
                    'where' => "o.date_added >= (CURRENT_DATE - INTERVAL '7 days')",
                    'params' => [],
                ];
            }
            return [
                'where' => "o.date_added >= (CURRENT_DATE - INTERVAL '14 days') AND o.date_added < (CURRENT_DATE - INTERVAL '7 days')",
                'params' => [],
            ];

        case 'quarter':
            if ($which === 'current') {
                return [
                    'where' => "o.date_added >= (CURRENT_DATE - INTERVAL '90 days')",
                    'params' => [],
                ];
            }
            return [
                'where' => "o.date_added >= (CURRENT_DATE - INTERVAL '180 days') AND o.date_added < (CURRENT_DATE - INTERVAL '90 days')",
                'params' => [],
            ];

        case 'month':
        default:
            if ($which === 'current') {
                return [
                    'where' => "o.date_added >= (CURRENT_DATE - INTERVAL '30 days')",
                    'params' => [],
                ];
            }
            return [
                'where' => "o.date_added >= (CURRENT_DATE - INTERVAL '60 days') AND o.date_added < (CURRENT_DATE - INTERVAL '30 days')",
                'params' => [],
            ];
    }
}

function getStoreRanking(
    PDO $db,
    array $current,
    array $previous,
    ?int $partnerId,
    string $category,
    int $minOrders,
    string $sortBy,
    string $direction
): array {
    // Validate sort column to prevent SQL injection
    $allowedSorts = [
        'revenue' => 'revenue',
        'rating' => 'avg_rating',
        'health_score' => 'health_score',
        'orders' => 'total_orders',
        'cancel_rate' => 'cancel_rate',
        'prep_time' => 'avg_prep_time_minutes',
    ];
    $sortColumn = $allowedSorts[$sortBy] ?? 'revenue';

    $partnerWhere = '';
    $partnerParams = [];
    if ($partnerId) {
        $partnerWhere = 'AND p.partner_id = ?';
        $partnerParams[] = $partnerId;
    }

    $categoryWhere = '';
    $categoryParams = [];
    if ($category && $category !== 'all') {
        $categoryWhere = 'AND p.categoria = ?';
        $categoryParams[] = $category;
    }

    $allParams = array_merge($current['params'], $partnerParams, $categoryParams);

    // Current period metrics per store
    $sql = "
        WITH store_metrics AS (
            SELECT
                p.partner_id,
                p.name,
                p.trade_name,
                p.logo,
                p.categoria AS category,
                COUNT(o.order_id) AS total_orders,
                SUM(CASE WHEN o.status = 'entregue' THEN 1 ELSE 0 END) AS completed_orders,
                SUM(CASE WHEN o.status = 'cancelado' THEN 1 ELSE 0 END) AS cancelled_orders,
                CASE WHEN COUNT(o.order_id) > 0
                    THEN ROUND(SUM(CASE WHEN o.status = 'cancelado' THEN 1 ELSE 0 END)::numeric / COUNT(o.order_id) * 100, 1)
                    ELSE 0 END AS cancel_rate,
                COALESCE(
                    ROUND(AVG(
                        CASE WHEN o.status IN ('pronto', 'em_entrega', 'entregue') AND o.updated_at IS NOT NULL AND o.date_added IS NOT NULL
                            THEN EXTRACT(EPOCH FROM (o.updated_at - o.date_added)) / 60.0
                            ELSE NULL END
                    )::numeric, 0),
                0) AS avg_prep_time_minutes,
                COALESCE(SUM(CASE WHEN o.status NOT IN ('cancelado', 'reembolsado') THEN o.total ELSE 0 END), 0) AS revenue,
                CASE WHEN SUM(CASE WHEN o.status NOT IN ('cancelado', 'reembolsado') THEN 1 ELSE 0 END) > 0
                    THEN ROUND(
                        SUM(CASE WHEN o.status NOT IN ('cancelado', 'reembolsado') THEN o.total ELSE 0 END)::numeric
                        / SUM(CASE WHEN o.status NOT IN ('cancelado', 'reembolsado') THEN 1 ELSE 0 END),
                    2)
                    ELSE 0 END AS avg_ticket,
                COUNT(DISTINCT o.customer_id) AS unique_customers
            FROM om_market_partners p
            LEFT JOIN om_market_orders o ON o.partner_id = p.partner_id AND {$current['where']}
            WHERE p.status = '1'
            {$partnerWhere}
            {$categoryWhere}
            GROUP BY p.partner_id, p.name, p.trade_name, p.logo, p.categoria
            HAVING COUNT(o.order_id) >= ?
        ),
        store_ratings AS (
            SELECT
                r.partner_id,
                COALESCE(ROUND(AVG(r.rating)::numeric, 1), 0) AS avg_rating,
                COUNT(r.id) AS total_reviews
            FROM om_market_reviews r
            GROUP BY r.partner_id
        ),
        store_penalties AS (
            SELECT
                sp.partner_id,
                COUNT(sp.id) AS complaints,
                COALESCE(SUM(sp.penalty_amount), 0) AS penalties_amount
            FROM om_store_penalties sp
            WHERE sp.created_at >= (CURRENT_DATE - INTERVAL '30 days')
            GROUP BY sp.partner_id
        ),
        repeat_customers AS (
            SELECT
                o.partner_id,
                COUNT(DISTINCT CASE WHEN cust_orders.cnt >= 2 THEN o.customer_id END) AS repeat_count,
                COUNT(DISTINCT o.customer_id) AS total_unique
            FROM om_market_orders o
            INNER JOIN (
                SELECT customer_id, partner_id, COUNT(*) AS cnt
                FROM om_market_orders o
                WHERE {$current['where']}
                AND o.status NOT IN ('cancelado', 'reembolsado')
                GROUP BY customer_id, partner_id
            ) cust_orders ON cust_orders.customer_id = o.customer_id AND cust_orders.partner_id = o.partner_id
            WHERE {$current['where']}
            GROUP BY o.partner_id
        )
        SELECT
            sm.*,
            COALESCE(sr.avg_rating, p_rating.rating, 0) AS avg_rating,
            COALESCE(sr.total_reviews, 0) AS total_reviews,
            COALESCE(spen.complaints, 0) AS complaints,
            COALESCE(spen.penalties_amount, 0) AS penalties_amount,
            CASE WHEN rc.total_unique > 0
                THEN ROUND(rc.repeat_count::numeric / rc.total_unique * 100, 1)
                ELSE 0 END AS repeat_customer_rate,
            -- Health score: 100 - (cancel_rate * 5) - (complaints * 2) + (rating * 3), clamped 0-100
            GREATEST(0, LEAST(100,
                ROUND((
                    100
                    - (sm.cancel_rate * 5)
                    - (COALESCE(spen.complaints, 0) * 2)
                    + (COALESCE(sr.avg_rating, p_rating.rating, 0) * 3)
                )::numeric, 0)
            )) AS health_score
        FROM store_metrics sm
        LEFT JOIN store_ratings sr ON sr.partner_id = sm.partner_id
        LEFT JOIN store_penalties spen ON spen.partner_id = sm.partner_id
        LEFT JOIN repeat_customers rc ON rc.partner_id = sm.partner_id
        LEFT JOIN om_market_partners p_rating ON p_rating.partner_id = sm.partner_id
        ORDER BY {$sortColumn} {$direction}
        LIMIT 100
    ";

    // Merge params: current (for main query) + partner + category + minOrders + current (for repeat subquery) + current (for repeat subquery outer)
    $execParams = array_merge(
        $allParams,
        [$minOrders],
        $current['params'],
        $current['params']
    );

    $stmt = $db->prepare($sql);
    $stmt->execute($execParams);
    $stores = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get previous period data for trends
    $prevData = getPreviousPeriodData($db, $previous, $partnerId, $category);

    // Format results
    $ranking = [];
    foreach ($stores as $store) {
        $pid = (int)$store['partner_id'];
        $prev = $prevData[$pid] ?? null;

        $currentOrders = (int)$store['total_orders'];
        $currentRating = round((float)$store['avg_rating'], 1);
        $currentRevenue = round((float)$store['revenue'], 2);

        // Trend calculations
        $ordersChange = 0;
        $ratingChange = 0;
        $revenueChange = 0;
        if ($prev) {
            if ($prev['total_orders'] > 0) {
                $ordersChange = round(($currentOrders - $prev['total_orders']) / $prev['total_orders'] * 100, 1);
            }
            $ratingChange = round($currentRating - $prev['avg_rating'], 1);
            if ($prev['revenue'] > 0) {
                $revenueChange = round(($currentRevenue - $prev['revenue']) / $prev['revenue'] * 100, 1);
            }
        }

        $avgDeliveryTime = 0;
        $prepTime = (int)$store['avg_prep_time_minutes'];
        // Estimate delivery as prep + 15min average transit
        $avgDeliveryTime = $prepTime > 0 ? $prepTime + 15 : 0;

        $ranking[] = [
            'partner_id' => $pid,
            'name' => $store['trade_name'] ?: $store['name'],
            'logo' => $store['logo'] ?? null,
            'category' => $store['category'] ?? 'mercado',
            'metrics' => [
                'total_orders' => $currentOrders,
                'completed_orders' => (int)$store['completed_orders'],
                'cancelled_orders' => (int)$store['cancelled_orders'],
                'cancel_rate' => round((float)$store['cancel_rate'], 1),
                'avg_prep_time_minutes' => $prepTime,
                'avg_delivery_time_minutes' => $avgDeliveryTime,
                'avg_rating' => $currentRating,
                'total_reviews' => (int)$store['total_reviews'],
                'revenue' => $currentRevenue,
                'avg_ticket' => round((float)$store['avg_ticket'], 2),
                'complaints' => (int)$store['complaints'],
                'penalties_amount' => round((float)$store['penalties_amount'], 2),
                'health_score' => (int)$store['health_score'],
                'repeat_customer_rate' => round((float)$store['repeat_customer_rate'], 1),
                'unique_customers' => (int)$store['unique_customers'],
            ],
            'trend' => [
                'orders_change' => $ordersChange,
                'rating_change' => $ratingChange,
                'revenue_change' => $revenueChange,
            ],
        ];
    }

    return $ranking;
}

function getPreviousPeriodData(PDO $db, array $previous, ?int $partnerId, string $category): array {
    $partnerWhere = '';
    $params = $previous['params'];
    if ($partnerId) {
        $partnerWhere = 'AND o.partner_id = ?';
        $params[] = $partnerId;
    }

    $categoryWhere = '';
    if ($category && $category !== 'all') {
        $categoryWhere = 'AND p.categoria = ?';
        $params[] = $category;
    }

    $sql = "
        SELECT
            o.partner_id,
            COUNT(o.order_id) AS total_orders,
            COALESCE(SUM(CASE WHEN o.status NOT IN ('cancelado', 'reembolsado') THEN o.total ELSE 0 END), 0) AS revenue
        FROM om_market_orders o
        INNER JOIN om_market_partners p ON p.partner_id = o.partner_id
        WHERE {$previous['where']}
        {$partnerWhere}
        {$categoryWhere}
        GROUP BY o.partner_id
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    // Also get previous ratings (these are cumulative, so same as current)
    $result = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pid = (int)$row['partner_id'];
        $result[$pid] = [
            'total_orders' => (int)$row['total_orders'],
            'revenue' => round((float)$row['revenue'], 2),
            'avg_rating' => 0, // Ratings are cumulative, use current
        ];
    }

    // Get current ratings for comparison baseline
    $ratingStmt = $db->query("
        SELECT partner_id, COALESCE(ROUND(AVG(rating)::numeric, 1), 0) AS avg_rating
        FROM om_market_reviews
        GROUP BY partner_id
    ");
    foreach ($ratingStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $pid = (int)$r['partner_id'];
        if (isset($result[$pid])) {
            $result[$pid]['avg_rating'] = round((float)$r['avg_rating'], 1);
        }
    }

    return $result;
}

function getOverallSummary(PDO $db, array $current): array {
    $params = $current['params'];

    // Total active stores
    $stmt = $db->query("SELECT COUNT(*) AS total FROM om_market_partners WHERE status = '1'");
    $totalStores = (int)$stmt->fetch()['total'];

    // Avg prep time across all stores for the period
    $sql = "
        SELECT
            COALESCE(ROUND(AVG(
                CASE WHEN o.status IN ('pronto', 'em_entrega', 'entregue') AND o.updated_at IS NOT NULL
                    THEN EXTRACT(EPOCH FROM (o.updated_at - o.date_added)) / 60.0
                    ELSE NULL END
            )::numeric, 0), 0) AS avg_prep_time,
            CASE WHEN COUNT(*) > 0
                THEN ROUND(SUM(CASE WHEN o.status = 'cancelado' THEN 1 ELSE 0 END)::numeric / COUNT(*) * 100, 1)
                ELSE 0 END AS avg_cancel_rate
        FROM om_market_orders o
        WHERE {$current['where']}
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $metrics = $stmt->fetch(PDO::FETCH_ASSOC);

    // Avg rating
    $ratingStmt = $db->query("
        SELECT COALESCE(ROUND(AVG(r.rating)::numeric, 1), 0) AS avg_rating
        FROM om_market_reviews r
        INNER JOIN om_market_partners p ON p.partner_id = r.partner_id AND p.status = '1'
    ");
    $avgRating = round((float)$ratingStmt->fetch()['avg_rating'], 1);

    // Best and worst store by health score (from current period, min 3 orders)
    $sql2 = "
        WITH store_scores AS (
            SELECT
                p.partner_id,
                COALESCE(p.trade_name, p.name) AS name,
                COUNT(o.order_id) AS total_orders,
                CASE WHEN COUNT(o.order_id) > 0
                    THEN ROUND(SUM(CASE WHEN o.status = 'cancelado' THEN 1 ELSE 0 END)::numeric / COUNT(o.order_id) * 100, 1)
                    ELSE 0 END AS cancel_rate,
                COALESCE(p.rating, 0) AS rating
            FROM om_market_partners p
            LEFT JOIN om_market_orders o ON o.partner_id = p.partner_id AND {$current['where']}
            WHERE p.status = '1'
            GROUP BY p.partner_id, p.name, p.trade_name, p.rating
            HAVING COUNT(o.order_id) >= 3
        )
        SELECT name, cancel_rate, rating,
               GREATEST(0, LEAST(100, ROUND((100 - (cancel_rate * 5) + (rating * 3))::numeric, 0))) AS health_score
        FROM store_scores
        ORDER BY health_score DESC
    ";
    $stmt2 = $db->prepare($sql2);
    $stmt2->execute($current['params']);
    $allScores = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    $bestStore = !empty($allScores) ? $allScores[0]['name'] : '-';
    $worstStore = count($allScores) > 1 ? $allScores[count($allScores) - 1]['name'] : '-';

    // Categories list for filter
    $catStmt = $db->query("SELECT DISTINCT categoria FROM om_market_partners WHERE status = '1' AND categoria IS NOT NULL ORDER BY categoria");
    $categories = array_column($catStmt->fetchAll(PDO::FETCH_ASSOC), 'categoria');

    return [
        'total_stores' => $totalStores,
        'avg_prep_time' => (int)$metrics['avg_prep_time'],
        'avg_rating' => $avgRating,
        'avg_cancel_rate' => round((float)$metrics['avg_cancel_rate'], 1),
        'best_store' => $bestStore,
        'worst_store' => $worstStore,
        'categories' => $categories,
    ];
}
