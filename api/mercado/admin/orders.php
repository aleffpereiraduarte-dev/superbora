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

    $status = $_GET['status'] ?? null;
    $search = $_GET['search'] ?? null;
    $date_from = $_GET['date_from'] ?? null;
    $date_to = $_GET['date_to'] ?? null;
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $sort = $_GET['sort'] ?? 'newest';
    $offset = ($page - 1) * $limit;

    $where = ["1=1"];
    $params = [];

    if ($status) {
        $where[] = "o.status = ?";
        $params[] = $status;
    }
    if ($search) {
        $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $search);
        $where[] = "(CAST(o.order_id AS TEXT) ILIKE ? OR c.name ILIKE ? OR p.name ILIKE ?)";
        $s = "%{$escaped}%";
        $params = array_merge($params, [$s, $s, $s]);
    }
    if ($date_from) {
        $where[] = "o.created_at >= ?";
        $params[] = $date_from . ' 00:00:00';
    }
    if ($date_to) {
        $where[] = "o.created_at <= ?";
        $params[] = $date_to . ' 23:59:59';
    }

    $order_by = match($sort) {
        'oldest' => 'o.created_at ASC',
        'highest' => 'o.total DESC',
        'lowest' => 'o.total ASC',
        default => 'o.created_at DESC'
    };

    $where_sql = implode(' AND ', $where);

    // Count
    $count_sql = "SELECT COUNT(*) as total FROM om_market_orders o
        LEFT JOIN om_customers c ON o.customer_id = c.customer_id
        LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
        WHERE {$where_sql}";
    $stmt = $db->prepare($count_sql);
    $stmt->execute($params);
    $total = (int)$stmt->fetch()['total'];

    // Fetch
    $sql = "SELECT o.order_id, o.status, o.total, o.delivery_fee, o.subtotal,
                   o.delivery_address, o.created_at, o.updated_at,
                   c.name as customer_name,
                   c.email as customer_email,
                   p.name as partner_name, p.partner_id,
                   s.name as shopper_name, s.shopper_id
            FROM om_market_orders o
            LEFT JOIN om_customers c ON o.customer_id = c.customer_id
            LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
            LEFT JOIN om_market_shoppers s ON o.shopper_id = s.shopper_id
            WHERE {$where_sql}
            ORDER BY {$order_by}
            LIMIT ? OFFSET ?";
    $stmt = $db->prepare($sql);
    $params[] = $limit;
    $params[] = $offset;
    $stmt->execute($params);
    $orders = $stmt->fetchAll();

    response(true, [
        'orders' => $orders,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => ceil($total / $limit)
        ]
    ], "Pedidos listados");
} catch (Exception $e) {
    error_log("[admin/orders] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
