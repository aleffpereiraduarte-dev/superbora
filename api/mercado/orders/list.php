<?php
/**
 * GET /api/mercado/orders/list.php?status=X&page=1&limit=10
 * Lista pedidos do cliente autenticado
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Autenticacao necessaria", 401);
    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== 'customer') response(false, null, "Token invalido", 401);
    $customerId = (int)$payload['uid'];

    $status = preg_replace('/[^a-z_,]/', '', $_GET['status'] ?? '');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;

    $where = "o.customer_id = ?";
    $params = [$customerId];

    if (!empty($status)) {
        $statuses = explode(',', $status);
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $where .= " AND o.status IN ($placeholders)";
        $params = array_merge($params, $statuses);
    }

    // Count
    $stmtCount = $db->prepare("SELECT COUNT(*) FROM om_market_orders o WHERE $where");
    $stmtCount->execute($params);
    $total = (int)$stmtCount->fetchColumn();

    // Fetch
    $stmt = $db->prepare("
        SELECT o.order_id, o.order_number, o.partner_id, o.status, o.subtotal,
               o.delivery_fee, o.total, o.tip_amount, o.coupon_discount,
               o.forma_pagamento, o.payment_method, o.delivery_address, o.date_added,
               o.customer_name, o.is_pickup, o.schedule_date, o.schedule_time,
               o.items_count, o.partner_name,
               p.trade_name, p.logo as partner_logo, p.categoria as partner_category
        FROM om_market_orders o
        LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
        WHERE $where
        ORDER BY o.date_added DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute(array_merge($params, [$limit, $offset]));
    $orders = $stmt->fetchAll();

    $result = [];
    foreach ($orders as $o) {
        $result[] = [
            "id" => (int)$o['order_id'],
            "order_number" => $o['order_number'],
            "status" => $o['status'],
            "total" => (float)$o['total'],
            "subtotal" => (float)$o['subtotal'],
            "delivery_fee" => (float)$o['delivery_fee'],
            "tip" => (float)($o['tip_amount'] ?? 0),
            "coupon_discount" => (float)($o['coupon_discount'] ?? 0),
            "payment_method" => $o['forma_pagamento'] ?: $o['payment_method'],
            "address" => $o['delivery_address'],
            "is_pickup" => (bool)$o['is_pickup'],
            "date" => $o['date_added'],
            "schedule_date" => $o['schedule_date'],
            "schedule_time" => $o['schedule_time'],
            "items_count" => (int)($o['items_count'] ?? 0),
            "partner" => [
                "id" => (int)$o['partner_id'],
                "name" => $o['trade_name'] ?: $o['partner_name'] ?: '',
                "logo" => $o['partner_logo'],
                "category" => $o['partner_category']
            ]
        ];
    }

    response(true, [
        "orders" => $result,
        "pagination" => [
            "page" => $page,
            "limit" => $limit,
            "total" => $total,
            "pages" => (int)ceil($total / $limit)
        ]
    ]);

} catch (Exception $e) {
    error_log("[API Orders List] Erro: " . $e->getMessage());
    response(false, null, "Erro ao listar pedidos", 500);
}
