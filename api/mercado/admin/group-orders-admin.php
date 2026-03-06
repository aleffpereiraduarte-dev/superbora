<?php
/**
 * GET /api/mercado/admin/group-orders-admin.php
 *
 * Lista pedidos em grupo para o painel administrativo.
 *
 * Query: ?status=active&page=1&limit=20&search=
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
        $where[] = "g.status = ?";
        $params[] = $status;
    }

    // Search by share_code or creator name
    if ($search !== '') {
        $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $search);
        $where[] = "(g.share_code ILIKE ? OR c.name ILIKE ?)";
        $s = "%{$escaped}%";
        $params[] = $s;
        $params[] = $s;
    }

    $where_sql = implode(' AND ', $where);

    // Count total
    $stmt = $db->prepare("
        SELECT COUNT(*) as total
        FROM om_market_group_orders g
        LEFT JOIN om_customers c ON g.creator_id = c.customer_id
        WHERE {$where_sql}
    ");
    $stmt->execute($params);
    $total = (int)$stmt->fetch()['total'];

    // Fetch groups with creator info, participant count, and total amount
    $stmt = $db->prepare("
        SELECT g.id, g.creator_id, g.partner_id, g.share_code, g.status,
               g.expires_at, g.created_at, g.updated_at,
               c.name as creator_name, c.email as creator_email, c.phone as creator_phone,
               p.name as partner_name,
               (SELECT COUNT(*) FROM om_market_group_order_participants gp WHERE gp.group_order_id = g.id) as participant_count,
               (SELECT COALESCE(SUM(gi.price * gi.quantity), 0) FROM om_market_group_order_items gi WHERE gi.group_order_id = g.id) as total_amount
        FROM om_market_group_orders g
        LEFT JOIN om_customers c ON g.creator_id = c.customer_id
        LEFT JOIN om_market_partners p ON g.partner_id = p.partner_id
        WHERE {$where_sql}
        ORDER BY g.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $params[] = $limit;
    $params[] = $offset;
    $stmt->execute($params);
    $groups = $stmt->fetchAll();

    // Format numeric fields
    foreach ($groups as &$group) {
        $group['id'] = (int)$group['id'];
        $group['creator_id'] = (int)$group['creator_id'];
        $group['partner_id'] = (int)$group['partner_id'];
        $group['participant_count'] = (int)$group['participant_count'];
        $group['total_amount'] = (float)$group['total_amount'];
    }
    unset($group);

    response(true, [
        'groups' => $groups,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => (int)ceil($total / max(1, $limit)),
        ],
    ], "Pedidos em grupo listados");

} catch (Exception $e) {
    error_log("[admin/group-orders-admin] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
