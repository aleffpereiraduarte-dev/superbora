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
    $category = $_GET['category'] ?? null;
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;

    $where = ["1=1"];
    $params = [];

    if ($search) {
        $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $search);
        $where[] = "(p.name ILIKE ? OR p.email ILIKE ? OR p.cnpj ILIKE ?)";
        $s = "%{$escaped}%";
        $params = array_merge($params, [$s, $s, $s]);
    }
    if ($status !== null) {
        $where[] = "p.status = ?";
        $params[] = $status;
    }
    if ($category) {
        $where[] = "p.category = ?";
        $params[] = $category;
    }

    $where_sql = implode(' AND ', $where);

    $stmt = $db->prepare("SELECT COUNT(*) as total FROM om_market_partners p WHERE {$where_sql}");
    $stmt->execute($params);
    $total = (int)$stmt->fetch()['total'];

    $stmt = $db->prepare("
        SELECT p.partner_id, p.name, p.trade_name, p.email, p.phone, p.cnpj,
               p.logo, p.banner, p.category, p.description, p.status,
               p.is_open, p.opening_hours, p.delivery_fee, p.min_order,
               p.rating, p.rating_count, p.commission_rate,
               p.city, p.state, p.created_at, p.updated_at,
               (SELECT COUNT(*) FROM om_market_orders o WHERE o.partner_id = p.partner_id) as total_orders,
               (SELECT COALESCE(SUM(o2.total), 0) FROM om_market_orders o2
                WHERE o2.partner_id = p.partner_id AND o2.status NOT IN ('cancelled','refunded')) as total_revenue
        FROM om_market_partners p
        WHERE {$where_sql}
        ORDER BY p.name ASC
        LIMIT ? OFFSET ?
    ");
    $params[] = (int)$limit;
    $params[] = (int)$offset;
    $stmt->execute($params);
    $mercados = $stmt->fetchAll();

    response(true, [
        'mercados' => $mercados,
        'pagination' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'pages' => ceil($total / $limit)]
    ], "Mercados listados");
} catch (Exception $e) {
    error_log("[admin/mercados] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
