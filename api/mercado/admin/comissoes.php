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

    $partner_id = $_GET['partner_id'] ?? null;
    $period = $_GET['period'] ?? 'month';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;

    $date_filter = match($period) {
        'today' => "AND DATE(s.created_at) = CURRENT_DATE",
        'week' => "AND s.created_at >= CURRENT_DATE - INTERVAL '7 days'",
        'year' => "AND s.created_at >= CURRENT_DATE - INTERVAL '1 year'",
        default => "AND s.created_at >= CURRENT_DATE - INTERVAL '30 days'"
    };

    $where = "1=1 {$date_filter}";
    $params = [];

    if ($partner_id) {
        $where .= " AND s.partner_id = ?";
        $params[] = (int)$partner_id;
    }

    // Count
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM om_market_sales s WHERE {$where}");
    $stmt->execute($params);
    $total = (int)$stmt->fetch()['total'];

    // Save base params before adding limit/offset (for totals query)
    $baseParams = $params;

    // Fetch
    $stmt = $db->prepare("
        SELECT s.*, p.name as partner_name,
               o.order_id, o.total as order_total
        FROM om_market_sales s
        INNER JOIN om_market_partners p ON s.partner_id = p.partner_id
        LEFT JOIN om_market_orders o ON s.order_id = o.order_id
        WHERE {$where}
        ORDER BY s.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $params[] = (int)$limit;
    $params[] = (int)$offset;
    $stmt->execute($params);
    $comissoes = $stmt->fetchAll();

    // Totals (use baseParams without limit/offset)
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(commission), 0) as total_comissao,
               COALESCE(SUM(net_amount), 0) as total_net,
               COALESCE(SUM(amount), 0) as total_amount
        FROM om_market_sales s
        WHERE {$where}
    ");
    $stmt->execute($baseParams);
    $totals = $stmt->fetch();

    response(true, [
        'comissoes' => $comissoes,
        'totals' => $totals,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => ceil($total / $limit)
        ]
    ], "Comissoes listadas");
} catch (Exception $e) {
    error_log("[admin/comissoes] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
