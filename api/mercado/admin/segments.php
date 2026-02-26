<?php
/**
 * GET /api/mercado/admin/segments.php
 * Returns customer segments with counts for marketing targeting
 *
 * Segments:
 * - new_users: Registered < 7 days, 0 orders
 * - first_order: Exactly 1 delivered order
 * - regular: 3+ orders in last 30 days
 * - inactive: No orders in 30+ days
 * - vip: 10+ orders OR R$500+ total spent
 * - at_risk: Was regular (3+ orders in prev 30-60 day window), no orders in 14+ days
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";
require_once dirname(__DIR__, 2) . "/cache/CacheHelper.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();

    $data = CacheHelper::remember("admin_segments", 300, function() use ($db) {
        $segments = [];

        // Total customers (baseline)
        $stmt = $db->query("SELECT COUNT(*) as cnt FROM om_customers");
        $totalCustomers = (int)$stmt->fetch()['cnt'];

        // 1. new_users: Registered < 7 days ago, 0 orders
        $stmt = $db->query("
            SELECT COUNT(*) as cnt FROM om_customers c
            WHERE c.created_at >= CURRENT_DATE - INTERVAL '7 days'
            AND NOT EXISTS (
                SELECT 1 FROM om_market_orders o
                WHERE o.customer_id = c.customer_id
                AND o.status IN ('entregue','finalizado')
            )
        ");
        $segments[] = [
            'key' => 'new_users',
            'label' => 'Novos Usuarios',
            'description' => 'Registrados ha menos de 7 dias, sem pedidos',
            'count' => (int)$stmt->fetch()['cnt'],
            'icon' => 'user-plus',
        ];

        // 2. first_order: Exactly 1 delivered order
        $stmt = $db->query("
            SELECT COUNT(*) as cnt FROM (
                SELECT o.customer_id, COUNT(*) as order_count
                FROM om_market_orders o
                WHERE o.status IN ('entregue','finalizado')
                GROUP BY o.customer_id
                HAVING COUNT(*) = 1
            ) sub
        ");
        $segments[] = [
            'key' => 'first_order',
            'label' => 'Primeiro Pedido',
            'description' => 'Fizeram exatamente 1 pedido entregue',
            'count' => (int)$stmt->fetch()['cnt'],
            'icon' => 'shopping-bag',
        ];

        // 3. regular: 3+ orders in last 30 days
        $stmt = $db->query("
            SELECT COUNT(*) as cnt FROM (
                SELECT o.customer_id, COUNT(*) as order_count
                FROM om_market_orders o
                WHERE o.status IN ('entregue','finalizado')
                AND o.date_added >= CURRENT_DATE - INTERVAL '30 days'
                GROUP BY o.customer_id
                HAVING COUNT(*) >= 3
            ) sub
        ");
        $segments[] = [
            'key' => 'regular',
            'label' => 'Regulares',
            'description' => '3+ pedidos nos ultimos 30 dias',
            'count' => (int)$stmt->fetch()['cnt'],
            'icon' => 'repeat',
        ];

        // 4. inactive: No orders in 30+ days (but has ordered before)
        $stmt = $db->query("
            SELECT COUNT(*) as cnt FROM (
                SELECT o.customer_id, MAX(o.date_added) as last_order
                FROM om_market_orders o
                WHERE o.status IN ('entregue','finalizado')
                GROUP BY o.customer_id
                HAVING MAX(o.date_added) < CURRENT_DATE - INTERVAL '30 days'
            ) sub
        ");
        $segments[] = [
            'key' => 'inactive',
            'label' => 'Inativos',
            'description' => 'Sem pedidos ha mais de 30 dias',
            'count' => (int)$stmt->fetch()['cnt'],
            'icon' => 'moon',
        ];

        // 5. vip: 10+ orders OR R$500+ total spent
        $stmt = $db->query("
            SELECT COUNT(*) as cnt FROM (
                SELECT o.customer_id,
                       COUNT(*) as order_count,
                       SUM(o.total) as total_spent
                FROM om_market_orders o
                WHERE o.status IN ('entregue','finalizado')
                GROUP BY o.customer_id
                HAVING COUNT(*) >= 10 OR SUM(o.total) >= 500
            ) sub
        ");
        $segments[] = [
            'key' => 'vip',
            'label' => 'VIP',
            'description' => '10+ pedidos ou R$500+ gastos',
            'count' => (int)$stmt->fetch()['cnt'],
            'icon' => 'star',
        ];

        // 6. at_risk: Was regular (3+ orders in 30-60 day window), no orders in 14+ days
        $stmt = $db->query("
            SELECT COUNT(*) as cnt FROM (
                SELECT o.customer_id,
                       SUM(CASE WHEN o.date_added >= CURRENT_DATE - INTERVAL '60 days'
                                 AND o.date_added < CURRENT_DATE - INTERVAL '30 days' THEN 1 ELSE 0 END) as prev_orders,
                       MAX(o.date_added) as last_order
                FROM om_market_orders o
                WHERE o.status IN ('entregue','finalizado')
                GROUP BY o.customer_id
                HAVING SUM(CASE WHEN o.date_added >= CURRENT_DATE - INTERVAL '60 days'
                                 AND o.date_added < CURRENT_DATE - INTERVAL '30 days' THEN 1 ELSE 0 END) >= 3
                   AND MAX(o.date_added) < CURRENT_DATE - INTERVAL '14 days'
            ) sub
        ");
        $segments[] = [
            'key' => 'at_risk',
            'label' => 'Em Risco',
            'description' => 'Eram regulares, sem pedido ha 14+ dias',
            'count' => (int)$stmt->fetch()['cnt'],
            'icon' => 'alert-triangle',
        ];

        return [
            'total_customers' => $totalCustomers,
            'segments' => $segments,
        ];
    });

    response(true, $data, "Segmentos carregados");

} catch (Exception $e) {
    error_log("[admin/segments] Erro: " . $e->getMessage());
    response(false, null, "Erro ao carregar segmentos", 500);
}
