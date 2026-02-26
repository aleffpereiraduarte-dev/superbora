<?php
/**
 * GET /api/mercado/pedido/historico.php?customer_id=1
 * Histórico de pedidos do cliente
 * Otimizado com cache (TTL: 2 min) e prepared statements
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 2) . "/cache/CacheHelper.php";
setCorsHeaders();

try {
    $customer_id = requireCustomerAuth();
    $pagina = max(1, (int)($_GET["pagina"] ?? 1));
    $limite = min(50, max(1, (int)($_GET["limite"] ?? 20)));
    $offset = ($pagina - 1) * $limite;

    // Optional status filter (comma-separated)
    $statusFilter = trim($_GET["status"] ?? '');
    $statusList = [];
    if ($statusFilter) {
        $statusList = array_filter(array_map('trim', explode(',', $statusFilter)));
    }

    $cacheKey = "historico_pedidos_{$customer_id}_{$pagina}_{$limite}" . ($statusFilter ? "_st_" . md5($statusFilter) : "");

    $data = CacheHelper::remember($cacheKey, 30, function() use ($customer_id, $pagina, $limite, $offset, $statusList) {
        $db = getDB();

        // Build WHERE clause with optional status filter
        $where = "o.customer_id = ?";
        $params = [$customer_id];
        if (!empty($statusList)) {
            $placeholders = implode(',', array_fill(0, count($statusList), '?'));
            $where .= " AND o.status IN ($placeholders)";
            $params = array_merge($params, $statusList);
        }

        // Count total for pagination metadata
        $stmtCount = $db->prepare("SELECT COUNT(*) FROM om_market_orders o WHERE $where");
        $stmtCount->execute($params);
        $total = (int)$stmtCount->fetchColumn();

        $stmt = $db->prepare("SELECT o.order_id, o.order_number, o.partner_id, o.status, o.subtotal, o.delivery_fee, o.total,
                    o.tip_amount, o.service_fee, o.delivery_address, o.forma_pagamento, o.is_pickup,
                    o.schedule_date, o.schedule_time, o.date_added, o.accepted_at, o.ready_at,
                    o.delivered_at, o.cancelled_at, o.coupon_discount, o.loyalty_discount, o.discount,
                    o.delivery_type, o.partner_categoria,
                    o.cancel_reason, o.cancelled_by, o.payment_status, o.pagamento_status,
                    p.name as parceiro_nome, p.logo
                FROM om_market_orders o
                LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
                WHERE $where
                ORDER BY o.date_added DESC
                LIMIT ? OFFSET ?");
        $stmt->execute(array_merge($params, [$limite, $offset]));
        $pedidos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Buscar itens de todos os pedidos retornados
        $orderIds = array_column($pedidos, 'order_id');
        $itemsByOrder = [];
        if (!empty($orderIds)) {
            $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
            $stmtItems = $db->prepare("
                SELECT order_id, product_id, product_name, quantity, price, product_image, unit
                FROM om_market_order_items
                WHERE order_id IN ($placeholders)
                ORDER BY id ASC
            ");
            $stmtItems->execute($orderIds);
            $allItems = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

            foreach ($allItems as $item) {
                $itemsByOrder[$item['order_id']][] = [
                    'product_id' => (int)$item['product_id'],
                    'product_name' => $item['product_name'],
                    'quantity' => (int)$item['quantity'],
                    'price' => (float)$item['price'],
                    'product_image' => $item['product_image'],
                    'unit' => $item['unit'],
                ];
            }
        }

        // Anexar itens + refund info a cada pedido
        foreach ($pedidos as &$pedido) {
            $pedido['items'] = $itemsByOrder[$pedido['order_id']] ?? [];

            // Add refund status for cancelled orders
            $payStatus = $pedido['payment_status'] ?? $pedido['pagamento_status'] ?? '';
            $pedido['was_refunded'] = in_array($payStatus, ['refunded', 'estornado']);
            $pedido['refund_pending'] = ($pedido['status'] === 'cancelado')
                && in_array($payStatus, ['paid', 'pago', 'captured'])
                && !$pedido['was_refunded'];
        }
        unset($pedido);

        return [
            "pagina" => $pagina,
            "total" => $total,
            "total_pages" => (int)ceil($total / $limite),
            "por_pagina" => $limite,
            "pedidos" => $pedidos
        ];
    });

    response(true, $data);

} catch (Exception $e) {
    error_log("[pedido/historico] Erro: " . $e->getMessage());
    response(false, null, 'Erro interno do servidor', 500);
}
