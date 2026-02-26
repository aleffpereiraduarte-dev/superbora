<?php
/**
 * GET /api/mercado/partner/analytics.php
 * Advanced analytics: acceptance rate, prep time, cancellation rate, peak hours, customer retention, avg rating
 * Params: period=month|week|today (default: month)
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

    $partnerId = (int)$payload['uid'];
    $period = $_GET['period'] ?? 'month';
    // Validate period against allowed values
    if (!in_array($period, ['today', 'week', 'month'], true)) {
        $period = 'month';
    }

    // Determine date range
    $now = new DateTime();
    switch ($period) {
        case 'today':
            $startDate = $now->format('Y-m-d');
            $endDate = $startDate;
            break;
        case 'week':
            $startDate = (clone $now)->modify('-7 days')->format('Y-m-d');
            $endDate = $now->format('Y-m-d');
            break;
        case 'month':
        default:
            $startDate = (clone $now)->modify('-30 days')->format('Y-m-d');
            $endDate = $now->format('Y-m-d');
            break;
    }

    // 1. Total orders in period (all statuses)
    $stmtTotal = $db->prepare("
        SELECT COUNT(*) as total
        FROM om_market_orders
        WHERE partner_id = ?
          AND DATE(date_added) BETWEEN ? AND ?
    ");
    $stmtTotal->execute([$partnerId, $startDate, $endDate]);
    $totalOrders = (int)$stmtTotal->fetchColumn();

    // 2. Accepted orders (not cancelled/pendente)
    $stmtAccepted = $db->prepare("
        SELECT COUNT(*) as total
        FROM om_market_orders
        WHERE partner_id = ?
          AND DATE(date_added) BETWEEN ? AND ?
          AND status NOT IN ('pendente', 'cancelado', 'cancelled')
    ");
    $stmtAccepted->execute([$partnerId, $startDate, $endDate]);
    $acceptedOrders = (int)$stmtAccepted->fetchColumn();

    // 3. Cancelled orders
    $stmtCancelled = $db->prepare("
        SELECT COUNT(*) as total
        FROM om_market_orders
        WHERE partner_id = ?
          AND DATE(date_added) BETWEEN ? AND ?
          AND status IN ('cancelado', 'cancelled')
    ");
    $stmtCancelled->execute([$partnerId, $startDate, $endDate]);
    $cancelledOrders = (int)$stmtCancelled->fetchColumn();

    // Rates
    $acceptanceRate = $totalOrders > 0 ? round(($acceptedOrders / $totalOrders) * 100, 1) : 0;
    $cancellationRate = $totalOrders > 0 ? round(($cancelledOrders / $totalOrders) * 100, 1) : 0;

    // 4. Average acceptance time (seconds between date_added and accepted_at)
    $stmtAcceptTime = $db->prepare("
        SELECT AVG(EXTRACT(EPOCH FROM (accepted_at - date_added))) as avg_seconds
        FROM om_market_orders
        WHERE partner_id = ?
          AND DATE(date_added) BETWEEN ? AND ?
          AND accepted_at IS NOT NULL
    ");
    $stmtAcceptTime->execute([$partnerId, $startDate, $endDate]);
    $avgAcceptanceTime = round((float)($stmtAcceptTime->fetchColumn() ?: 0));

    // 5. Average preparation time (minutes between accepted_at and when status became pronto)
    // We use order_events to find the pronto timestamp
    $stmtPrepTime = $db->prepare("
        SELECT AVG(EXTRACT(EPOCH FROM (e.created_at - o.accepted_at)) / 60) as avg_minutes
        FROM om_market_orders o
        INNER JOIN om_market_order_events e ON e.order_id = o.order_id AND e.event_type = 'partner_pronto'
        WHERE o.partner_id = ?
          AND DATE(o.date_added) BETWEEN ? AND ?
          AND o.accepted_at IS NOT NULL
    ");
    $stmtPrepTime->execute([$partnerId, $startDate, $endDate]);
    $avgPrepTime = round((float)($stmtPrepTime->fetchColumn() ?: 0));

    // 6. Peak hours (order count by hour of day)
    $stmtPeakHours = $db->prepare("
        SELECT
            EXTRACT(HOUR FROM date_added)::int as hour,
            COUNT(*) as order_count
        FROM om_market_orders
        WHERE partner_id = ?
          AND DATE(date_added) BETWEEN ? AND ?
          AND status NOT IN ('cancelado', 'cancelled')
        GROUP BY EXTRACT(HOUR FROM date_added)::int
        ORDER BY EXTRACT(HOUR FROM date_added)::int
    ");
    $stmtPeakHours->execute([$partnerId, $startDate, $endDate]);
    $peakHoursRaw = $stmtPeakHours->fetchAll();

    // Fill all 24 hours
    $peakHoursMap = [];
    foreach ($peakHoursRaw as $row) {
        $peakHoursMap[(int)$row['hour']] = (int)$row['order_count'];
    }
    $peakHours = [];
    for ($h = 0; $h < 24; $h++) {
        $peakHours[] = [
            'hour' => $h,
            'label' => str_pad($h, 2, '0', STR_PAD_LEFT) . ':00',
            'order_count' => $peakHoursMap[$h] ?? 0
        ];
    }

    // 7. Customer retention
    $stmtUniqueCustomers = $db->prepare("
        SELECT COUNT(DISTINCT customer_id) as total
        FROM om_market_orders
        WHERE partner_id = ?
          AND DATE(date_added) BETWEEN ? AND ?
          AND status NOT IN ('cancelado', 'cancelled')
    ");
    $stmtUniqueCustomers->execute([$partnerId, $startDate, $endDate]);
    $uniqueCustomers = (int)$stmtUniqueCustomers->fetchColumn();

    // Returning customers = customers with more than 1 order (ever, not just this period)
    $stmtReturning = $db->prepare("
        SELECT COUNT(*) as total FROM (
            SELECT customer_id
            FROM om_market_orders
            WHERE partner_id = ?
              AND DATE(date_added) BETWEEN ? AND ?
              AND status NOT IN ('cancelado', 'cancelled')
            GROUP BY customer_id
            HAVING COUNT(*) > 1
        ) as returning_customers
    ");
    $stmtReturning->execute([$partnerId, $startDate, $endDate]);
    $returningCustomers = (int)$stmtReturning->fetchColumn();

    $customerRetention = $uniqueCustomers > 0 ? round(($returningCustomers / $uniqueCustomers) * 100, 1) : 0;

    // 8. Average rating
    $stmtRating = $db->prepare("
        SELECT AVG(rating) as avg_rating, COUNT(*) as total_reviews
        FROM om_market_reviews
        WHERE partner_id = ?
          AND DATE(created_at) BETWEEN ? AND ?
    ");
    $stmtRating->execute([$partnerId, $startDate, $endDate]);
    $ratingData = $stmtRating->fetch();
    $avgRating = round((float)($ratingData['avg_rating'] ?? 0), 1);
    $totalReviews = (int)($ratingData['total_reviews'] ?? 0);

    response(true, [
        'period' => $period,
        'date_range' => ['start' => $startDate, 'end' => $endDate],
        'total_orders' => $totalOrders,
        'accepted_orders' => $acceptedOrders,
        'cancelled_orders' => $cancelledOrders,
        'acceptance_rate' => $acceptanceRate,
        'cancellation_rate' => $cancellationRate,
        'avg_acceptance_time' => $avgAcceptanceTime,
        'avg_preparation_time' => $avgPrepTime,
        'peak_hours' => $peakHours,
        'unique_customers' => $uniqueCustomers,
        'returning_customers' => $returningCustomers,
        'customer_retention' => $customerRetention,
        'avg_rating' => $avgRating,
        'total_reviews' => $totalReviews
    ]);

} catch (Exception $e) {
    error_log("[partner/analytics] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar analytics", 500);
}
