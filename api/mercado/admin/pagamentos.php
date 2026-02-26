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

    $type = $_GET['type'] ?? null;
    $status = $_GET['status'] ?? null;
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;

    $where = ["1=1"];
    $params = [];

    if ($status) {
        $where[] = "s.status = ?";
        $params[] = $status;
    }

    $where_sql = implode(' AND ', $where);

    // Count
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM om_market_sales s WHERE {$where_sql}");
    $stmt->execute($params);
    $total = (int)$stmt->fetch()['total'];

    // Fetch
    $stmt = $db->prepare("
        SELECT s.*, p.name as partner_name,
               o.order_id as ref_order_id, o.total as order_total,
               o.created_at as order_date
        FROM om_market_sales s
        INNER JOIN om_market_partners p ON s.partner_id = p.partner_id
        LEFT JOIN om_market_orders o ON s.order_id = o.order_id
        WHERE {$where_sql}
        ORDER BY s.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $params[] = (int)$limit;
    $params[] = (int)$offset;
    $stmt->execute($params);
    $pagamentos = $stmt->fetchAll();

    response(true, [
        'pagamentos' => $pagamentos,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => ceil($total / $limit)
        ]
    ], "Pagamentos listados");
} catch (Exception $e) {
    error_log("[admin/pagamentos] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
