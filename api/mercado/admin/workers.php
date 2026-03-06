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

    // Single worker detail
    $id = (int)($_GET['id'] ?? 0);
    $section = trim($_GET['section'] ?? '');

    if ($id > 0) {
        // Section: earnings
        if ($section === 'earnings') {
            $stmt = $db->prepare("
                SELECT id, amount, type, description, status, created_at
                FROM om_worker_earnings
                WHERE worker_id = ?
                ORDER BY created_at DESC LIMIT 50
            ");
            try {
                $stmt->execute([$id]);
                $earnings = $stmt->fetchAll();
            } catch (\Exception $e) {
                // Fallback: calculate from orders
                $stmt = $db->prepare("
                    SELECT order_id as id, delivery_fee as amount, 'delivery' as type, status as description, status, created_at
                    FROM om_market_orders
                    WHERE shopper_id = ? AND status IN ('entregue', 'delivered')
                    ORDER BY created_at DESC LIMIT 50
                ");
                $stmt->execute([$id]);
                $earnings = $stmt->fetchAll();
            }
            response(true, ['earnings' => $earnings], "Ganhos do worker");
        }

        // Section: reviews
        if ($section === 'reviews') {
            $reviews = [];
            try {
                $stmt = $db->prepare("
                    SELECT r.id, r.rating, r.comment, r.created_at,
                           c.name as customer_name, r.order_id
                    FROM om_worker_reviews r
                    LEFT JOIN om_customers c ON r.customer_id = c.customer_id
                    WHERE r.worker_id = ?
                    ORDER BY r.created_at DESC LIMIT 50
                ");
                $stmt->execute([$id]);
                $reviews = $stmt->fetchAll();
            } catch (\Exception $e) {
                // Table may not exist
            }
            response(true, ['reviews' => $reviews], "Avaliacoes do worker");
        }

        // Default: full worker detail
        $stmt = $db->prepare("
            SELECT w.*
            FROM om_workers w
            WHERE w.worker_id = ?
        ");
        $stmt->execute([$id]);
        $worker = $stmt->fetch();
        if (!$worker) response(false, null, "Worker nao encontrado", 404);

        // Remove password hash from response
        unset($worker['password'], $worker['password_hash']);

        // Stats
        $deliveryCount = 0;
        try {
            $stmt = $db->prepare("SELECT COUNT(*) as total FROM om_market_orders WHERE shopper_id = ? AND status IN ('entregue', 'delivered')");
            $stmt->execute([$id]);
            $deliveryCount = (int)$stmt->fetch()['total'];
        } catch (\Exception $e) {}

        $worker['completed_deliveries'] = $deliveryCount;
        response(true, $worker, "Detalhe do worker");
    }

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
