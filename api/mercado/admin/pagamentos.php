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
    $admin_id = (int)$payload['uid'];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // POST: Payment resolution actions
        $input = getInput();
        $order_id = (int)($input['order_id'] ?? $input['payment_id'] ?? 0);
        $action = strip_tags(trim($input['action'] ?? ''));
        $notes = strip_tags(trim($input['notes'] ?? ''));

        if (!$order_id) response(false, null, "order_id ou payment_id obrigatorio", 400);
        $valid_actions = ['manual_confirm', 'cancel_payment', 'retry_payment', 'refund', 'force_resolve'];
        if (!in_array($action, $valid_actions)) response(false, null, "Acao invalida. Validas: " . implode(', ', $valid_actions), 400);

        $db->beginTransaction();

        $stmt = $db->prepare("SELECT * FROM om_market_orders WHERE order_id = ? FOR UPDATE");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch();
        if (!$order) { $db->rollBack(); response(false, null, "Pedido nao encontrado", 404); }

        $old_payment = $order['payment_status'] ?? 'unknown';
        $new_payment = $old_payment;
        $new_status = $order['status'];
        $msg = '';

        switch ($action) {
            case 'manual_confirm':
                $new_payment = 'paid';
                if ($order['status'] === 'pendente') $new_status = 'confirmado';
                $msg = "Pagamento confirmado manualmente pelo admin";
                break;
            case 'cancel_payment':
                $new_payment = 'cancelled';
                $new_status = 'cancelado';
                $msg = "Pagamento cancelado pelo admin";
                break;
            case 'retry_payment':
                $new_payment = 'pending';
                $msg = "Pagamento reenviado para processamento";
                break;
            case 'refund':
                $new_payment = 'refunded';
                $new_status = 'refunded';
                $msg = "Reembolso processado pelo admin";
                break;
            case 'force_resolve':
                $new_payment = 'paid';
                if (in_array($order['status'], ['pendente', 'stuck'])) $new_status = 'confirmado';
                $msg = "Pagamento resolvido forcadamente pelo admin";
                break;
        }

        if ($notes) $msg .= ". Obs: {$notes}";

        $stmt = $db->prepare("UPDATE om_market_orders SET payment_status = ?, status = ?, updated_at = NOW() WHERE order_id = ?");
        $stmt->execute([$new_payment, $new_status, $order_id]);

        // Timeline entry
        try {
            $stmt = $db->prepare("INSERT INTO om_order_timeline (order_id, status, description, actor_type, actor_id, created_at) VALUES (?, ?, ?, 'admin', ?, NOW())");
            $stmt->execute([$order_id, $new_status, $msg, $admin_id]);
        } catch (Exception $e) { /* timeline table may not exist */ }

        $db->commit();

        om_audit()->log('payment_resolve', 'order', $order_id,
            ['payment_status' => $old_payment, 'status' => $order['status']],
            ['payment_status' => $new_payment, 'status' => $new_status, 'action' => $action],
            $msg
        );

        response(true, [
            'order_id' => $order_id,
            'old_payment_status' => $old_payment,
            'new_payment_status' => $new_payment,
            'new_status' => $new_status,
        ], $msg);
    }

    // GET: List payments
    $id = (int)($_GET['id'] ?? 0);

    // Single payment detail by order ID
    if ($id > 0) {
        $stmt = $db->prepare("
            SELECT o.order_id as id, o.order_id, o.status, o.payment_status, o.total, o.subtotal,
                   o.delivery_fee, o.service_fee, o.forma_pagamento, o.created_at, o.updated_at,
                   c.name as customer_name, c.email as customer_email, c.phone as customer_phone,
                   p.name as partner_name
            FROM om_market_orders o
            LEFT JOIN om_customers c ON o.customer_id = c.customer_id
            LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
            WHERE o.order_id = ?
        ");
        $stmt->execute([$id]);
        $payment = $stmt->fetch();
        if (!$payment) response(false, null, "Pagamento nao encontrado", 404);
        $payment['id'] = (int)$payment['id'];
        $payment['total'] = (float)$payment['total'];
        $payment['subtotal'] = (float)$payment['subtotal'];
        $payment['delivery_fee'] = (float)$payment['delivery_fee'];
        $payment['service_fee'] = (float)$payment['service_fee'];
        response(true, $payment, "Detalhe do pagamento");
    }

    $type = $_GET['type'] ?? null;
    $status = $_GET['status'] ?? null;
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;

    $where = ["1=1"];
    $params = [];

    if ($status) {
        $where[] = "s.status = ?";
        $params[] = $status;
    }

    $where_sql = implode(' AND ', $where);

    // Count
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM om_market_sales s WHERE {$where_sql}");
    $stmt->execute($params);
    $total = (int)$stmt->fetch()['total'];

    // Fetch
    $stmt = $db->prepare("
        SELECT s.*, p.name as partner_name,
               o.order_id as ref_order_id, o.total as order_total,
               o.created_at as order_date
        FROM om_market_sales s
        INNER JOIN om_market_partners p ON s.partner_id = p.partner_id
        LEFT JOIN om_market_orders o ON s.order_id = o.order_id
        WHERE {$where_sql}
        ORDER BY s.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $params[] = (int)$limit;
    $params[] = (int)$offset;
    $stmt->execute($params);
    $pagamentos = $stmt->fetchAll();

    response(true, [
        'pagamentos' => $pagamentos,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => ceil($total / $limit)
        ]
    ], "Pagamentos listados");
} catch (Exception $e) {
    error_log("[admin/pagamentos] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
