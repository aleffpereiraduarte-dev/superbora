<?php
/**
 * GET /api/mercado/admin/order-heatmap.php
 *
 * Order heatmap data for geographic visualization.
 *
 * Query params:
 *   period=today|week|month   Time period (default: today)
 *   city=X                    Filter by shipping_city
 *
 * Returns:
 *   heatmap[]     { lat, lng, weight, count } — grouped by ~100m precision
 *   hot_zones[]   Top 10 areas by order count with neighborhood
 *   cold_zones[]  Areas with stores but few orders (opportunity zones)
 *   peak_hours[]  By zone, which hours have most orders
 *   summary       Total orders, unique locations, avg per zone
 */
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../config/cache.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        response(false, null, "Metodo nao permitido", 405);
    }

    $period = $_GET['period'] ?? 'today';
    $city = isset($_GET['city']) ? trim($_GET['city']) : null;

    // Validate period
    if (!in_array($period, ['today', 'week', 'month'], true)) {
        $period = 'today';
    }

    // Build cache key
    $cache_key = "order_heatmap_{$period}" . ($city ? "_" . md5($city) : "");

    // Try cache (10 minutes)
    $cached = om_cache()->get($cache_key);
    if ($cached !== null) {
        response(true, $cached, "Heatmap (cache)");
    }

    // ── Date filter ──
    $date_filter = match($period) {
        'today' => "o.created_at >= CURRENT_DATE",
        'week'  => "o.created_at >= CURRENT_DATE - INTERVAL '7 days'",
        'month' => "o.created_at >= CURRENT_DATE - INTERVAL '30 days'",
    };

    // ── Base WHERE conditions ──
    $where = [$date_filter, "o.shipping_lat IS NOT NULL", "o.shipping_lng IS NOT NULL"];
    $params = [];

    if ($city) {
        $where[] = "o.shipping_city ILIKE ?";
        $escaped_city = str_replace(['%', '_'], ['\\%', '\\_'], $city);
        $params[] = "%" . $escaped_city . "%";
    }

    $where_sql = implode(' AND ', $where);

    // ================================================================
    // Heatmap points — group by lat/lng rounded to 3 decimal places (~100m)
    // ================================================================
    $stmt = $db->prepare("
        SELECT
            ROUND(o.shipping_lat::numeric, 3) as lat,
            ROUND(o.shipping_lng::numeric, 3) as lng,
            COUNT(*) as count,
            COUNT(*)::float as weight,
            COALESCE(o.shipping_city, '') as city
        FROM om_market_orders o
        WHERE {$where_sql}
        GROUP BY ROUND(o.shipping_lat::numeric, 3), ROUND(o.shipping_lng::numeric, 3), o.shipping_city
        ORDER BY count DESC
    ");
    $stmt->execute($params);
    $raw_points = $stmt->fetchAll();

    // Normalize weights (0-1 scale based on max count)
    $max_count = 0;
    foreach ($raw_points as $p) {
        if ((int)$p['count'] > $max_count) $max_count = (int)$p['count'];
    }

    $heatmap = [];
    foreach ($raw_points as $p) {
        $heatmap[] = [
            'lat' => (float)$p['lat'],
            'lng' => (float)$p['lng'],
            'count' => (int)$p['count'],
            'weight' => $max_count > 0 ? round((int)$p['count'] / $max_count, 3) : 0,
        ];
    }

    // ================================================================
    // Hot zones — top 10 areas by order count with neighborhood info
    // ================================================================
    $stmt = $db->prepare("
        SELECT
            ROUND(o.shipping_lat::numeric, 3) as lat,
            ROUND(o.shipping_lng::numeric, 3) as lng,
            COUNT(*) as order_count,
            COALESCE(o.shipping_city, 'Desconhecida') as city,
            COALESCE(
                NULLIF(
                    regexp_replace(
                        split_part(o.delivery_address, ',', 2),
                        '^\s*\d+\s*', ''
                    ),
                    ''
                ),
                split_part(o.delivery_address, ',', 1)
            ) as neighborhood_hint,
            ROUND(AVG(o.total)::numeric, 2) as avg_ticket,
            ROUND(AVG(o.delivery_fee)::numeric, 2) as avg_delivery_fee
        FROM om_market_orders o
        WHERE {$where_sql}
        GROUP BY ROUND(o.shipping_lat::numeric, 3), ROUND(o.shipping_lng::numeric, 3), o.shipping_city, neighborhood_hint
        ORDER BY order_count DESC
        LIMIT 10
    ");
    $stmt->execute($params);
    $hot_zones = $stmt->fetchAll();

    foreach ($hot_zones as &$hz) {
        $hz['lat'] = (float)$hz['lat'];
        $hz['lng'] = (float)$hz['lng'];
        $hz['order_count'] = (int)$hz['order_count'];
        $hz['avg_ticket'] = (float)$hz['avg_ticket'];
        $hz['avg_delivery_fee'] = (float)$hz['avg_delivery_fee'];
        // Clean neighborhood hint
        $hz['neighborhood'] = trim($hz['neighborhood_hint'] ?? '');
        unset($hz['neighborhood_hint']);
    }

    // ================================================================
    // Cold zones — areas with stores but few orders (opportunity zones)
    // ================================================================
    $cold_where = ["p.lat IS NOT NULL", "p.lng IS NOT NULL", "p.status = '1'"];
    $cold_params = [];
    if ($city) {
        $cold_where[] = "p.city ILIKE ?";
        $cold_params[] = "%" . $escaped_city . "%";
    }
    $cold_where_sql = implode(' AND ', $cold_where);

    // Find store locations with low order counts
    $cold_sql = "
        SELECT
            ROUND(p.lat::numeric, 3) as lat,
            ROUND(p.lng::numeric, 3) as lng,
            COUNT(DISTINCT p.partner_id) as store_count,
            STRING_AGG(DISTINCT p.name, ', ' ORDER BY p.name) as store_names,
            COALESCE(p.city, '') as city,
            COALESCE(SUM(order_counts.total_orders), 0) as order_count
        FROM om_market_partners p
        LEFT JOIN LATERAL (
            SELECT COUNT(*) as total_orders
            FROM om_market_orders o
            WHERE o.partner_id = p.partner_id
              AND {$date_filter}
              AND o.status NOT IN ('cancelado', 'reembolsado')
        ) order_counts ON true
        WHERE {$cold_where_sql}
        GROUP BY ROUND(p.lat::numeric, 3), ROUND(p.lng::numeric, 3), p.city
        HAVING COALESCE(SUM(order_counts.total_orders), 0) < 5
        ORDER BY store_count DESC, order_count ASC
        LIMIT 10
    ";
    $stmt = $db->prepare($cold_sql);
    $stmt->execute($cold_params);
    $cold_zones = $stmt->fetchAll();

    foreach ($cold_zones as &$cz) {
        $cz['lat'] = (float)$cz['lat'];
        $cz['lng'] = (float)$cz['lng'];
        $cz['store_count'] = (int)$cz['store_count'];
        $cz['order_count'] = (int)$cz['order_count'];
        // Truncate long store name lists
        if (strlen($cz['store_names']) > 200) {
            $cz['store_names'] = substr($cz['store_names'], 0, 197) . '...';
        }
    }

    // ================================================================
    // Peak hours — by zone (top 5 zones), which hours have most orders
    // ================================================================
    $stmt = $db->prepare("
        WITH top_zones AS (
            SELECT
                ROUND(o.shipping_lat::numeric, 3) as lat,
                ROUND(o.shipping_lng::numeric, 3) as lng
            FROM om_market_orders o
            WHERE {$where_sql}
            GROUP BY ROUND(o.shipping_lat::numeric, 3), ROUND(o.shipping_lng::numeric, 3)
            ORDER BY COUNT(*) DESC
            LIMIT 5
        )
        SELECT
            tz.lat,
            tz.lng,
            EXTRACT(HOUR FROM o.created_at)::int as hour,
            COUNT(*) as order_count
        FROM om_market_orders o
        JOIN top_zones tz ON ROUND(o.shipping_lat::numeric, 3) = tz.lat
                         AND ROUND(o.shipping_lng::numeric, 3) = tz.lng
        WHERE {$where_sql}
        GROUP BY tz.lat, tz.lng, hour
        ORDER BY tz.lat, tz.lng, order_count DESC
    ");
    $stmt->execute(array_merge($params, $params)); // params used twice (top_zones + main)
    $peak_rows = $stmt->fetchAll();

    // Group by zone
    $peak_hours = [];
    foreach ($peak_rows as $row) {
        $zone_key = $row['lat'] . ',' . $row['lng'];
        if (!isset($peak_hours[$zone_key])) {
            $peak_hours[$zone_key] = [
                'lat' => (float)$row['lat'],
                'lng' => (float)$row['lng'],
                'hours' => [],
            ];
        }
        $peak_hours[$zone_key]['hours'][] = [
            'hour' => (int)$row['hour'],
            'count' => (int)$row['order_count'],
        ];
    }
    $peak_hours = array_values($peak_hours);

    // ================================================================
    // Summary stats
    // ================================================================
    $stmt = $db->prepare("
        SELECT
            COUNT(*) as total_orders,
            COUNT(DISTINCT CONCAT(ROUND(o.shipping_lat::numeric, 3), ',', ROUND(o.shipping_lng::numeric, 3))) as unique_locations,
            ROUND(AVG(o.total)::numeric, 2) as avg_ticket,
            ROUND(SUM(o.total)::numeric, 2) as total_revenue
        FROM om_market_orders o
        WHERE {$where_sql}
    ");
    $stmt->execute($params);
    $summary_row = $stmt->fetch();

    $total_orders = (int)$summary_row['total_orders'];
    $unique_locations = (int)$summary_row['unique_locations'];

    $summary = [
        'total_orders' => $total_orders,
        'unique_locations' => $unique_locations,
        'avg_orders_per_zone' => $unique_locations > 0 ? round($total_orders / $unique_locations, 1) : 0,
        'avg_ticket' => (float)($summary_row['avg_ticket'] ?? 0),
        'total_revenue' => (float)($summary_row['total_revenue'] ?? 0),
        'period' => $period,
        'city_filter' => $city,
    ];

    // ── Available cities for filter ──
    $cities_stmt = $db->query("
        SELECT DISTINCT shipping_city as city, COUNT(*) as count
        FROM om_market_orders
        WHERE shipping_city IS NOT NULL AND shipping_city != ''
          AND created_at >= CURRENT_DATE - INTERVAL '30 days'
        GROUP BY shipping_city
        ORDER BY count DESC
        LIMIT 50
    ");
    $available_cities = $cities_stmt->fetchAll();

    $result = [
        'heatmap' => $heatmap,
        'hot_zones' => $hot_zones,
        'cold_zones' => $cold_zones,
        'peak_hours' => $peak_hours,
        'summary' => $summary,
        'available_cities' => $available_cities,
    ];

    // Cache for 10 minutes
    om_cache()->set($cache_key, $result, 600);

    response(true, $result, "Heatmap de pedidos");

} catch (Exception $e) {
    error_log("[admin/order-heatmap] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
