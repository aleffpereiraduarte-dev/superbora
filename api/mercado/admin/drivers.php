<?php
/**
 * GET /api/mercado/admin/drivers.php
 * Driver Management - Returns all BoraUm drivers that have delivered for SuperBora
 *
 * Query params:
 *   status: all|active|inactive (default: all)
 *   vehicle: all|moto|carro|bicicleta (default: all)
 *   search: driver name search
 *   period: today|week|month|all (default: all)
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        response(false, null, 'Metodo nao permitido', 405);
    }

    $statusFilter = $_GET['status'] ?? 'all';
    $vehicleFilter = $_GET['vehicle'] ?? 'all';
    $search = trim($_GET['search'] ?? '');
    $period = $_GET['period'] ?? 'all';

    // Build period condition
    $periodWhere = '';
    $periodParams = [];
    switch ($period) {
        case 'today':
            $periodWhere = "AND DATE(e.created_at) = CURRENT_DATE";
            break;
        case 'week':
            $periodWhere = "AND e.created_at >= (CURRENT_DATE - INTERVAL '7 days')";
            break;
        case 'month':
            $periodWhere = "AND e.created_at >= (CURRENT_DATE - INTERVAL '30 days')";
            break;
    }

    // Main driver stats query - group by motorista_nome (BoraUm drivers)
    $searchWhere = '';
    if ($search) {
        $searchWhere = "AND LOWER(e.motorista_nome) LIKE LOWER(?)";
        $periodParams[] = '%' . $search . '%';
    }

    $vehicleWhere = '';
    if ($vehicleFilter !== 'all') {
        $vehicleWhere = "AND LOWER(e.motorista_veiculo) = LOWER(?)";
        $periodParams[] = $vehicleFilter;
    }

    $sql = "
        SELECT
            COALESCE(e.motorista_nome, 'Desconhecido') as driver_name,
            MAX(e.motorista_telefone) as phone,
            MAX(e.motorista_foto) as photo,
            MAX(e.motorista_veiculo) as vehicle,
            MAX(e.motorista_placa) as plate,
            COUNT(*) as total_deliveries,
            SUM(CASE WHEN e.status = 'entregue' OR e.boraum_status = 'delivered' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN e.status = 'cancelado' OR e.boraum_status IN ('cancelled', 'expired') THEN 1 ELSE 0 END) as cancelled,
            AVG(
                CASE WHEN e.entregue_em IS NOT NULL AND e.created_at IS NOT NULL
                THEN EXTRACT(EPOCH FROM (e.entregue_em - e.created_at)) / 60.0
                ELSE NULL END
            ) as avg_delivery_time_minutes,
            SUM(COALESCE(o.tip_amount, 0) + COALESCE(o.post_tip_amount, 0)) as total_tips,
            MAX(e.created_at) as last_delivery,
            -- On-time rate: deliveries under 45 minutes / total completed
            CASE
                WHEN SUM(CASE WHEN e.status = 'entregue' OR e.boraum_status = 'delivered' THEN 1 ELSE 0 END) > 0
                THEN ROUND(
                    SUM(CASE
                        WHEN (e.status = 'entregue' OR e.boraum_status = 'delivered')
                            AND e.entregue_em IS NOT NULL AND e.created_at IS NOT NULL
                            AND EXTRACT(EPOCH FROM (e.entregue_em - e.created_at)) / 60.0 <= 45
                        THEN 1 ELSE 0
                    END)::NUMERIC /
                    NULLIF(SUM(CASE WHEN e.status = 'entregue' OR e.boraum_status = 'delivered' THEN 1 ELSE 0 END), 0) * 100,
                    1
                )
                ELSE NULL
            END as on_time_rate
        FROM om_entregas e
        LEFT JOIN om_market_orders o ON e.referencia_id = o.order_id
        WHERE e.boraum_delivery_id IS NOT NULL
        AND e.motorista_nome IS NOT NULL
        AND e.motorista_nome != ''
        {$periodWhere}
        {$searchWhere}
        {$vehicleWhere}
        GROUP BY COALESCE(e.motorista_nome, 'Desconhecido')
        ORDER BY COUNT(*) DESC
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute($periodParams);
    $rawDrivers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get complaint counts per driver from om_boraum_disputes
    $complaintMap = [];
    try {
        $disputeStmt = $db->query("
            SELECT
                e.motorista_nome as driver_name,
                COUNT(d.id) as complaints
            FROM om_boraum_disputes d
            JOIN om_entregas e ON d.entrega_id = e.id
            WHERE e.motorista_nome IS NOT NULL
            GROUP BY e.motorista_nome
        ");
        foreach ($disputeStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $complaintMap[$row['driver_name']] = (int)$row['complaints'];
        }
    } catch (Exception $e) {
        // Table may not exist yet, ignore
        error_log("[admin/drivers] Disputes query failed: " . $e->getMessage());
    }

    // Get delivery ratings per driver from om_market_ratings
    $ratingMap = [];
    try {
        $ratingStmt = $db->query("
            SELECT
                e.motorista_nome as driver_name,
                AVG(r.rating_delivery) as avg_rating,
                COUNT(r.rating_delivery) as rating_count
            FROM om_market_ratings r
            JOIN om_market_orders o ON r.order_id = o.order_id
            JOIN om_entregas e ON e.referencia_id = o.order_id
            WHERE r.rating_delivery IS NOT NULL
            AND e.motorista_nome IS NOT NULL
            AND e.boraum_delivery_id IS NOT NULL
            GROUP BY e.motorista_nome
        ");
        foreach ($ratingStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $ratingMap[$row['driver_name']] = [
                'avg' => round((float)$row['avg_rating'], 1),
                'count' => (int)$row['rating_count'],
            ];
        }
    } catch (Exception $e) {
        error_log("[admin/drivers] Ratings query failed: " . $e->getMessage());
    }

    // Build driver list
    $drivers = [];
    $totalTips = 0;
    $totalDeliveryTimeSum = 0;
    $totalDeliveryTimeCount = 0;
    $totalRatingSum = 0;
    $totalRatingCount = 0;

    foreach ($rawDrivers as $row) {
        $name = $row['driver_name'];
        $complaints = $complaintMap[$name] ?? 0;
        $rating = $ratingMap[$name] ?? ['avg' => null, 'count' => 0];
        $avgTime = $row['avg_delivery_time_minutes'] !== null ? round((float)$row['avg_delivery_time_minutes']) : null;
        $tips = round((float)($row['total_tips'] ?? 0), 2);
        $completed = (int)$row['completed'];
        $total = (int)$row['total_deliveries'];

        // Determine activity status: active if delivered within last 7 days
        $lastDelivery = $row['last_delivery'];
        $isActive = false;
        if ($lastDelivery) {
            $daysSince = (time() - strtotime($lastDelivery)) / 86400;
            $isActive = $daysSince <= 7;
        }
        $status = $isActive ? 'active' : 'inactive';

        // Apply status filter
        if ($statusFilter !== 'all' && $statusFilter !== $status) {
            continue;
        }

        $driver = [
            'driver_id' => 'boraum_' . md5($name),
            'name' => $name,
            'phone' => $row['phone'] ?? null,
            'photo' => $row['photo'] ?? null,
            'vehicle' => $row['vehicle'] ?? 'moto',
            'plate' => $row['plate'] ?? null,
            'stats' => [
                'total_deliveries' => $total,
                'completed' => $completed,
                'cancelled' => (int)$row['cancelled'],
                'avg_delivery_time_minutes' => $avgTime,
                'avg_rating' => $rating['avg'],
                'rating_count' => $rating['count'],
                'total_tips' => $tips,
                'complaints' => $complaints,
                'on_time_rate' => $row['on_time_rate'] !== null ? (float)$row['on_time_rate'] : null,
            ],
            'last_delivery' => $lastDelivery,
            'status' => $status,
        ];

        $drivers[] = $driver;
        $totalTips += $tips;

        if ($avgTime !== null) {
            $totalDeliveryTimeSum += $avgTime;
            $totalDeliveryTimeCount++;
        }
        if ($rating['avg'] !== null) {
            $totalRatingSum += $rating['avg'];
            $totalRatingCount++;
        }
    }

    // Count active today
    $activeTodayStmt = $db->query("
        SELECT COUNT(DISTINCT motorista_nome) as cnt
        FROM om_entregas
        WHERE boraum_delivery_id IS NOT NULL
        AND motorista_nome IS NOT NULL
        AND DATE(created_at) = CURRENT_DATE
    ");
    $activeToday = (int)$activeTodayStmt->fetch()['cnt'];

    $summary = [
        'total_drivers' => count($drivers),
        'active_today' => $activeToday,
        'avg_delivery_time' => $totalDeliveryTimeCount > 0
            ? round($totalDeliveryTimeSum / $totalDeliveryTimeCount)
            : null,
        'avg_rating' => $totalRatingCount > 0
            ? round($totalRatingSum / $totalRatingCount, 1)
            : null,
        'total_tips' => round($totalTips, 2),
    ];

    response(true, [
        'drivers' => $drivers,
        'summary' => $summary,
    ]);

} catch (PDOException $e) {
    error_log("[admin/drivers] DB error: " . $e->getMessage());
    response(false, null, 'Erro interno', 500);
} catch (Exception $e) {
    if (in_array(http_response_code(), [401, 403], true)) {
        exit;
    }
    error_log("[admin/drivers] Error: " . $e->getMessage());
    response(false, null, 'Erro interno', 500);
}
