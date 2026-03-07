<?php
/**
 * GET /api/mercado/customer/dashboard.php
 * Dashboard do cliente — resumo personalizado para tela inicial
 * Retorna: pedidos ativos, sugestoes de reorder, cashback, ofertas, stats
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $customerId = requireCustomerAuth();

    // 1. Active orders
    $stmt = $db->prepare("
        SELECT o.order_id, o.status, o.total, o.created_at, o.updated_at,
               m.nome as store_name, m.logo as store_logo
        FROM om_market_orders o
        JOIN om_mercados m ON m.mercado_id = o.mercado_id
        WHERE o.customer_id = ?
          AND o.status NOT IN ('entregue', 'cancelled', 'refunded')
        ORDER BY o.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$customerId]);
    $activeOrders = $stmt->fetchAll();

    // 2. Recent completed orders (for reorder suggestions)
    $stmt = $db->prepare("
        SELECT o.order_id, o.total, o.created_at,
               m.mercado_id, m.nome as store_name, m.logo as store_logo,
               (SELECT COUNT(*) FROM om_market_order_items WHERE order_id = o.order_id) as item_count
        FROM om_market_orders o
        JOIN om_mercados m ON m.mercado_id = o.mercado_id
        WHERE o.customer_id = ? AND o.status = 'entregue'
        ORDER BY o.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$customerId]);
    $reorderSuggestions = $stmt->fetchAll();

    // 3. Cashback balance
    $cashbackBalance = 0;
    try {
        $stmt = $db->prepare("SELECT saldo FROM om_cashback_balance WHERE customer_id = ?");
        $stmt->execute([$customerId]);
        $row = $stmt->fetch();
        $cashbackBalance = $row ? round((float)$row['saldo'], 2) : 0;
    } catch (Exception $e) {
        // Table may not exist
    }

    // 4. Available coupons (platform + favorite stores)
    $stmt = $db->prepare("
        SELECT c.id as coupon_id, c.code, c.tipo as type, c.valor as value,
               c.min_order, c.partner_id,
               CASE WHEN c.partner_id IS NOT NULL THEN m.nome ELSE NULL END as store_name
        FROM om_market_coupons c
        LEFT JOIN om_mercados m ON m.mercado_id = c.partner_id
        WHERE c.status = 1
          AND (c.expires_at IS NULL OR c.expires_at > NOW())
          AND (c.max_uses IS NULL OR c.times_used < c.max_uses)
          AND (c.partner_id IS NULL OR c.partner_id IN (
              SELECT DISTINCT mercado_id FROM om_market_orders
              WHERE customer_id = ? AND status = 'entregue'
          ))
        ORDER BY c.valor DESC
        LIMIT 5
    ");
    $stmt->execute([$customerId]);
    $availableCoupons = $stmt->fetchAll();

    // 5. Customer stats
    $stmt = $db->prepare("
        SELECT
            COUNT(*) as total_orders,
            COALESCE(SUM(CASE WHEN status = 'entregue' THEN total ELSE 0 END), 0) as total_spent,
            COUNT(DISTINCT mercado_id) as stores_visited,
            MAX(created_at) as last_order_at
        FROM om_market_orders
        WHERE customer_id = ?
    ");
    $stmt->execute([$customerId]);
    $stats = $stmt->fetch();

    // 6. Unread notifications count
    $unreadNotifs = 0;
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) as cnt FROM om_market_notifications
            WHERE customer_id = ? AND is_read = false
        ");
        $stmt->execute([$customerId]);
        $unreadNotifs = (int)$stmt->fetch()['cnt'];
    } catch (Exception $e) {
        // Table may not exist
    }

    // 7. Pending disputes/tickets
    $pendingIssues = 0;
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) as cnt FROM om_market_disputes
            WHERE customer_id = ? AND status IN ('aberta', 'em_analise')
        ");
        $stmt->execute([$customerId]);
        $pendingIssues = (int)$stmt->fetch()['cnt'];
    } catch (Exception $e) {}
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) as cnt FROM om_support_tickets
            WHERE customer_id = ? AND status IN ('aberto', 'em_andamento')
        ");
        $stmt->execute([$customerId]);
        $pendingIssues += (int)$stmt->fetch()['cnt'];
    } catch (Exception $e) {}

    // 8. Favorite stores (most ordered from)
    $stmt = $db->prepare("
        SELECT m.mercado_id, m.nome, m.logo, m.banner,
               COUNT(o.order_id) as order_count,
               MAX(o.created_at) as last_order
        FROM om_market_orders o
        JOIN om_mercados m ON m.mercado_id = o.mercado_id
        WHERE o.customer_id = ? AND o.status = 'entregue'
        GROUP BY m.mercado_id, m.nome, m.logo, m.banner
        ORDER BY order_count DESC
        LIMIT 5
    ");
    $stmt->execute([$customerId]);
    $favoriteStores = $stmt->fetchAll();

    // 9. Loyalty tier (based on total spent)
    $totalSpent = (float)($stats['total_spent'] ?? 0);
    $loyaltyTier = 'bronze';
    $loyaltyProgress = 0;
    if ($totalSpent >= 5000) {
        $loyaltyTier = 'diamante';
        $loyaltyProgress = 100;
    } elseif ($totalSpent >= 2000) {
        $loyaltyTier = 'ouro';
        $loyaltyProgress = round(($totalSpent - 2000) / 3000 * 100);
    } elseif ($totalSpent >= 500) {
        $loyaltyTier = 'prata';
        $loyaltyProgress = round(($totalSpent - 500) / 1500 * 100);
    } else {
        $loyaltyProgress = round($totalSpent / 500 * 100);
    }

    response(true, [
        'active_orders' => array_map(function($o) {
            return [
                'order_id' => (int)$o['order_id'],
                'status' => $o['status'],
                'total' => round((float)$o['total'], 2),
                'store_name' => $o['store_name'],
                'store_logo' => $o['store_logo'],
                'created_at' => $o['created_at'],
                'updated_at' => $o['updated_at'],
            ];
        }, $activeOrders),
        'reorder_suggestions' => array_map(function($o) {
            return [
                'order_id' => (int)$o['order_id'],
                'store_id' => (int)$o['mercado_id'],
                'store_name' => $o['store_name'],
                'store_logo' => $o['store_logo'],
                'total' => round((float)$o['total'], 2),
                'item_count' => (int)$o['item_count'],
                'ordered_at' => $o['created_at'],
            ];
        }, $reorderSuggestions),
        'cashback_balance' => $cashbackBalance,
        'available_coupons' => array_map(function($c) {
            return [
                'coupon_id' => (int)$c['coupon_id'],
                'code' => $c['code'],
                'type' => $c['type'],
                'value' => round((float)$c['value'], 2),
                'min_order' => round((float)($c['min_order'] ?? 0), 2),
                'store_name' => $c['store_name'],
            ];
        }, $availableCoupons),
        'stats' => [
            'total_orders' => (int)($stats['total_orders'] ?? 0),
            'total_spent' => round($totalSpent, 2),
            'stores_visited' => (int)($stats['stores_visited'] ?? 0),
            'last_order_at' => $stats['last_order_at'],
        ],
        'unread_notifications' => $unreadNotifs,
        'pending_issues' => $pendingIssues,
        'favorite_stores' => array_map(function($s) {
            return [
                'mercado_id' => (int)$s['mercado_id'],
                'nome' => $s['nome'],
                'logo' => $s['logo'],
                'order_count' => (int)$s['order_count'],
                'last_order' => $s['last_order'],
            ];
        }, $favoriteStores),
        'loyalty' => [
            'tier' => $loyaltyTier,
            'total_spent' => round($totalSpent, 2),
            'progress' => min(100, $loyaltyProgress),
            'next_tier' => $loyaltyTier === 'diamante' ? null : [
                'bronze' => ['name' => 'Prata', 'required' => 500],
                'prata' => ['name' => 'Ouro', 'required' => 2000],
                'ouro' => ['name' => 'Diamante', 'required' => 5000],
            ][$loyaltyTier] ?? null,
        ],
    ], "Dashboard do cliente");

} catch (Exception $e) {
    error_log("[customer/dashboard] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
