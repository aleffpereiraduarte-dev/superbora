<?php
/**
 * GET /api/mercado/partner/orders-hourly.php
 * Order counts by hour for today
 * Returns array of {hour: 0-23, count, revenue} with missing hours filled as 0
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $payload = om_auth()->requirePartner();
    $partnerId = (int)$payload['uid'];

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        response(false, null, "Metodo nao permitido", 405);
    }

    $stmt = dbQuery($db, "
        SELECT
            EXTRACT(HOUR FROM date_added)::int as hour,
            COUNT(*) as count,
            COALESCE(SUM(total), 0) as revenue
        FROM om_market_orders
        WHERE partner_id = ?
          AND DATE(date_added) = CURRENT_DATE
          AND status NOT IN ('cancelado', 'recusado')
        GROUP BY EXTRACT(HOUR FROM date_added)
        ORDER BY hour
    ", [$partnerId]);
    $rows = $stmt->fetchAll();

    // Index by hour for quick lookup
    $byHour = [];
    foreach ($rows as $row) {
        $byHour[(int)$row['hour']] = [
            'count' => (int)$row['count'],
            'revenue' => round((float)$row['revenue'], 2),
        ];
    }

    // Fill all 24 hours
    $hourly = [];
    for ($h = 0; $h < 24; $h++) {
        $hourly[] = [
            'hour' => $h,
            'count' => $byHour[$h]['count'] ?? 0,
            'revenue' => $byHour[$h]['revenue'] ?? 0,
        ];
    }

    // Summary
    $totalCount = array_sum(array_column($hourly, 'count'));
    $totalRevenue = array_sum(array_column($hourly, 'revenue'));
    $peakHour = 0;
    $peakCount = 0;
    foreach ($hourly as $h) {
        if ($h['count'] > $peakCount) {
            $peakCount = $h['count'];
            $peakHour = $h['hour'];
        }
    }

    response(true, [
        'hourly' => $hourly,
        'summary' => [
            'total_orders' => $totalCount,
            'total_revenue' => round($totalRevenue, 2),
            'peak_hour' => $peakHour,
            'peak_count' => $peakCount,
        ],
    ], "Pedidos por hora");

} catch (Exception $e) {
    error_log("[partner/orders-hourly] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
