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

    $rating = $_GET['rating'] ?? null;
    $type = $_GET['type'] ?? null;
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;

    // Delivered orders as reviews proxy
    $where = ["o.status = 'entregue'"];
    $params = [];

    if ($type === 'shopper') {
        $where[] = "o.shopper_id IS NOT NULL";
    } elseif ($type === 'partner') {
        $where[] = "o.partner_id IS NOT NULL";
    }

    $where_sql = implode(' AND ', $where);

    $stmt = $db->prepare("SELECT COUNT(*) as total FROM om_market_orders o WHERE {$where_sql}");
    $stmt->execute($params);
    $total = (int)$stmt->fetch()['total'];

    $stmt = $db->prepare("
        SELECT o.order_id, o.total, o.created_at as order_date, o.updated_at as delivered_at,
               c.firstname as customer_name, c.lastname as customer_lastname,
               p.name as partner_name, p.partner_id,
               s.name as shopper_name, s.shopper_id, s.rating as shopper_rating
        FROM om_market_orders o
        LEFT JOIN oc_customer c ON o.customer_id = c.customer_id
        LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
        LEFT JOIN om_market_shoppers s ON o.shopper_id = s.shopper_id
        WHERE {$where_sql}
        ORDER BY o.updated_at DESC
        LIMIT ? OFFSET ?
    ");
    $params[] = (int)$limit;
    $params[] = (int)$offset;
    $stmt->execute($params);
    $avaliacoes = $stmt->fetchAll();

    response(true, [
        'avaliacoes' => $avaliacoes,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => ceil($total / $limit)
        ]
    ], "Avaliacoes listadas");
} catch (Exception $e) {
    error_log("[admin/avaliacoes] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
