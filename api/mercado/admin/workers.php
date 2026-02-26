<?php
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();

    $search = $_GET['search'] ?? null;
    $status = $_GET['status'] ?? null;
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;

    $where = ["1=1"];
    $params = [];

    if ($search) {
        $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $search);
        $where[] = "(w.name ILIKE ? OR w.email ILIKE ? OR w.phone ILIKE ?)";
        $s = "%{$escaped}%";
        $params = array_merge($params, [$s, $s, $s]);
    }
    if ($status !== null) {
        $where[] = "w.status = ?";
        $params[] = $status;
    }

    $where_sql = implode(' AND ', $where);

    $stmt = $db->prepare("SELECT COUNT(*) as total FROM om_workers w WHERE {$where_sql}");
    $stmt->execute($params);
    $total = (int)$stmt->fetch()['total'];

    $stmt = $db->prepare("
        SELECT w.worker_id, w.name, w.email, w.phone, w.type, w.status,
               w.is_shopper, w.is_delivery, w.shopper_approved, w.delivery_approved,
               w.city, w.state, w.neighborhood,
               w.has_vehicle, w.vehicle_type, w.vehicle_plate,
               w.is_online, w.availability, w.is_available, w.is_paused, w.pause_reason,
               w.commission_rate, w.balance, w.total_earned,
               w.orders_today, w.orders_total, w.deliveries_today, w.deliveries_total,
               w.rating_avg, w.rating_count, w.rating, w.level,
               w.avatar, w.last_login, w.last_activity,
               w.created_at, w.updated_at,
               w.approved_at, w.approved_by, w.rejection_reason,
               w.total_deliveries, w.total_shops, w.total_earnings
        FROM om_workers w
        WHERE {$where_sql}
        ORDER BY w.name ASC
        LIMIT ? OFFSET ?
    ");
    $params[] = (int)$limit;
    $params[] = (int)$offset;
    $stmt->execute($params);
    $workers = $stmt->fetchAll();

    response(true, [
        'workers' => $workers,
        'pagination' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'pages' => ceil($total / $limit)]
    ], "Workers listados");
} catch (Exception $e) {
    error_log("[admin/workers] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
