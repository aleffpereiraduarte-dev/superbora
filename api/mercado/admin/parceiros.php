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
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;

    $where = ["p.status = '0'"];
    $params = [];

    if ($search) {
        $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $search);
        $where[] = "(p.name ILIKE ? OR p.email ILIKE ? OR p.cnpj ILIKE ?)";
        $s = "%{$escaped}%";
        $params = array_merge($params, [$s, $s, $s]);
    }

    $where_sql = implode(' AND ', $where);

    $stmt = $db->prepare("SELECT COUNT(*) as total FROM om_market_partners p WHERE {$where_sql}");
    $stmt->execute($params);
    $total = (int)$stmt->fetch()['total'];

    $stmt = $db->prepare("
        SELECT p.partner_id, p.name, p.trade_name, p.email, p.phone, p.cnpj,
               p.address, p.city, p.state, p.neighborhood, p.zip_code,
               p.logo, p.banner, p.category, p.description, p.status,
               p.is_open, p.opening_hours, p.delivery_fee, p.min_order,
               p.rating, p.rating_count, p.commission_rate,
               p.created_at, p.updated_at
        FROM om_market_partners p
        WHERE {$where_sql}
        ORDER BY p.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $params[] = (int)$limit;
    $params[] = (int)$offset;
    $stmt->execute($params);
    $parceiros = $stmt->fetchAll();

    response(true, [
        'parceiros' => $parceiros,
        'pagination' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'pages' => ceil($total / $limit)]
    ], "Parceiros pendentes de aprovacao");
} catch (Exception $e) {
    error_log("[admin/parceiros] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
