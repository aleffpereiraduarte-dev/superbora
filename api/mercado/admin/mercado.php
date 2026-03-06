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

    $id = (int)($_GET['id'] ?? 0);
    $section = trim($_GET['section'] ?? '');
    if (!$id) response(false, null, "ID obrigatorio", 400);

    // Section-specific data
    if ($section === 'products') {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = 50;
        $offset = ($page - 1) * $limit;
        $search = trim($_GET['search'] ?? '');

        $where = ["p.partner_id = ?"];
        $params = [$id];
        if ($search) {
            $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $search);
            $where[] = "p.name ILIKE ?";
            $params[] = "%{$escaped}%";
        }
        $where_sql = implode(' AND ', $where);

        $stmt = $db->prepare("SELECT COUNT(*) as total FROM om_market_products p WHERE {$where_sql}");
        $stmt->execute($params);
        $total = (int)$stmt->fetch()['total'];

        $params[] = $limit;
        $params[] = $offset;
        $stmt = $db->prepare("
            SELECT p.product_id as id, p.name, p.price, p.special_price, p.quantity, p.status,
                   p.image, c.name as category_name
            FROM om_market_products p
            LEFT JOIN om_market_categories c ON p.category_id = c.category_id
            WHERE {$where_sql}
            ORDER BY p.name ASC LIMIT ? OFFSET ?
        ");
        $stmt->execute($params);
        response(true, ['products' => $stmt->fetchAll(), 'total' => $total], "Produtos do mercado");
    }

    if ($section === 'reviews') {
        $stmt = $db->prepare("
            SELECT r.id, r.rating, r.comment, r.created_at,
                   c.name as customer_name
            FROM om_market_reviews r
            LEFT JOIN om_customers c ON r.customer_id = c.customer_id
            WHERE r.partner_id = ?
            ORDER BY r.created_at DESC LIMIT 50
        ");
        $stmt->execute([$id]);
        $reviews = $stmt->fetchAll();

        $stmt = $db->prepare("SELECT AVG(rating) as avg_rating, COUNT(*) as total FROM om_market_reviews WHERE partner_id = ?");
        $stmt->execute([$id]);
        $stats = $stmt->fetch();

        response(true, ['reviews' => $reviews, 'avg_rating' => round((float)($stats['avg_rating'] ?? 0), 1), 'total' => (int)($stats['total'] ?? 0)], "Avaliacoes do mercado");
    }

    if ($section === 'financeiro') {
        // Repasses
        $stmt = $db->prepare("
            SELECT id, amount, status, period_start, period_end, created_at
            FROM om_market_repasses
            WHERE partner_id = ?
            ORDER BY created_at DESC LIMIT 20
        ");
        $stmt->execute([$id]);
        $repasses = $stmt->fetchAll();

        // Revenue summary
        $stmt = $db->prepare("
            SELECT COUNT(*) as total_orders,
                   COALESCE(SUM(total), 0) as total_revenue,
                   COALESCE(SUM(CASE WHEN status = 'refunded' THEN refund_amount ELSE 0 END), 0) as total_refunds
            FROM om_market_orders
            WHERE partner_id = ? AND status NOT IN ('cancelado', 'cancelled')
        ");
        $stmt->execute([$id]);
        $revenue = $stmt->fetch();

        response(true, [
            'repasses' => $repasses,
            'revenue' => [
                'total_orders' => (int)$revenue['total_orders'],
                'total_revenue' => (float)$revenue['total_revenue'],
                'total_refunds' => (float)$revenue['total_refunds'],
            ]
        ], "Financeiro do mercado");
    }

    // Default: full market detail
    $stmt = $db->prepare("
        SELECT partner_id, name, nome, email, phone, telefone, cnpj,
               address, endereco, city, state, neighborhood, cep,
               categoria, status, logo, banner, description,
               commission_rate, commission_type, partnership_type,
               opening_hours, delivery_radius, min_order, avg_prep_time,
               rating, total_orders, total_vendas,
               date_added as created_at, date_modified as updated_at
        FROM om_market_partners WHERE partner_id = ?
    ");
    $stmt->execute([$id]);
    $market = $stmt->fetch();
    if (!$market) response(false, null, "Mercado nao encontrado", 404);

    // Products count
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM om_market_products WHERE partner_id = ?");
    $stmt->execute([$id]);
    $products_count = (int)$stmt->fetch()['total'];

    // Order stats
    $stmt = $db->prepare("
        SELECT COUNT(*) as total_orders,
               COALESCE(SUM(CASE WHEN status NOT IN ('cancelled','refunded') THEN total ELSE 0 END), 0) as total_revenue,
               COALESCE(AVG(CASE WHEN status NOT IN ('cancelled','refunded') THEN total END), 0) as avg_order
        FROM om_market_orders WHERE partner_id = ?
    ");
    $stmt->execute([$id]);
    $order_stats = $stmt->fetch();

    // Recent orders
    $stmt = $db->prepare("
        SELECT o.order_id, o.status, o.total, o.created_at,
               c.name as customer_name
        FROM om_market_orders o
        LEFT JOIN om_customers c ON o.customer_id = c.customer_id
        WHERE o.partner_id = ?
        ORDER BY o.created_at DESC LIMIT 10
    ");
    $stmt->execute([$id]);
    $recent_orders = $stmt->fetchAll();

    response(true, [
        'market' => $market,
        'products_count' => $products_count,
        'order_stats' => $order_stats,
        'recent_orders' => $recent_orders
    ], "Detalhes do mercado");
} catch (Exception $e) {
    error_log("[admin/mercado] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
