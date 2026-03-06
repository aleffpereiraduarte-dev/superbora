<?php
/**
 * GET /api/mercado/admin/scheduled-orders-admin.php
 *
 * Lista pedidos agendados para o painel administrativo.
 *
 * Query: ?status=agendado|processando|concluido|cancelado|past_due&page=1&limit=20&search=
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') response(false, null, "Metodo nao permitido", 405);

    $status = trim($_GET['status'] ?? '');
    $search = trim($_GET['search'] ?? '');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $where = ["1=1"];
    $params = [];

    // Filter by status
    if ($status !== '') {
        if ($status === 'past_due') {
            // Past due: scheduled date has passed but still in 'agendado' status
            $where[] = "so.status = 'agendado'";
            $where[] = "so.scheduled_date < CURRENT_DATE";
        } elseif ($status === 'upcoming') {
            // Upcoming: scheduled for today or later, still agendado
            $where[] = "so.status = 'agendado'";
            $where[] = "so.scheduled_date >= CURRENT_DATE";
        } else {
            $where[] = "so.status = ?";
            $params[] = $status;
        }
    }

    // Search by customer name or store name
    if ($search !== '') {
        $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $search);
        $where[] = "(c.name ILIKE ? OR p.name ILIKE ?)";
        $s = "%{$escaped}%";
        $params[] = $s;
        $params[] = $s;
    }

    $where_sql = implode(' AND ', $where);

    // Count total
    $stmt = $db->prepare("
        SELECT COUNT(*) as total
        FROM om_market_scheduled_orders so
        LEFT JOIN om_customers c ON so.customer_id = c.customer_id
        LEFT JOIN om_market_partners p ON so.store_id = p.partner_id
        WHERE {$where_sql}
    ");
    $stmt->execute($params);
    $total = (int)$stmt->fetch()['total'];

    // Fetch scheduled orders
    $stmt = $db->prepare("
        SELECT so.id, so.customer_id, so.store_id, so.scheduled_date, so.scheduled_time,
               so.subtotal, so.delivery_fee, so.total, so.payment_method,
               so.notes, so.status, so.recurring_id, so.created_at, so.updated_at,
               c.name as customer_name, c.email as customer_email, c.phone as customer_phone,
               p.name as partner_name,
               CASE
                   WHEN so.status = 'agendado' AND so.scheduled_date < CURRENT_DATE THEN true
                   ELSE false
               END as is_past_due
        FROM om_market_scheduled_orders so
        LEFT JOIN om_customers c ON so.customer_id = c.customer_id
        LEFT JOIN om_market_partners p ON so.store_id = p.partner_id
        WHERE {$where_sql}
        ORDER BY so.scheduled_date ASC, so.scheduled_time ASC NULLS LAST
        LIMIT ? OFFSET ?
    ");
    $params[] = $limit;
    $params[] = $offset;
    $stmt->execute($params);
    $orders = $stmt->fetchAll();

    // Parse items JSON and format numeric fields
    foreach ($orders as &$order) {
        $order['id'] = (int)$order['id'];
        $order['customer_id'] = (int)$order['customer_id'];
        $order['store_id'] = (int)$order['store_id'];
        $order['subtotal'] = (float)$order['subtotal'];
        $order['delivery_fee'] = (float)$order['delivery_fee'];
        $order['total'] = (float)$order['total'];
        $order['is_past_due'] = (bool)$order['is_past_due'];
        $order['recurring_id'] = $order['recurring_id'] ? (int)$order['recurring_id'] : null;

        // Parse items JSONB
        if (isset($order['items'])) {
            $parsed = json_decode($order['items'], true);
            $order['items'] = is_array($parsed) ? $parsed : [];
        }
    }
    unset($order);

    // Summary stats
    $stmtStats = $db->prepare("
        SELECT
            COUNT(*) FILTER (WHERE status = 'agendado' AND scheduled_date >= CURRENT_DATE) as upcoming_count,
            COUNT(*) FILTER (WHERE status = 'agendado' AND scheduled_date < CURRENT_DATE) as past_due_count,
            COUNT(*) FILTER (WHERE status = 'concluido') as completed_count,
            COUNT(*) FILTER (WHERE status = 'cancelado') as cancelled_count
        FROM om_market_scheduled_orders
    ");
    $stmtStats->execute();
    $stats = $stmtStats->fetch();

    response(true, [
        'orders' => $orders,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => (int)ceil($total / max(1, $limit)),
        ],
        'stats' => [
            'upcoming' => (int)$stats['upcoming_count'],
            'past_due' => (int)$stats['past_due_count'],
            'completed' => (int)$stats['completed_count'],
            'cancelled' => (int)$stats['cancelled_count'],
        ],
    ], "Pedidos agendados listados");

} catch (Exception $e) {
    error_log("[admin/scheduled-orders-admin] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
