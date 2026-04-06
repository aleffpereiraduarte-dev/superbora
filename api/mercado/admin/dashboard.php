<?php
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../config/cache.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();
    $admin_id = $payload['uid'];
    $role = $payload['data']['role'] ?? 'admin';

    // Cache dashboard stats por 30 segundos
    $dashboard_data = om_cache()->remember('admin_dashboard', 30, function() use ($db) {
        // Pedidos hoje
        $stmt = $db->query("SELECT COUNT(*) as total FROM om_market_orders WHERE DATE(created_at) = CURRENT_DATE");
        $pedidos_hoje = (int)$stmt->fetch()['total'];

        // Receita hoje
        $stmt = $db->query("
            SELECT COALESCE(SUM(total), 0) as total
            FROM om_market_orders
            WHERE DATE(created_at) = CURRENT_DATE AND status NOT IN ('cancelado', 'reembolsado')
        ");
        $receita_hoje = (float)$stmt->fetch()['total'];

        // Ticket medio hoje
        $ticket_medio = $pedidos_hoje > 0 ? round($receita_hoje / $pedidos_hoje, 2) : 0;

        // Taxa cancelamento hoje
        $stmt = $db->query("
            SELECT COUNT(*) as total,
                   SUM(CASE WHEN status = 'cancelado' THEN 1 ELSE 0 END) as cancelados
            FROM om_market_orders
            WHERE DATE(created_at) = CURRENT_DATE
        ");
        $cancel_row = $stmt->fetch();
        $taxa_cancelamento = $cancel_row['total'] > 0
            ? round(($cancel_row['cancelados'] / $cancel_row['total']) * 100, 1)
            : 0;

        // Pedidos ativos (em andamento)
        $stmt = $db->query("SELECT COUNT(*) as total FROM om_market_orders WHERE status IN ('pendente', 'aceito', 'preparando', 'pronto', 'em_entrega')");
        $pedidos_ativos = (int)$stmt->fetch()['total'];

        // Novos clientes hoje
        $stmt = $db->query("SELECT COUNT(*) as total FROM om_market_customers WHERE DATE(created_at) = CURRENT_DATE");
        $novos_clientes = (int)$stmt->fetch()['total'];

        // Clientes ativos (pediram nos ultimos 30 dias)
        $stmt = $db->query("
            SELECT COUNT(DISTINCT customer_id) as total
            FROM om_market_orders
            WHERE created_at >= CURRENT_DATE - INTERVAL '30 days'
        ");
        $clientes_ativos = (int)$stmt->fetch()['total'];

        // Shoppers online
        $stmt = $db->query("SELECT COUNT(*) as total FROM om_market_shoppers WHERE is_online = 1 AND status = '1'");
        $shoppers_online = (int)$stmt->fetch()['total'];

        // Tickets abertos
        $stmt = $db->query("SELECT COUNT(*) as total FROM om_support_tickets WHERE status IN ('open', 'in_progress')");
        $tickets_abertos = (int)$stmt->fetch()['total'];

        // Lojas abertas/total
        $stmt = $db->query("SELECT COUNT(*) as total, SUM(CASE WHEN is_open = 1 THEN 1 ELSE 0 END) as abertas FROM om_market_partners WHERE status = '1'");
        $lojas_row = $stmt->fetch();
        $lojas_total = (int)$lojas_row['total'];
        $lojas_abertas = (int)$lojas_row['abertas'];

        // Taxa de entrega (completed/total)
        $stmt = $db->query("
            SELECT COUNT(*) as total,
                   SUM(CASE WHEN status = 'entregue' THEN 1 ELSE 0 END) as completed
            FROM om_market_orders
            WHERE DATE(created_at) = CURRENT_DATE
        ");
        $taxa_row = $stmt->fetch();
        $taxa_entrega = $taxa_row['total'] > 0
            ? round(($taxa_row['completed'] / $taxa_row['total']) * 100, 1)
            : 0;

        // Pedidos recentes (ultimos 10)
        $stmt = $db->query("
            SELECT o.order_id, o.status, o.total, o.created_at,
                   c.name as customer_name,
                   p.name as partner_name,
                   s.name as shopper_name
            FROM om_market_orders o
            LEFT JOIN om_customers c ON o.customer_id = c.customer_id
            LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
            LEFT JOIN om_market_shoppers s ON o.shopper_id = s.shopper_id
            ORDER BY o.created_at DESC
            LIMIT 10
        ");
        $pedidos_recentes = $stmt->fetchAll();

        return [
            'stats' => [
                'pedidos_hoje' => $pedidos_hoje,
                'receita_hoje' => $receita_hoje,
                'ticket_medio' => $ticket_medio,
                'taxa_cancelamento' => $taxa_cancelamento,
                'pedidos_ativos' => $pedidos_ativos,
                'novos_clientes' => $novos_clientes,
                'clientes_ativos' => $clientes_ativos,
                'shoppers_online' => $shoppers_online,
                'tickets_abertos' => $tickets_abertos,
                'lojas_abertas' => $lojas_abertas,
                'lojas_total' => $lojas_total,
                'lojas_fechadas' => $lojas_total - $lojas_abertas,
                'taxa_entrega' => $taxa_entrega
            ],
            'pedidos_recentes' => $pedidos_recentes
        ];
    });

    response(true, $dashboard_data, "Dashboard carregado");
} catch (Exception $e) {
    error_log("[admin/dashboard] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
