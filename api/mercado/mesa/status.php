<?php
/**
 * GET /api/mercado/mesa/status.php?order_id=X&table_code=Y
 *
 * Public endpoint to check table order status.
 * No auth required, but validates order_id belongs to the given table_code.
 * Used by the mesa page to poll for PIX confirmation and order updates.
 */

require_once __DIR__ . "/../config/database.php";
setCorsHeaders();

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    response(false, null, "Metodo nao permitido", 405);
}

try {
    $db = getDB();

    $orderId = (int)($_GET['order_id'] ?? 0);
    $tableCode = trim($_GET['table_code'] ?? '');

    if ($orderId <= 0) {
        response(false, null, "order_id e obrigatorio", 400);
    }
    if (empty($tableCode)) {
        response(false, null, "table_code e obrigatorio", 400);
    }

    // Sanitize table code
    $tableCode = substr(preg_replace('/[^a-zA-Z0-9_\-]/', '', $tableCode), 0, 200);

    // Resolve table ID from code
    $tableId = 0;
    if (preg_match('/^(\d+)-(\d+)$/', $tableCode, $matches)) {
        $partnerId = (int)$matches[1];
        $tableNumero = (int)$matches[2];
        $stmt = dbQuery($db,
            "SELECT id FROM om_market_qr_tables WHERE partner_id = ? AND numero = ? AND ativo = true LIMIT 1",
            [$partnerId, $tableNumero]
        );
        $row = $stmt->fetch();
        if ($row) $tableId = (int)$row['id'];
    }

    if (!$tableId && ctype_digit($tableCode)) {
        $tableId = (int)$tableCode;
    }

    if ($tableId <= 0) {
        response(false, null, "Mesa nao encontrada", 404);
    }

    // Fetch order - verify it belongs to this table
    $stmt = dbQuery($db,
        "SELECT o.order_id, o.order_number, o.status, o.subtotal, o.total,
                o.forma_pagamento, o.payment_status, o.pix_code, o.pix_qr_code,
                o.customer_name, o.date_added, o.table_id
         FROM om_market_orders o
         WHERE o.order_id = ? AND o.table_id = ?
         LIMIT 1",
        [$orderId, $tableId]
    );
    $order = $stmt->fetch();

    if (!$order) {
        response(false, null, "Pedido nao encontrado para esta mesa", 404);
    }

    // Fetch order items
    $stmtItems = dbQuery($db,
        "SELECT oi.name, oi.quantity, oi.price, oi.total
         FROM om_market_order_items oi
         WHERE oi.order_id = ?
         ORDER BY oi.id",
        [$orderId]
    );
    $items = $stmtItems->fetchAll();

    $responseData = [
        'order_id' => (int)$order['order_id'],
        'order_number' => $order['order_number'],
        'status' => $order['status'],
        'total' => round((float)$order['total'], 2),
        'subtotal' => round((float)$order['subtotal'], 2),
        'payment_method' => $order['forma_pagamento'],
        'paid' => in_array($order['payment_status'], ['paid', 'pago'], true),
        'customer_name' => $order['customer_name'],
        'created_at' => $order['date_added'],
        'items' => array_map(function($item) {
            return [
                'name' => $item['name'],
                'quantity' => (int)$item['quantity'],
                'price' => round((float)$item['price'], 2),
                'total' => round((float)$item['total'], 2),
            ];
        }, $items),
    ];

    // Include PIX data if payment is PIX and not yet paid
    if ($order['forma_pagamento'] === 'pix' && !in_array($order['payment_status'], ['paid', 'pago'], true)) {
        $responseData['pix'] = [
            'qr_code' => $order['pix_code'] ?? '',
            'qr_code_url' => $order['pix_qr_code'] ?? '',
        ];
    }

    response(true, $responseData, "Status do pedido");

} catch (Exception $e) {
    error_log("[mesa/status] Erro: " . $e->getMessage());
    response(false, null, "Erro ao consultar status", 500);
}
