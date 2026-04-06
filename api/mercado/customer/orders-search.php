<?php
/**
 * GET /api/mercado/customer/orders-search.php?q=pizza&from=2026-01-01&to=2026-04-04&limit=20&page=1
 *
 * Server-side order history search.
 * Searches across: store name, order number, and product names.
 * Requires customer authentication.
 */
require_once __DIR__ . "/../config/database.php";
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    response(false, null, "Metodo nao permitido", 405);
}

try {
    $customerId = requireCustomerAuth();

    $q = trim($_GET['q'] ?? '');
    $from = trim($_GET['from'] ?? '');
    $to = trim($_GET['to'] ?? '');
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $offset = ($page - 1) * $limit;

    $db = getDB();

    // Build dynamic WHERE clause
    $params = [$customerId];
    $conditions = ["o.customer_id = ?"];

    // Date range filters
    if ($from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $conditions[] = "o.date_added >= ?";
        $params[] = $from . ' 00:00:00';
    }
    if ($to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $conditions[] = "o.date_added <= ?";
        $params[] = $to . ' 23:59:59';
    }

    // Search query — match against store name, order number, or product names
    $searchJoin = '';
    if ($q !== '') {
        $searchPattern = '%' . mb_strtolower($q) . '%';
        $searchJoin = "LEFT JOIN om_market_order_items oi_search ON oi_search.order_id = o.order_id";
        $conditions[] = "(
            LOWER(p.name) LIKE ? OR
            LOWER(COALESCE(p.trade_name, '')) LIKE ? OR
            LOWER(CAST(o.order_number AS TEXT)) LIKE ? OR
            LOWER(COALESCE(oi_search.product_name, '')) LIKE ?
        )";
        $params[] = $searchPattern;
        $params[] = $searchPattern;
        $params[] = $searchPattern;
        $params[] = $searchPattern;
    }

    $where = implode(' AND ', $conditions);

    // Count total matches (DISTINCT because of item join)
    $countSql = "SELECT COUNT(DISTINCT o.order_id)
        FROM om_market_orders o
        LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
        {$searchJoin}
        WHERE {$where}";
    $stmtCount = $db->prepare($countSql);
    $stmtCount->execute($params);
    $total = (int)$stmtCount->fetchColumn();

    // Fetch matching orders
    $sql = "SELECT DISTINCT o.order_id, o.order_number, o.partner_id, o.status,
                o.subtotal, o.delivery_fee, o.total, o.tip_amount, o.service_fee,
                o.delivery_address, o.forma_pagamento, o.is_pickup,
                o.schedule_date, o.schedule_time, o.date_added,
                o.accepted_at, o.ready_at, o.delivered_at, o.cancelled_at,
                o.coupon_discount, o.discount, o.delivery_type,
                o.cancel_reason, o.cancelled_by, o.payment_status,
                p.name as parceiro_nome, p.logo
            FROM om_market_orders o
            LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
            {$searchJoin}
            WHERE {$where}
            ORDER BY o.date_added DESC
            LIMIT ? OFFSET ?";
    $paramsWithPagination = array_merge($params, [$limit, $offset]);
    $stmt = $db->prepare($sql);
    $stmt->execute($paramsWithPagination);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Bulk-fetch items for all matched orders
    $orderIds = array_column($orders, 'order_id');
    $itemsByOrder = [];
    if (!empty($orderIds)) {
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $stmtItems = $db->prepare("
            SELECT order_id, product_id, product_name, quantity, price, product_image, unit
            FROM om_market_order_items
            WHERE order_id IN ({$placeholders})
            ORDER BY id ASC
        ");
        $stmtItems->execute(array_values($orderIds));
        foreach ($stmtItems->fetchAll(PDO::FETCH_ASSOC) as $item) {
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

    // Attach items to orders
    foreach ($orders as &$order) {
        $order['items'] = $itemsByOrder[$order['order_id']] ?? [];
    }
    unset($order);

    response(true, [
        'orders' => $orders,
        'total' => $total,
        'page' => $page,
        'total_pages' => (int)ceil($total / $limit),
        'per_page' => $limit,
        'query' => $q,
    ]);

} catch (Exception $e) {
    error_log("[customer/orders-search] Erro: " . $e->getMessage());
    response(false, null, 'Erro interno do servidor', 500);
}
