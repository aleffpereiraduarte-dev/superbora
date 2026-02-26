<?php
/**
 * GET /api/mercado/partner/financial-forecast.php - Previsoes financeiras com IA
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Token ausente", 401);

    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== OmAuth::USER_TYPE_PARTNER) {
        response(false, null, "Nao autorizado", 401);
    }

    $partnerId = $payload['uid'];
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        // Get historical data for predictions
        $stmt = $db->prepare("
            SELECT
                DATE(created_at) as date,
                EXTRACT(DOW FROM DATE(created_at))::int + 1 as day_of_week,
                COUNT(*) as orders,
                SUM(total) as revenue
            FROM om_market_orders
            WHERE partner_id = ? AND status NOT IN ('cancelado','cancelled')
            AND created_at >= NOW() - INTERVAL '90 days'
            GROUP BY DATE(created_at), EXTRACT(DOW FROM DATE(created_at))::int + 1
            ORDER BY date DESC
        ");
        $stmt->execute([$partnerId]);
        $historicalData = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate averages by day of week
        $dayAverages = [];
        foreach ($historicalData as $day) {
            $dow = $day['day_of_week'];
            if (!isset($dayAverages[$dow])) {
                $dayAverages[$dow] = ['orders' => [], 'revenue' => []];
            }
            $dayAverages[$dow]['orders'][] = $day['orders'];
            $dayAverages[$dow]['revenue'][] = $day['revenue'];
        }

        foreach ($dayAverages as $dow => &$data) {
            $data['avg_orders'] = count($data['orders']) > 0 ? round(array_sum($data['orders']) / count($data['orders']), 1) : 0;
            $data['avg_revenue'] = count($data['revenue']) > 0 ? round(array_sum($data['revenue']) / count($data['revenue']), 2) : 0;
        }

        // Generate 7-day forecast
        $forecast = [];
        for ($i = 1; $i <= 7; $i++) {
            $date = date('Y-m-d', strtotime("+$i days"));
            $dow = date('w', strtotime($date)) + 1; // PHP: 0=Sunday, MySQL: 1=Sunday

            $avgOrders = $dayAverages[$dow]['avg_orders'] ?? 0;
            $avgRevenue = $dayAverages[$dow]['avg_revenue'] ?? 0;

            // Apply trend adjustment (simple linear)
            $trendFactor = 1.0;
            if (count($historicalData) > 14) {
                $recent = array_slice($historicalData, 0, 7);
                $older = array_slice($historicalData, 7, 7);
                $recentAvg = array_sum(array_column($recent, 'revenue')) / count($recent);
                $olderAvg = array_sum(array_column($older, 'revenue')) / count($older);
                if ($olderAvg > 0) {
                    $trendFactor = min(1.5, max(0.5, $recentAvg / $olderAvg));
                }
            }

            $forecast[] = [
                'date' => $date,
                'day_name' => getDayName($dow),
                'predicted_orders' => round($avgOrders * $trendFactor),
                'predicted_revenue' => round($avgRevenue * $trendFactor, 2),
                'confidence' => count($historicalData) > 30 ? 'high' : (count($historicalData) > 14 ? 'medium' : 'low'),
            ];
        }

        // Get pending payments (orders delivered but not yet paid out)
        $stmt = $db->prepare("
            SELECT SUM(total) as pending
            FROM om_market_orders
            WHERE partner_id = ? AND status = 'entregue'
            AND created_at >= NOW() - INTERVAL '30 days'
        ");
        $stmt->execute([$partnerId]);
        $pending = $stmt->fetch()['pending'] ?? 0;

        // Calculate next payout estimate (assuming weekly)
        $stmt = $db->prepare("
            SELECT SUM(total) as week_revenue
            FROM om_market_orders
            WHERE partner_id = ? AND status = 'entregue'
            AND created_at >= NOW() - INTERVAL '7 days'
        ");
        $stmt->execute([$partnerId]);
        $weekRevenue = $stmt->fetch()['week_revenue'] ?? 0;

        // Get commission rate
        $stmt = $db->prepare("SELECT commission_rate FROM om_market_partners WHERE partner_id = ?");
        $stmt->execute([$partnerId]);
        $commission = $stmt->fetch()['commission_rate'] ?? 10;

        $nextPayout = $weekRevenue * (1 - $commission / 100);

        // Monthly projection
        $monthProjection = 0;
        foreach ($forecast as $day) {
            $monthProjection += $day['predicted_revenue'];
        }
        $monthProjection = $monthProjection * 4.3; // Extrapolate to month

        // Insights
        $insights = [];

        // Best day
        $bestDay = null;
        $bestRevenue = 0;
        foreach ($dayAverages as $dow => $data) {
            if ($data['avg_revenue'] > $bestRevenue) {
                $bestRevenue = $data['avg_revenue'];
                $bestDay = $dow;
            }
        }
        if ($bestDay) {
            $insights[] = [
                'type' => 'info',
                'message' => getDayName($bestDay) . " e seu melhor dia, com media de R$ " . number_format($bestRevenue, 2, ',', '.'),
            ];
        }

        // Growth trend
        if (isset($trendFactor) && $trendFactor > 1.1) {
            $insights[] = [
                'type' => 'success',
                'message' => "Suas vendas estao crescendo! Tendencia de +" . round(($trendFactor - 1) * 100) . "% nas ultimas semanas.",
            ];
        } elseif (isset($trendFactor) && $trendFactor < 0.9) {
            $insights[] = [
                'type' => 'warning',
                'message' => "Suas vendas cairam " . round((1 - $trendFactor) * 100) . "%. Considere criar promocoes!",
            ];
        }

        response(true, [
            'forecast' => $forecast,
            'financial' => [
                'pending_payout' => round($pending, 2),
                'next_payout_estimate' => round($nextPayout, 2),
                'commission_rate' => $commission,
                'month_projection' => round($monthProjection, 2),
            ],
            'day_averages' => array_map(function($dow, $data) {
                return [
                    'day' => getDayName($dow),
                    'avg_orders' => $data['avg_orders'],
                    'avg_revenue' => $data['avg_revenue'],
                ];
            }, array_keys($dayAverages), $dayAverages),
            'insights' => $insights,
            'data_points' => count($historicalData),
        ]);
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[partner/financial-forecast] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}

function getDayName($dow) {
    $days = [1 => 'Domingo', 2 => 'Segunda', 3 => 'Terca', 4 => 'Quarta', 5 => 'Quinta', 6 => 'Sexta', 7 => 'Sabado'];
    return $days[$dow] ?? '';
}
