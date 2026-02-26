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
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $sort = $_GET['sort'] ?? 'newest';
    $offset = ($page - 1) * $limit;

    $where = ["1=1"];
    $params = [];

    if ($search) {
        $where[] = "(c.name LIKE ? OR c.email LIKE ? OR c.phone LIKE ?)";
        $s = "%{$search}%";
        $params = array_merge($params, [$s, $s, $s]);
    }
    if ($status !== null) {
        $where[] = "c.is_active = ?";
        $params[] = (int)$status;
    }

    $where_sql = implode(' AND ', $where);

    $order_by = match($sort) {
        'name' => 'c.name ASC',
        'orders' => 'orders_count DESC',
        'spent' => 'total_spent DESC',
        default => 'c.created_at DESC'
    };

    // Count
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM om_customers c WHERE {$where_sql}");
    $stmt->execute($params);
    $total = (int)$stmt->fetch()['total'];

    // Fetch
    $stmt = $db->prepare("
        SELECT c.customer_id, c.name, c.email, c.phone,
               c.is_active, c.created_at,
               (SELECT COUNT(*) FROM om_market_orders o WHERE o.customer_id = c.customer_id) as orders_count,
               (SELECT COALESCE(SUM(o2.total), 0) FROM om_market_orders o2
                WHERE o2.customer_id = c.customer_id AND o2.status NOT IN ('cancelled','refunded')) as total_spent
        FROM om_customers c
        WHERE {$where_sql}
        ORDER BY {$order_by}
        LIMIT ? OFFSET ?
    ");
    $params[] = $limit;
    $params[] = $offset;
    $stmt->execute($params);
    $clientes = $stmt->fetchAll();

    response(true, [
        'clientes' => $clientes,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => ceil($total / $limit)
        ]
    ], "Clientes listados");
} catch (Exception $e) {
    error_log("[admin/clientes] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
