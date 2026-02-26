<?php
/**
 * GET /api/mercado/partner/orders.php
 * Orders list for partner with advanced filtering
 * Params: status, date_from, date_to, page (default 1), limit (default 20),
 *         search, payment_method, delivery_type, sort
 * Status values: pendente, confirmado, preparando, pronto, coletado, em_entrega, entregue, cancelado, aceito
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requirePartner();
    $partner_id = (int)$payload['uid'];

    $status = trim($_GET['status'] ?? '');
    $date_from = trim($_GET['date_from'] ?? '');
    $date_to = trim($_GET['date_to'] ?? '');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    // New filters
    $search = trim($_GET['search'] ?? '');
    $payment_method = trim($_GET['payment_method'] ?? '');
    $delivery_type = trim($_GET['delivery_type'] ?? '');
    $sort = trim($_GET['sort'] ?? 'newest');

    // Validar status
    $validStatuses = ['pendente', 'confirmado', 'preparando', 'pronto', 'coletado', 'em_entrega', 'entregue', 'cancelado', 'aceito'];

    $where = ["o.partner_id = ?"];
    $params = [$partner_id];

    if ($status !== '' && in_array($status, $validStatuses)) {
        $where[] = "o.status = ?";
        $params[] = $status;
    }

    if ($date_from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
        $where[] = "DATE(o.date_added) >= ?";
        $params[] = $date_from;
    }

    if ($date_to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
        $where[] = "DATE(o.date_added) <= ?";
        $params[] = $date_to;
    }

    // Search: by order_number or customer_name
    if ($search !== '') {
        $searchSafe = str_replace(['%', '_'], ['\\%', '\\_'], substr($search, 0, 100));
        $where[] = "(o.order_number ILIKE ? OR o.customer_name ILIKE ?)";
        $params[] = "%{$searchSafe}%";
        $params[] = "%{$searchSafe}%";
    }

    // Payment method filter
    if ($payment_method !== '') {
        $where[] = "o.forma_pagamento = ?";
        $params[] = $payment_method;
    }

    // Delivery type filter
    if ($delivery_type === 'pickup') {
        $where[] = "o.is_pickup = 1";
    } elseif ($delivery_type === 'delivery') {
        $where[] = "(o.is_pickup = 0 OR o.is_pickup IS NULL)";
    }

    $whereSQL = implode(" AND ", $where);

    // Sort options
    $orderBySQL = match($sort) {
        'oldest' => 'o.date_added ASC',
        'highest' => 'o.total DESC',
        'lowest' => 'o.total ASC',
        'priority' => "array_position(ARRAY['pendente','aceito','confirmado','preparando','pronto','saiu_entrega','em_entrega'], o.status), o.date_added ASC",
        default => 'o.date_added DESC', // newest
    };

    // Count
    $stmtCount = $db->prepare("SELECT COUNT(*) FROM om_market_orders o WHERE {$whereSQL}");
    $stmtCount->execute($params);
    $total = (int)$stmtCount->fetchColumn();

    // Fetch orders
    $stmt = $db->prepare("
        SELECT
            o.order_id,
            o.order_number,
            o.customer_id,
            o.customer_name,
            o.customer_phone,
            o.shopper_id,
            o.status,
            o.subtotal,
            o.delivery_fee,
            o.total,
            o.forma_pagamento,
            o.delivery_address,
            o.notes,
            o.is_pickup,
            o.date_added,
            o.date_modified,
            o.schedule_date,
            o.schedule_time,
            s.name as shopper_name,
            s.phone as shopper_phone,
            (SELECT COUNT(*) FROM om_market_order_items WHERE order_id = o.order_id) as total_items
        FROM om_market_orders o
        LEFT JOIN om_market_shoppers s ON o.shopper_id = s.shopper_id
        WHERE {$whereSQL}
        ORDER BY {$orderBySQL}
        LIMIT ? OFFSET ?
    ");
    $stmt->execute(array_merge($params, [$limit, $offset]));
    $orders = $stmt->fetchAll();

    $items = [];
    foreach ($orders as $order) {
        $items[] = [
            "order_id" => (int)$order['order_id'],
            "order_number" => $order['order_number'],
            "customer_id" => (int)$order['customer_id'],
            "customer_name" => $order['customer_name'],
            "customer_phone" => $order['customer_phone'],
            "shopper_id" => $order['shopper_id'] ? (int)$order['shopper_id'] : null,
            "shopper_name" => $order['shopper_name'],
            "status" => $order['status'],
            "subtotal" => (float)$order['subtotal'],
            "delivery_fee" => (float)$order['delivery_fee'],
            "total" => (float)$order['total'],
            "payment_method" => $order['forma_pagamento'],
            "address" => $order['delivery_address'],
            "notes" => $order['notes'],
            "is_pickup" => (bool)$order['is_pickup'],
            "total_items" => (int)$order['total_items'],
            "date" => $order['date_added'],
            "date_modified" => $order['date_modified'],
            "schedule_date" => $order['schedule_date'],
            "schedule_time" => $order['schedule_time']
        ];
    }

    $pages = $total > 0 ? ceil($total / $limit) : 1;

    response(true, [
        "items" => $items,
        "pagination" => [
            "total" => $total,
            "page" => $page,
            "pages" => (int)$pages,
            "limit" => $limit
        ]
    ], "Pedidos listados");

} catch (Exception $e) {
    error_log("[partner/orders] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
