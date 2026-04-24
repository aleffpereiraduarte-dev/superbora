<?php
/**
 * GET /api/mercado/customer/orders.php
 * Returns customer orders (alias for orders/list.php with customer auth)
 * Used by suporte-ia.jsx
 *
 * Query params: limit, status (active|recent|all)
 */
require_once __DIR__ . "/../config/database.php";

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    response(false, null, "Metodo nao permitido", 405);
}

try {
    $db = getDB();
    $customerId = requireCustomerAuth();

    $limit = min(50, max(1, (int)($_GET['limit'] ?? 10)));
    $status = $_GET['status'] ?? 'all';

    $statusFilter = '';
    if ($status === 'active') {
        $statusFilter = "AND o.status NOT IN ('cancelado', 'entregue', 'retirado', 'refunded')";
    } elseif ($status === 'recent') {
        $statusFilter = "AND o.date_added > NOW() - INTERVAL '30 days'";
    }

    // Query principal + flags derivadas num único select:
    //  - items_count: quantos itens ainda estão no histórico (om_market_order_items)
    //  - partner_active: loja ainda está ativa (status = '1')
    //  Usamos LEFT JOIN LATERAL / subqueries pra evitar N+1.
    $stmt = $db->prepare("
        SELECT o.order_id, o.order_number, o.partner_id, o.status, o.total,
               o.delivery_fee, o.date_added, o.delivered_at,
               p.name as partner_name, p.trade_name, p.logo as partner_logo,
               p.status::text as partner_status,
               (SELECT COUNT(*) FROM om_market_order_items oi WHERE oi.order_id = o.order_id) as items_count
        FROM om_market_orders o
        LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
        WHERE o.customer_id = ? {$statusFilter}
        ORDER BY o.date_added DESC
        LIMIT ?
    ");
    $stmt->execute([$customerId, $limit]);
    $orders = $stmt->fetchAll();

    $result = [];
    foreach ($orders as $o) {
        $isTerminal = in_array($o['status'], ['entregue', 'cancelado', 'recusado', 'reembolsado'], true);
        $hasItems = (int)$o['items_count'] > 0;
        $partnerActive = ($o['partner_status'] === '1');
        // can_reorder = terminal + tem itens salvos + loja ativa. Antes era só
        // terminal, o que exibia "Pedir de novo" em pedidos sem produtos (api
        // retornava "Pedido sem itens") e pedidos de lojas descadastradas. Agora
        // o botão só aparece quando HÁ como repetir.
        $canReorder = $isTerminal && $hasItems && $partnerActive;
        $canTrack = !$isTerminal;
        $canRate = $o['status'] === 'entregue';

        $result[] = [
            'order_id' => (int)$o['order_id'],
            'order_number' => $o['order_number'],
            'partner_id' => (int)$o['partner_id'],
            'partner_name' => $o['trade_name'] ?: $o['partner_name'],
            'partner_logo' => $o['partner_logo'],
            'status' => $o['status'],
            'total' => (float)$o['total'],
            'delivery_fee' => (float)($o['delivery_fee'] ?? 0),
            'date' => $o['date_added'],
            'delivered_at' => $o['delivered_at'],
            'items_count' => (int)$o['items_count'],
            'partner_active' => $partnerActive,
            'can_reorder' => $canReorder,
            'can_track' => $canTrack,
            'can_rate' => $canRate,
        ];
    }

    response(true, ['orders' => $result, 'total' => count($result)]);

} catch (Exception $e) {
    error_log("[customer/orders] Erro: " . $e->getMessage());
    response(false, null, "Erro ao listar pedidos", 500);
}
