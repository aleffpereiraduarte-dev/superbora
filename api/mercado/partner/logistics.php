<?php
/**
 * GET /api/mercado/partner/logistics.php
 * Logistics Dashboard (iFood Painel Logistico)
 *
 * Provides detailed time metrics for each stage of the order lifecycle:
 * pending -> accepted -> preparing -> ready -> delivering -> delivered.
 * Includes hourly/daily breakdowns, on-time rate, cancellation analysis.
 *
 * Auth: Bearer token (partner type)
 * Cache: Redis 15 minutes
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
        response(false, null, "Metodo nao permitido. Use GET.", 405);
    }

    // ── Redis cache check (15 min) ──
    $redis = RedisService::getInstance();
    $cacheKey = "logistics:{$partnerId}";
    if ($redis->isAvailable()) {
        $cached = $redis->get($cacheKey);
        if ($cached && is_array($cached)) {
            response(true, $cached, "Dashboard logistico (cache).");
        }
    }

    // ── Step 1: Average stage times (last 30 days) ──
    $stmtAvg = $db->prepare("
        SELECT
            AVG(CASE WHEN accepted_at IS NOT NULL AND date_added IS NOT NULL
                THEN EXTRACT(EPOCH FROM (accepted_at - date_added)) / 60 END) as pending_avg,
            AVG(CASE WHEN preparing_started_at IS NOT NULL AND accepted_at IS NOT NULL
                THEN EXTRACT(EPOCH FROM (preparing_started_at - accepted_at)) / 60 END) as accepted_avg,
            AVG(CASE WHEN ready_at IS NOT NULL AND preparing_started_at IS NOT NULL
                THEN EXTRACT(EPOCH FROM (ready_at - preparing_started_at)) / 60 END) as preparing_avg,
            AVG(CASE WHEN delivering_at IS NOT NULL AND ready_at IS NOT NULL
                THEN EXTRACT(EPOCH FROM (delivering_at - ready_at)) / 60 END) as ready_avg,
            AVG(CASE WHEN delivered_at IS NOT NULL AND delivering_at IS NOT NULL
                THEN EXTRACT(EPOCH FROM (delivered_at - delivering_at)) / 60 END) as delivering_avg,
            AVG(CASE WHEN accepted_at IS NOT NULL AND ready_at IS NOT NULL
                THEN EXTRACT(EPOCH FROM (ready_at - accepted_at)) / 60 END) as avg_prep_time,
            AVG(CASE WHEN ready_at IS NOT NULL AND delivered_at IS NOT NULL
                THEN EXTRACT(EPOCH FROM (delivered_at - ready_at)) / 60 END) as avg_delivery_time,
            AVG(CASE WHEN date_added IS NOT NULL AND delivered_at IS NOT NULL
                THEN EXTRACT(EPOCH FROM (delivered_at - date_added)) / 60 END) as avg_total_time,
            COUNT(CASE WHEN delivered_at IS NOT NULL AND date_added IS NOT NULL
                AND EXTRACT(EPOCH FROM (delivered_at - date_added)) / 60 > 60
                THEN 1 END) as delays,
            COUNT(CASE WHEN delivered_at IS NOT NULL THEN 1 END) as delivered_count,
            COUNT(CASE WHEN delivered_at IS NOT NULL AND date_added IS NOT NULL
                AND EXTRACT(EPOCH FROM (delivered_at - date_added)) / 60 <= 45
                THEN 1 END) as on_time_count,
            COUNT(*) as total_orders,
            COUNT(CASE WHEN status = 'cancelado' THEN 1 END) as cancelled_count
        FROM om_market_orders
        WHERE partner_id = ?
          AND date_added >= NOW() - INTERVAL '30 days'
    ");
    $stmtAvg->execute([$partnerId]);
    $avg = $stmtAvg->fetch(PDO::FETCH_ASSOC);

    $totalOrders = (int)($avg['total_orders'] ?? 0);
    $deliveredCount = (int)($avg['delivered_count'] ?? 0);
    $cancelledCount = (int)($avg['cancelled_count'] ?? 0);
    $onTimeCount = (int)($avg['on_time_count'] ?? 0);
    $delays = (int)($avg['delays'] ?? 0);

    $onTimeRate = $deliveredCount > 0 ? round(($onTimeCount / $deliveredCount) * 100, 1) : 0;
    $cancellationRate = $totalOrders > 0 ? round(($cancelledCount / $totalOrders) * 100, 1) : 0;

    $timeByStage = [
        'pending_avg' => round((float)($avg['pending_avg'] ?? 0), 1),
        'accepted_avg' => round((float)($avg['accepted_avg'] ?? 0), 1),
        'preparing_avg' => round((float)($avg['preparing_avg'] ?? 0), 1),
        'ready_avg' => round((float)($avg['ready_avg'] ?? 0), 1),
        'delivering_avg' => round((float)($avg['delivering_avg'] ?? 0), 1),
    ];

    // ── Step 2: Average prep time by hour of day ──
    $stmtHour = $db->prepare("
        SELECT
            EXTRACT(HOUR FROM date_added)::int as hour,
            AVG(CASE WHEN accepted_at IS NOT NULL AND ready_at IS NOT NULL
                THEN EXTRACT(EPOCH FROM (ready_at - accepted_at)) / 60 END) as avg_prep,
            COUNT(*) as order_count
        FROM om_market_orders
        WHERE partner_id = ?
          AND date_added >= NOW() - INTERVAL '30 days'
          AND status NOT IN ('cancelado')
        GROUP BY hour
        ORDER BY hour
    ");
    $stmtHour->execute([$partnerId]);
    $hourlyRows = $stmtHour->fetchAll(PDO::FETCH_ASSOC);

    // Build full 24-hour array
    $byHour = [];
    $hourMap = [];
    foreach ($hourlyRows as $row) {
        $hourMap[(int)$row['hour']] = $row;
    }
    for ($h = 0; $h < 24; $h++) {
        $row = $hourMap[$h] ?? null;
        $byHour[] = [
            'hour' => $h,
            'hour_label' => str_pad($h, 2, '0', STR_PAD_LEFT) . ':00',
            'avg_prep_time' => $row ? round((float)$row['avg_prep'], 1) : 0,
            'order_count' => $row ? (int)$row['order_count'] : 0,
        ];
    }

    // ── Step 3: Last 7 days with avg times per day ──
    $stmtDay = $db->prepare("
        SELECT
            DATE(date_added) as day,
            EXTRACT(DOW FROM date_added)::int as dow,
            COUNT(*) as total,
            COUNT(CASE WHEN status IN ('entregue', 'finalizado') THEN 1 END) as delivered,
            COUNT(CASE WHEN status = 'cancelado' THEN 1 END) as cancelled,
            AVG(CASE WHEN accepted_at IS NOT NULL AND ready_at IS NOT NULL
                THEN EXTRACT(EPOCH FROM (ready_at - accepted_at)) / 60 END) as avg_prep,
            AVG(CASE WHEN ready_at IS NOT NULL AND delivered_at IS NOT NULL
                THEN EXTRACT(EPOCH FROM (delivered_at - ready_at)) / 60 END) as avg_delivery,
            AVG(CASE WHEN date_added IS NOT NULL AND delivered_at IS NOT NULL
                THEN EXTRACT(EPOCH FROM (delivered_at - date_added)) / 60 END) as avg_total
        FROM om_market_orders
        WHERE partner_id = ?
          AND date_added >= NOW() - INTERVAL '7 days'
        GROUP BY day, dow
        ORDER BY day DESC
    ");
    $stmtDay->execute([$partnerId]);
    $dailyRows = $stmtDay->fetchAll(PDO::FETCH_ASSOC);

    $dayNames = ['Domingo', 'Segunda', 'Terca', 'Quarta', 'Quinta', 'Sexta', 'Sabado'];
    $byDay = [];
    foreach ($dailyRows as $row) {
        $byDay[] = [
            'date' => $row['day'],
            'day_name' => $dayNames[(int)$row['dow']] ?? '',
            'total_orders' => (int)$row['total'],
            'delivered' => (int)$row['delivered'],
            'cancelled' => (int)$row['cancelled'],
            'avg_prep_time' => round((float)($row['avg_prep'] ?? 0), 1),
            'avg_delivery_time' => round((float)($row['avg_delivery'] ?? 0), 1),
            'avg_total_time' => round((float)($row['avg_total'] ?? 0), 1),
        ];
    }

    // ── Step 4: Top 5 cancellation reasons ──
    $stmtCancel = $db->prepare("
        SELECT
            COALESCE(NULLIF(TRIM(cancel_reason), ''), 'Nao informado') as reason,
            COUNT(*) as cnt
        FROM om_market_orders
        WHERE partner_id = ?
          AND status = 'cancelado'
          AND date_added >= NOW() - INTERVAL '30 days'
        GROUP BY reason
        ORDER BY cnt DESC
        LIMIT 5
    ");
    $stmtCancel->execute([$partnerId]);
    $cancelReasons = [];
    while ($row = $stmtCancel->fetch(PDO::FETCH_ASSOC)) {
        $cancelReasons[] = [
            'reason' => $row['reason'],
            'count' => (int)$row['cnt'],
        ];
    }

    // ── Step 5: Bottleneck detection ──
    $bottleneck = null;
    $stages = [
        ['name' => 'Tempo de aceite', 'key' => 'pending_avg', 'threshold' => 10],
        ['name' => 'Inicio do preparo', 'key' => 'accepted_avg', 'threshold' => 5],
        ['name' => 'Preparo', 'key' => 'preparing_avg', 'threshold' => 30],
        ['name' => 'Despacho', 'key' => 'ready_avg', 'threshold' => 10],
        ['name' => 'Entrega', 'key' => 'delivering_avg', 'threshold' => 30],
    ];
    $maxExcess = 0;
    foreach ($stages as $stage) {
        $val = $timeByStage[$stage['key']];
        $excess = $val - $stage['threshold'];
        if ($excess > $maxExcess && $val > 0) {
            $maxExcess = $excess;
            $bottleneck = [
                'stage' => $stage['name'],
                'avg_minutes' => $val,
                'threshold_minutes' => $stage['threshold'],
                'excess_minutes' => round($excess, 1),
            ];
        }
    }

    $result = [
        'avg_prep_time' => round((float)($avg['avg_prep_time'] ?? 0), 1),
        'avg_delivery_time' => round((float)($avg['avg_delivery_time'] ?? 0), 1),
        'avg_total_time' => round((float)($avg['avg_total_time'] ?? 0), 1),
        'time_by_stage' => $timeByStage,
        'delays' => $delays,
        'on_time_rate' => $onTimeRate,
        'total_orders' => $totalOrders,
        'delivered_count' => $deliveredCount,
        'cancellation_rate' => $cancellationRate,
        'cancel_reasons' => $cancelReasons,
        'by_hour' => $byHour,
        'by_day' => $byDay,
        'bottleneck' => $bottleneck,
    ];

    // ── Cache result in Redis for 15 minutes ──
    if ($redis->isAvailable()) {
        $redis->set($cacheKey, $result, 900);
    }

    response(true, $result, "Dashboard logistico calculado com sucesso.");

} catch (Exception $e) {
    error_log("[partner/logistics] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
