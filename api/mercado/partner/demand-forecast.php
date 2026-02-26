<?php
/**
 * GET /api/mercado/partner/demand-forecast.php?days=7
 * Demand forecasting for partners — predicts order volume and revenue.
 *
 * Uses weighted moving average (recent weeks weighted 2x) from historical data.
 *
 * Auth: Bearer token (partner type)
 *
 * Query params:
 *   days: number of days to forecast (default 7, max 30)
 *
 * Response:
 * {
 *   forecast: [{date, day_name, predicted_orders, predicted_revenue, confidence}],
 *   peak_hours: [{hour, avg_orders, pct_of_total}],
 *   historical_summary: {avg_daily_orders, avg_daily_revenue, total_orders_8w}
 * }
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    // --- Auth ---
    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Token ausente", 401);

    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== OmAuth::USER_TYPE_PARTNER) {
        response(false, null, "Nao autorizado", 401);
    }
    $partnerId = (int)$payload['uid'];

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        response(false, null, "Metodo nao permitido. Use GET.", 405);
    }

    // Parse forecast days
    $forecastDays = (int)($_GET['days'] ?? 7);
    $forecastDays = max(1, min(30, $forecastDays));

    // ── Step 1: Get order counts by day-of-week for last 8 weeks ──
    $stmt = $db->prepare("
        SELECT EXTRACT(DOW FROM date_added)::int as dow,
               DATE_TRUNC('week', date_added)::date as week_start,
               COUNT(*) as orders,
               SUM(total) as revenue
        FROM om_market_orders
        WHERE partner_id = ? AND date_added >= NOW() - INTERVAL '56 days'
          AND status NOT IN ('cancelado', 'cancelled')
        GROUP BY dow, week_start
        ORDER BY dow, week_start
    ");
    $stmt->execute([$partnerId]);
    $dailyData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Organize by day-of-week (0=Sunday, 6=Saturday)
    $dowData = [];
    for ($d = 0; $d <= 6; $d++) {
        $dowData[$d] = ['orders' => [], 'revenue' => []];
    }

    // Collect all distinct week_starts to determine recency weighting
    $allWeeks = [];
    foreach ($dailyData as $row) {
        $dow = (int)$row['dow'];
        $weekStart = $row['week_start'];
        $dowData[$dow]['orders'][$weekStart] = (int)$row['orders'];
        $dowData[$dow]['revenue'][$weekStart] = (float)$row['revenue'];
        if (!in_array($weekStart, $allWeeks)) {
            $allWeeks[] = $weekStart;
        }
    }
    sort($allWeeks);
    $totalWeeks = count($allWeeks);

    // ── Step 2: Calculate weighted moving average per DOW ──
    // Recent weeks (last 2) weighted 2x, older weeks weighted 1x
    $dowForecast = [];
    $dayNames = ['Domingo', 'Segunda', 'Terca', 'Quarta', 'Quinta', 'Sexta', 'Sabado'];

    for ($dow = 0; $dow <= 6; $dow++) {
        $weightedOrders = 0;
        $weightedRevenue = 0;
        $totalWeight = 0;
        $dataPoints = 0;

        foreach ($allWeeks as $idx => $weekStart) {
            $orders = $dowData[$dow]['orders'][$weekStart] ?? 0;
            $revenue = $dowData[$dow]['revenue'][$weekStart] ?? 0;

            // Recent weeks (top 2) get 2x weight
            $isRecent = ($idx >= $totalWeeks - 2);
            $weight = $isRecent ? 2.0 : 1.0;

            if ($orders > 0) {
                $weightedOrders += $orders * $weight;
                $weightedRevenue += $revenue * $weight;
                $totalWeight += $weight;
                $dataPoints++;
            }
        }

        if ($totalWeight > 0) {
            $avgOrders = $weightedOrders / $totalWeight;
            $avgRevenue = $weightedRevenue / $totalWeight;
        } else {
            $avgOrders = 0;
            $avgRevenue = 0;
        }

        // Confidence based on data availability (more data = higher confidence)
        $confidence = min(0.95, $dataPoints / 8 * 0.95);
        if ($dataPoints === 0) $confidence = 0.1;

        $dowForecast[$dow] = [
            'predicted_orders' => (int)round($avgOrders),
            'predicted_revenue' => round($avgRevenue, 2),
            'confidence' => round($confidence, 2),
            'data_points' => $dataPoints,
        ];
    }

    // ── Step 3: Build forecast for requested days ──
    $forecast = [];
    $today = new DateTime();

    for ($i = 1; $i <= $forecastDays; $i++) {
        $forecastDate = clone $today;
        $forecastDate->modify("+{$i} days");
        $dow = (int)$forecastDate->format('w'); // 0=Sunday

        $forecast[] = [
            'date' => $forecastDate->format('Y-m-d'),
            'day_name' => $dayNames[$dow],
            'day_of_week' => $dow,
            'predicted_orders' => $dowForecast[$dow]['predicted_orders'],
            'predicted_revenue' => $dowForecast[$dow]['predicted_revenue'],
            'confidence' => $dowForecast[$dow]['confidence'],
        ];
    }

    // ── Step 4: Get peak hours (last 4 weeks) ──
    $stmt = $db->prepare("
        SELECT EXTRACT(HOUR FROM date_added)::int as order_hour,
               COUNT(*) as order_count
        FROM om_market_orders
        WHERE partner_id = ?
          AND date_added >= NOW() - INTERVAL '28 days'
          AND status NOT IN ('cancelado', 'cancelled')
        GROUP BY order_hour
        ORDER BY order_count DESC
    ");
    $stmt->execute([$partnerId]);
    $hourlyData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalHourlyOrders = array_sum(array_column($hourlyData, 'order_count'));

    $peakHours = [];
    foreach ($hourlyData as $row) {
        $pct = $totalHourlyOrders > 0
            ? round(((int)$row['order_count'] / $totalHourlyOrders) * 100, 1)
            : 0;

        $peakHours[] = [
            'hour' => (int)$row['order_hour'],
            'hour_label' => str_pad($row['order_hour'], 2, '0', STR_PAD_LEFT) . ':00',
            'avg_orders' => round((int)$row['order_count'] / 4, 1), // avg per week over 4 weeks
            'total_orders' => (int)$row['order_count'],
            'pct_of_total' => $pct,
        ];
    }

    // ── Step 5: Historical summary ──
    $stmt = $db->prepare("
        SELECT COUNT(*) as total_orders,
               COALESCE(SUM(total), 0) as total_revenue,
               COALESCE(AVG(total), 0) as avg_ticket
        FROM om_market_orders
        WHERE partner_id = ?
          AND date_added >= NOW() - INTERVAL '56 days'
          AND status NOT IN ('cancelado', 'cancelled')
    ");
    $stmt->execute([$partnerId]);
    $summary = $stmt->fetch();

    $totalOrders8w = (int)($summary['total_orders'] ?? 0);
    $daysWithData = max(1, min(56, $totalWeeks * 7));

    $historicalSummary = [
        'total_orders_8w' => $totalOrders8w,
        'avg_daily_orders' => round($totalOrders8w / $daysWithData, 1),
        'avg_daily_revenue' => round((float)($summary['total_revenue'] ?? 0) / $daysWithData, 2),
        'avg_ticket' => round((float)($summary['avg_ticket'] ?? 0), 2),
        'weeks_of_data' => $totalWeeks,
    ];

    // ── Cache forecast to om_partner_demand_forecast ──
    try {
        foreach ($forecast as $f) {
            $stmtUpsert = $db->prepare("
                INSERT INTO om_partner_demand_forecast
                    (partner_id, forecast_date, predicted_orders, predicted_revenue, confidence, day_of_week, factors)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT (partner_id, forecast_date)
                DO UPDATE SET predicted_orders = EXCLUDED.predicted_orders,
                              predicted_revenue = EXCLUDED.predicted_revenue,
                              confidence = EXCLUDED.confidence,
                              factors = EXCLUDED.factors,
                              created_at = NOW()
            ");
            $stmtUpsert->execute([
                $partnerId,
                $f['date'],
                $f['predicted_orders'],
                $f['predicted_revenue'],
                $f['confidence'],
                $f['day_of_week'],
                json_encode(['method' => 'weighted_moving_avg', 'weeks' => $totalWeeks]),
            ]);
        }
    } catch (Exception $e) {
        error_log("[partner/demand-forecast] Cache error: " . $e->getMessage());
    }

    response(true, [
        'forecast' => $forecast,
        'peak_hours' => $peakHours,
        'historical_summary' => $historicalSummary,
    ], "Previsao de demanda calculada com sucesso.");

} catch (Exception $e) {
    error_log("[partner/demand-forecast] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
