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
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;

    $where = ["1=1"];
    $params = [];

    if ($status === 'in_transit') {
        $where[] = "o.status IN ('collecting', 'in_transit')";
    } elseif ($status === 'delivered_today') {
        $where[] = "o.status = 'entregue' AND DATE(o.updated_at) = CURRENT_DATE";
    } elseif ($status) {
        $where[] = "o.status = ?";
        $params[] = $status;
    } else {
        $where[] = "o.status IN ('collecting', 'in_transit', 'entregue')";
    }

    $where_sql = implode(' AND ', $where);

    $stmt = $db->prepare("SELECT COUNT(*) as total FROM om_market_orders o WHERE {$where_sql}");
    $stmt->execute($params);
    $total = (int)$stmt->fetch()['total'];

    $stmt = $db->prepare("
        SELECT o.order_id, o.status, o.total, o.delivery_fee, o.delivery_address,
               o.created_at, o.updated_at,
               c.firstname as customer_name, c.telephone as customer_phone,
               p.name as partner_name, p.address as partner_address,
               s.name as shopper_name, s.phone as shopper_phone
        FROM om_market_orders o
        LEFT JOIN oc_customer c ON o.customer_id = c.customer_id
        LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
        LEFT JOIN om_market_shoppers s ON o.shopper_id = s.shopper_id
        WHERE {$where_sql}
        ORDER BY o.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $params[] = (int)$limit;
    $params[] = (int)$offset;
    $stmt->execute($params);
    $entregas = $stmt->fetchAll();

    response(true, [
        'entregas' => $entregas,
        'pagination' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'pages' => ceil($total / $limit)]
    ], "Entregas listadas");
} catch (Exception $e) {
    error_log("[admin/entregas] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
