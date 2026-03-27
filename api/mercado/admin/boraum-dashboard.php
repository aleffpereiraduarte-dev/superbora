<?php
/**
 * BoraUm Delivery Management Dashboard API
 *
 * GET: Returns comprehensive BoraUm delivery data for admin panel
 * POST: Mark billing period as paid
 *
 * Query params (GET):
 *   period: today|week|month|custom (default: today)
 *   start_date, end_date: for custom period (Y-m-d)
 *   status: all|entregue|cancelado|pendente (default: all)
 *   page, limit: pagination (default: 1, 20)
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    // Admin auth: JWT admin token
    $payload = om_auth()->requireAdmin();

    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        handleGet($db);
    } elseif ($method === 'POST') {
        handlePost($db, $payload);
    } else {
        response(false, null, 'Metodo nao permitido', 405);
    }
} catch (PDOException $e) {
    error_log("[BoraUm Dashboard] DB error: " . $e->getMessage());
    response(false, null, 'Erro interno', 500);
} catch (Exception $e) {
    // OmAuth throws exceptions for auth failures — let them propagate with proper codes
    if (in_array(http_response_code(), [401, 403], true)) {
        exit; // Already sent by OmAuth
    }
    error_log("[BoraUm Dashboard] Error: " . $e->getMessage());
    response(false, null, 'Erro interno', 500);
}

function handleGet(PDO $db): void {
    $period = $_GET['period'] ?? 'today';
    $statusFilter = $_GET['status'] ?? 'all';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    // Build date range
    $dateCondition = buildDateCondition($period);
    $dateParams = $dateCondition['params'];
    $dateWhere = $dateCondition['where'];

    // === SUMMARY ===
    $summary = getSummary($db, $dateWhere, $dateParams);

    // === BILLING ===
    $billing = getBilling($db);

    // === DELIVERIES LIST ===
    $deliveriesResult = getDeliveries($db, $dateWhere, $dateParams, $statusFilter, $limit, $offset);

    // === DAILY CHART ===
    $dailyChart = getDailyChart($db, $dateCondition);

    // === TOTAL for pagination ===
    $total = getDeliveryCount($db, $dateWhere, $dateParams, $statusFilter);

    response(true, [
        'summary' => $summary,
        'billing' => $billing,
        'deliveries' => $deliveriesResult,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
        ],
        'daily_chart' => $dailyChart,
    ]);
}

function handlePost(PDO $db, array $payload): void {
    $input = getInput();
    $action = $input['action'] ?? '';

    if ($action === 'mark_paid') {
        $billingId = (int)($input['billing_id'] ?? 0);
        if (!$billingId) {
            response(false, null, 'billing_id obrigatorio', 400);
        }

        $stmt = $db->prepare("
            UPDATE om_boraum_billing
            SET status = 'paid', paid_at = NOW(), notes = COALESCE(notes, '') || ?
            WHERE id = ? AND status != 'paid'
        ");
        $note = "\n[Pago por admin #{$payload['uid']} em " . date('Y-m-d H:i') . "]";
        $stmt->execute([$note, $billingId]);

        if ($stmt->rowCount() === 0) {
            response(false, null, 'Fatura nao encontrada ou ja paga', 404);
        }

        response(true, ['billing_id' => $billingId, 'status' => 'paid']);
    }

    response(false, null, 'Acao invalida', 400);
}

function buildDateCondition(string $period): array {
    $startDate = $_GET['start_date'] ?? '';
    $endDate = $_GET['end_date'] ?? '';

    switch ($period) {
        case 'week':
            return [
                'where' => "e.created_at >= (CURRENT_DATE - INTERVAL '7 days')",
                'params' => [],
                'chart_where' => "e.created_at >= (CURRENT_DATE - INTERVAL '7 days')",
                'chart_params' => [],
            ];
        case 'month':
            return [
                'where' => "e.created_at >= (CURRENT_DATE - INTERVAL '30 days')",
                'params' => [],
                'chart_where' => "e.created_at >= (CURRENT_DATE - INTERVAL '30 days')",
                'chart_params' => [],
            ];
        case 'custom':
            if ($startDate && $endDate) {
                // Validate date format
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
                    response(false, null, 'Formato de data invalido (use YYYY-MM-DD)', 400);
                }
                return [
                    'where' => "DATE(e.created_at) BETWEEN ? AND ?",
                    'params' => [$startDate, $endDate],
                    'chart_where' => "DATE(e.created_at) BETWEEN ? AND ?",
                    'chart_params' => [$startDate, $endDate],
                ];
            }
            // Fall through to today
        case 'today':
        default:
            return [
                'where' => "DATE(e.created_at) = CURRENT_DATE",
                'params' => [],
                'chart_where' => "e.created_at >= (CURRENT_DATE - INTERVAL '7 days')",
                'chart_params' => [],
            ];
    }
}

function getSummary(PDO $db, string $dateWhere, array $dateParams): array {
    $sql = "
        SELECT
            COUNT(*) as total_deliveries,
            SUM(CASE WHEN e.status = 'entregue' OR e.boraum_status = 'delivered' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN e.status = 'cancelado' OR e.boraum_status IN ('cancelled', 'expired') THEN 1 ELSE 0 END) as cancelled,
            SUM(CASE WHEN e.status NOT IN ('entregue', 'cancelado')
                      AND e.boraum_status NOT IN ('delivered', 'cancelled', 'expired') THEN 1 ELSE 0 END) as pending,
            COALESCE(SUM(e.valor_frete), 0) as total_boraum_cost,
            COALESCE(SUM(o.delivery_fee), 0) as total_client_fees,
            COALESCE(SUM(o.delivery_fee) - SUM(e.valor_frete), 0) as total_superbora_margin,
            CASE WHEN COUNT(*) > 0
                THEN ROUND(COALESCE(SUM(e.valor_frete), 0) / COUNT(*), 2)
                ELSE 0 END as avg_delivery_cost,
            CASE WHEN COUNT(CASE WHEN e.entregue_em IS NOT NULL THEN 1 END) > 0
                THEN ROUND(AVG(
                    CASE WHEN e.entregue_em IS NOT NULL AND e.created_at IS NOT NULL
                        THEN EXTRACT(EPOCH FROM (e.entregue_em - e.created_at)) / 60.0
                        ELSE NULL END
                )::numeric, 0)
                ELSE 0 END as avg_delivery_time_minutes,
            CASE WHEN COUNT(*) > 0
                THEN ROUND(
                    SUM(CASE WHEN e.status = 'cancelado' OR e.boraum_status IN ('cancelled', 'expired') THEN 1 ELSE 0 END)::numeric
                    / COUNT(*)::numeric * 100, 1
                )
                ELSE 0 END as cancel_rate_percent
        FROM om_entregas e
        LEFT JOIN om_market_orders o ON e.referencia_id = o.order_id
        WHERE e.boraum_delivery_id IS NOT NULL
        AND {$dateWhere}
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute($dateParams);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return [
        'total_deliveries' => (int)$row['total_deliveries'],
        'completed' => (int)$row['completed'],
        'cancelled' => (int)$row['cancelled'],
        'pending' => (int)$row['pending'],
        'total_boraum_cost' => round((float)$row['total_boraum_cost'], 2),
        'total_client_fees' => round((float)$row['total_client_fees'], 2),
        'total_superbora_margin' => round((float)$row['total_superbora_margin'], 2),
        'avg_delivery_cost' => round((float)$row['avg_delivery_cost'], 2),
        'avg_delivery_time_minutes' => (int)$row['avg_delivery_time_minutes'],
        'cancel_rate_percent' => round((float)$row['cancel_rate_percent'], 1),
    ];
}

function getBilling(PDO $db): array {
    // Get overall billing summary
    $stmt = $db->query("
        SELECT
            COALESCE(SUM(CASE WHEN status = 'pending' THEN net_amount ELSE 0 END), 0) as total_owed,
            COALESCE(SUM(CASE WHEN status = 'paid' THEN net_amount ELSE 0 END), 0) as total_paid,
            COALESCE(SUM(CASE WHEN status = 'pending' THEN net_amount ELSE 0 END), 0) as pending_payment,
            MAX(CASE WHEN status = 'paid' THEN paid_at ELSE NULL END) as last_payment_date,
            MAX(CASE WHEN status = 'pending' THEN period_end ELSE NULL END) as next_payment_date
        FROM om_boraum_billing
    ");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get recent billing periods
    $stmt = $db->query("
        SELECT id, period_start, period_end, total_deliveries, completed_deliveries,
               cancelled_deliveries, total_boraum_cost, total_superbora_margin,
               net_amount, status, paid_at, notes, created_at
        FROM om_boraum_billing
        ORDER BY period_end DESC
        LIMIT 10
    ");
    $periods = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'total_owed' => round((float)$row['total_owed'], 2),
        'total_paid' => round((float)$row['total_paid'], 2),
        'pending_payment' => round((float)$row['pending_payment'], 2),
        'last_payment_date' => $row['last_payment_date'] ? date('Y-m-d', strtotime($row['last_payment_date'])) : null,
        'next_payment_date' => $row['next_payment_date'] ?? null,
        'periods' => array_map(function($p) {
            return [
                'id' => (int)$p['id'],
                'period_start' => $p['period_start'],
                'period_end' => $p['period_end'],
                'total_deliveries' => (int)$p['total_deliveries'],
                'completed_deliveries' => (int)$p['completed_deliveries'],
                'cancelled_deliveries' => (int)$p['cancelled_deliveries'],
                'total_boraum_cost' => round((float)$p['total_boraum_cost'], 2),
                'total_superbora_margin' => round((float)$p['total_superbora_margin'], 2),
                'net_amount' => round((float)$p['net_amount'], 2),
                'status' => $p['status'],
                'paid_at' => $p['paid_at'],
                'created_at' => $p['created_at'],
            ];
        }, $periods),
    ];
}

function getDeliveries(PDO $db, string $dateWhere, array $dateParams, string $statusFilter, int $limit, int $offset): array {
    $statusWhere = '';
    $statusParams = [];

    if ($statusFilter === 'entregue') {
        $statusWhere = "AND (e.status = 'entregue' OR e.boraum_status = 'delivered')";
    } elseif ($statusFilter === 'cancelado') {
        $statusWhere = "AND (e.status = 'cancelado' OR e.boraum_status IN ('cancelled', 'expired'))";
    } elseif ($statusFilter === 'pendente') {
        $statusWhere = "AND e.status NOT IN ('entregue', 'cancelado') AND e.boraum_status NOT IN ('delivered', 'cancelled', 'expired')";
    }

    $sql = "
        SELECT
            e.id,
            e.referencia_id as order_id,
            o.order_number,
            COALESCE(o.customer_name, e.destinatario_nome) as customer_name,
            COALESCE(o.partner_name, e.remetente_nome) as partner_name,
            e.status,
            e.boraum_status,
            e.boraum_delivery_id,
            e.motorista_veiculo as vehicle_type,
            e.motorista_nome as driver_name,
            e.motorista_telefone as driver_phone,
            e.motorista_foto as driver_photo,
            e.motorista_placa as driver_plate,
            COALESCE(e.distancia_km, 0) as distance_km,
            COALESCE(e.valor_frete, 0) as boraum_cost,
            COALESCE(o.delivery_fee, 0) as client_fee,
            COALESCE(o.delivery_fee, 0) - COALESCE(e.valor_frete, 0) as superbora_margin,
            e.created_at,
            e.entregue_em as delivered_at,
            CASE WHEN e.entregue_em IS NOT NULL AND e.created_at IS NOT NULL
                THEN ROUND(EXTRACT(EPOCH FROM (e.entregue_em - e.created_at)) / 60.0)
                ELSE NULL END as delivery_time_minutes
        FROM om_entregas e
        LEFT JOIN om_market_orders o ON e.referencia_id = o.order_id
        WHERE e.boraum_delivery_id IS NOT NULL
        AND {$dateWhere}
        {$statusWhere}
        ORDER BY e.created_at DESC NULLS LAST, e.id DESC
        LIMIT ? OFFSET ?
    ";

    $allParams = array_merge($dateParams, $statusParams, [$limit, $offset]);
    $stmt = $db->prepare($sql);
    $stmt->execute($allParams);

    return array_map(function($row) {
        return [
            'id' => (int)$row['id'],
            'order_id' => $row['order_id'] ? (int)$row['order_id'] : null,
            'order_number' => $row['order_number'] ?? '-',
            'customer_name' => $row['customer_name'] ?? '-',
            'partner_name' => $row['partner_name'] ?? '-',
            'status' => $row['status'],
            'boraum_status' => $row['boraum_status'],
            'boraum_delivery_id' => (int)$row['boraum_delivery_id'],
            'vehicle_type' => $row['vehicle_type'] ?? 'moto',
            'driver_name' => $row['driver_name'] ?? '-',
            'driver_phone' => $row['driver_phone'] ?? null,
            'driver_photo' => $row['driver_photo'] ?? null,
            'driver_plate' => $row['driver_plate'] ?? null,
            'distance_km' => round((float)$row['distance_km'], 2),
            'boraum_cost' => round((float)$row['boraum_cost'], 2),
            'client_fee' => round((float)$row['client_fee'], 2),
            'superbora_margin' => round((float)$row['superbora_margin'], 2),
            'created_at' => $row['created_at'],
            'delivered_at' => $row['delivered_at'],
            'delivery_time_minutes' => $row['delivery_time_minutes'] !== null ? (int)$row['delivery_time_minutes'] : null,
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function getDeliveryCount(PDO $db, string $dateWhere, array $dateParams, string $statusFilter): int {
    $statusWhere = '';
    if ($statusFilter === 'entregue') {
        $statusWhere = "AND (e.status = 'entregue' OR e.boraum_status = 'delivered')";
    } elseif ($statusFilter === 'cancelado') {
        $statusWhere = "AND (e.status = 'cancelado' OR e.boraum_status IN ('cancelled', 'expired'))";
    } elseif ($statusFilter === 'pendente') {
        $statusWhere = "AND e.status NOT IN ('entregue', 'cancelado') AND e.boraum_status NOT IN ('delivered', 'cancelled', 'expired')";
    }

    $sql = "
        SELECT COUNT(*) as total
        FROM om_entregas e
        WHERE e.boraum_delivery_id IS NOT NULL
        AND {$dateWhere}
        {$statusWhere}
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute($dateParams);
    return (int)$stmt->fetch()['total'];
}

function getDailyChart(PDO $db, array $dateCondition): array {
    $chartWhere = $dateCondition['chart_where'];
    $chartParams = $dateCondition['chart_params'];

    $sql = "
        SELECT
            DATE(e.created_at) as date,
            COUNT(*) as deliveries,
            COALESCE(SUM(e.valor_frete), 0) as cost,
            COALESCE(SUM(o.delivery_fee), 0) as revenue,
            COALESCE(SUM(o.delivery_fee) - SUM(e.valor_frete), 0) as margin
        FROM om_entregas e
        LEFT JOIN om_market_orders o ON e.referencia_id = o.order_id
        WHERE e.boraum_delivery_id IS NOT NULL
        AND e.created_at IS NOT NULL
        AND {$chartWhere}
        GROUP BY DATE(e.created_at)
        ORDER BY DATE(e.created_at) ASC
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute($chartParams);

    return array_map(function($row) {
        return [
            'date' => $row['date'],
            'deliveries' => (int)$row['deliveries'],
            'cost' => round((float)$row['cost'], 2),
            'revenue' => round((float)$row['revenue'], 2),
            'margin' => round((float)$row['margin'], 2),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}
