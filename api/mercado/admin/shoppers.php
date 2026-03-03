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

    if ($_SERVER["REQUEST_METHOD"] === "GET" && !empty($_GET['shopper_id'])) {
        // Detail view for a specific shopper
        $shopper_id = (int)$_GET['shopper_id'];
        $stmt = $db->prepare("SELECT * FROM om_market_shoppers WHERE shopper_id = ?");
        $stmt->execute([$shopper_id]);
        $shopper = $stmt->fetch();
        if (!$shopper) response(false, null, "Shopper nao encontrado", 404);

        // Recent orders
        $stmt = $db->prepare("
            SELECT order_id, status, total, created_at, updated_at
            FROM om_market_orders WHERE shopper_id = ?
            ORDER BY created_at DESC LIMIT 20
        ");
        $stmt->execute([$shopper_id]);
        $recent_orders = $stmt->fetchAll();

        // Performance metrics
        $stmt = $db->prepare("
            SELECT COUNT(*) as total,
                   SUM(CASE WHEN status = 'entregue' THEN 1 ELSE 0 END) as delivered,
                   AVG(CASE WHEN status = 'entregue' THEN EXTRACT(EPOCH FROM (updated_at - created_at)) / 60 END) as avg_time
            FROM om_market_orders WHERE shopper_id = ?
        ");
        $stmt->execute([$shopper_id]);
        $perf = $stmt->fetch();

        // Earnings
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(CASE WHEN created_at >= CURRENT_DATE THEN amount ELSE 0 END), 0) as today,
                   COALESCE(SUM(CASE WHEN created_at >= DATE_TRUNC('week', CURRENT_DATE) THEN amount ELSE 0 END), 0) as this_week,
                   COALESCE(SUM(CASE WHEN created_at >= DATE_TRUNC('month', CURRENT_DATE) THEN amount ELSE 0 END), 0) as this_month
            FROM om_market_shopper_earnings WHERE shopper_id = ?
        ");
        // Table may not exist, handle gracefully
        try {
            $stmt->execute([$shopper_id]);
            $earnings = $stmt->fetch();
        } catch (Exception $e) {
            $earnings = ['today' => 0, 'this_week' => 0, 'this_month' => 0];
        }

        response(true, [
            'shopper' => $shopper,
            'recent_orders' => $recent_orders,
            'performance' => [
                'total_orders' => (int)$perf['total'],
                'delivered' => (int)$perf['delivered'],
                'completion_rate' => $perf['total'] > 0 ? round(($perf['delivered'] / $perf['total']) * 100, 1) : 0,
                'avg_delivery_minutes' => round((float)($perf['avg_time'] ?? 0), 1)
            ],
            'earnings' => $earnings
        ], "Detalhes do shopper");

    } elseif ($_SERVER["REQUEST_METHOD"] === "GET") {
        // List shoppers
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

        $stmt = $db->prepare("SELECT COUNT(*) as total FROM om_market_shoppers s WHERE {$where_sql}");
        $stmt->execute($params);
        $total = (int)$stmt->fetch()['total'];

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

        // Stats summary
        $stmt = $db->query("SELECT COUNT(*) as total FROM om_market_shoppers");
        $stats_total = (int)$stmt->fetch()['total'];
        $stmt = $db->query("SELECT COUNT(*) as c FROM om_market_shoppers WHERE status = '1'");
        $stats_active = (int)$stmt->fetch()['c'];
        $stmt = $db->query("SELECT COUNT(*) as c FROM om_market_shoppers WHERE is_online = 1 AND status = '1'");
        $stats_online = (int)$stmt->fetch()['c'];

        response(true, [
            'shoppers' => $shoppers,
            'stats' => [
                'total' => $stats_total,
                'active' => $stats_active,
                'online' => $stats_online,
                'suspended' => $stats_total - $stats_active
            ],
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => ceil($total / $limit)
            ]
        ], "Shoppers listados");

    } elseif ($_SERVER["REQUEST_METHOD"] === "POST") {
        $input = getInput();
        $action = $input['action'] ?? '';
        $shopper_id = (int)($input['shopper_id'] ?? 0);
        if (!$shopper_id) response(false, null, "shopper_id obrigatorio", 400);

        $allowed_actions = ['activate', 'suspend', 'block'];
        if (!in_array($action, $allowed_actions)) {
            response(false, null, "Acao invalida. Permitidas: " . implode(', ', $allowed_actions), 400);
        }

        $status_map = ['activate' => 1, 'suspend' => 0, 'block' => -1];
        $new_status = $status_map[$action];

        $stmt = $db->prepare("UPDATE om_market_shoppers SET status = ? WHERE shopper_id = ?");
        $stmt->execute([$new_status, $shopper_id]);

        if ($stmt->rowCount() === 0) response(false, null, "Shopper nao encontrado", 404);

        response(true, ['shopper_id' => $shopper_id, 'new_status' => $new_status], "Shopper atualizado");
    } else {
        response(false, null, "Metodo nao permitido", 405);
    }
} catch (Exception $e) {
    error_log("[admin/shoppers] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
