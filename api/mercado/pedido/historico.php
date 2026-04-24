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

    // Optional status filter (comma-separated) — whitelist valid values
    $statusFilter = trim($_GET["status"] ?? '');
    $allowedStatuses = ['pendente','confirmado','preparando','pronto','saiu_entrega','entregue','cancelado','recusado','aguardando_pagamento'];
    $statusList = [];
    if ($statusFilter) {
        $statusList = array_values(array_intersect(
            array_filter(array_map('trim', explode(',', $statusFilter))),
            $allowedStatuses
        ));
    }

    $cacheKey = "historico_pedidos_{$customer_id}_{$pagina}_{$limite}" . ($statusFilter ? "_st_" . md5($statusFilter) : "");

    $data = CacheHelper::remember($cacheKey, 30, function() use ($customer_id, $pagina, $limite, $offset, $statusList) {
        $db = getDB();

        // Build WHERE clause with optional status filter (parameterized)
        $params = [$customer_id];
        $statusClause = '';
        if (!empty($statusList)) {
            $statusClause = ' AND o.status IN (' . implode(',', array_fill(0, count($statusList), '?')) . ')';
            $params = array_merge($params, $statusList);
        }

        // Count total for pagination metadata
        $stmtCount = $db->prepare("SELECT COUNT(*) FROM om_market_orders o WHERE o.customer_id = ?" . $statusClause);
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
                WHERE o.customer_id = ?" . $statusClause . "
                ORDER BY o.date_added DESC
                LIMIT ? OFFSET ?");
        $stmt->execute(array_merge($params, [$limite, $offset]));
        $pedidos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Buscar itens de todos os pedidos retornados (IDs are from DB, not user input)
        $orderIds = array_column($pedidos, 'order_id');
        $itemsByOrder = [];
        if (!empty($orderIds)) {
            $inPlaceholders = implode(',', array_fill(0, count($orderIds), '?'));
            $stmtItems = $db->prepare(
                "SELECT order_id, product_id, product_name, quantity, price, product_image, unit
                FROM om_market_order_items
                WHERE order_id IN (" . $inPlaceholders . ")
                ORDER BY id ASC"
            );
            $stmtItems->execute(array_values($orderIds));
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

        // Partner status (ativa?) em 1 query pra todos os partners do batch
        $partnerIds = array_values(array_unique(array_column($pedidos, 'partner_id')));
        $partnerActive = [];
        if (!empty($partnerIds)) {
            $ph = implode(',', array_fill(0, count($partnerIds), '?'));
            $stmtP = $db->prepare("SELECT partner_id, status::text as st FROM om_market_partners WHERE partner_id IN ($ph)");
            $stmtP->execute($partnerIds);
            foreach ($stmtP->fetchAll() as $p) {
                $partnerActive[(int)$p['partner_id']] = ($p['st'] === '1');
            }
        }

        // Anexar itens + refund info + can_reorder a cada pedido
        foreach ($pedidos as &$pedido) {
            $items = $itemsByOrder[$pedido['order_id']] ?? [];
            $pedido['items'] = $items;

            // can_reorder respeitado pelo ReorderCarousel da home: só mostra
            // "Pedir de novo" se TEM items salvos + loja ativa + status terminal.
            $isTerminal = in_array($pedido['status'], ['entregue', 'cancelado', 'recusado', 'reembolsado'], true);
            $hasItems = count($items) > 0;
            $isPartnerActive = $partnerActive[(int)$pedido['partner_id']] ?? false;
            $pedido['can_reorder'] = $isTerminal && $hasItems && $isPartnerActive;

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
