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
    $is_online = $_GET['is_online'] ?? null;
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $where = ["1=1"];
    $params = [];

    if ($search) {
        $where[] = "(s.name LIKE ? OR s.email LIKE ? OR s.phone LIKE ? OR s.cpf LIKE ?)";
        $s = "%{$search}%";
        $params = array_merge($params, [$s, $s, $s, $s]);
    }
    if ($status !== null) {
        $where[] = "s.status = ?";
        $params[] = (int)$status;
    }
    if ($is_online !== null) {
        $where[] = "s.is_online = ?";
        $params[] = (int)$is_online;
    }

    $where_sql = implode(' AND ', $where);

    // Count
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM om_market_shoppers s WHERE {$where_sql}");
    $stmt->execute($params);
    $total = (int)$stmt->fetch()['total'];

    // Fetch
    $stmt = $db->prepare("
        SELECT s.shopper_id, s.name, s.email, s.phone, s.status, s.photo,
               s.rating, s.is_online, s.saldo, s.created_at,
               (SELECT COUNT(*) FROM om_market_orders o WHERE o.shopper_id = s.shopper_id) as total_orders,
               (SELECT COUNT(*) FROM om_market_orders o2 WHERE o2.shopper_id = s.shopper_id AND o2.status = 'entregue') as delivered_orders
        FROM om_market_shoppers s
        WHERE {$where_sql}
        ORDER BY s.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $params[] = (int)$limit;
    $params[] = (int)$offset;
    $stmt->execute($params);
    $shoppers = $stmt->fetchAll();

    response(true, [
        'shoppers' => $shoppers,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => ceil($total / $limit)
        ]
    ], "Shoppers listados");
} catch (Exception $e) {
    error_log("[admin/shoppers] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
