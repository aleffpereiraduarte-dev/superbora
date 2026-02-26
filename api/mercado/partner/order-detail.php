<?php
/**
 * GET /api/mercado/partner/order-detail.php?id=X
 * Order detail with items, customer info, shopper info
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

    $order_id = (int)($_GET['id'] ?? 0);
    if (!$order_id) {
        response(false, null, "ID do pedido obrigatorio", 400);
    }

    // Buscar pedido (garantir que pertence ao parceiro)
    $stmt = $db->prepare("
        SELECT
            o.order_id, o.order_number, o.status, o.subtotal, o.delivery_fee, o.total,
            o.tip_amount, o.coupon_discount, o.forma_pagamento, o.payment_method,
            o.delivery_address, o.notes, o.is_pickup, o.schedule_date, o.schedule_time,
            o.codigo_entrega, o.date_added, o.confirmed_at, o.delivered_at, o.cancelled_at,
            o.driver_name, o.driver_phone, o.driver_photo, o.picked_up_at,
            o.customer_id, o.customer_name, o.customer_phone, o.customer_email,
            o.shopper_id,
            s.name as shopper_name,
            s.phone as shopper_phone
        FROM om_market_orders o
        LEFT JOIN om_market_shoppers s ON o.shopper_id = s.shopper_id
        WHERE o.order_id = ? AND o.partner_id = ?
    ");
    $stmt->execute([$order_id, $partner_id]);
    $order = $stmt->fetch();

    if (!$order) {
        response(false, null, "Pedido nao encontrado", 404);
    }

    // Buscar itens do pedido
    $stmtItems = $db->prepare("
        SELECT
            oi.id,
            oi.product_id,
            oi.name,
            oi.quantity,
            oi.price,
            pb.image as product_image,
            pb.barcode
        FROM om_market_order_items oi
        LEFT JOIN om_market_products_base pb ON pb.product_id = oi.product_id
        WHERE oi.order_id = ?
    ");
    $stmtItems->execute([$order_id]);
    $rawItems = $stmtItems->fetchAll();

    $items = [];
    foreach ($rawItems as $item) {
        $items[] = [
            "id" => (int)$item['id'],
            "product_id" => (int)$item['product_id'],
            "name" => $item['name'],
            "quantity" => (int)$item['quantity'],
            "price" => (float)$item['price'],
            "total" => round((float)$item['price'] * (int)$item['quantity'], 2),
            "image" => $item['product_image'],
            "barcode" => $item['barcode']
        ];
    }

    // Buscar dados de entrega (om_entregas) se existir
    $delivery = null;
    try {
        $stmtDel = $db->prepare("
            SELECT e.id, e.status AS delivery_status, e.boraum_status,
                   e.motorista_nome, e.motorista_telefone, e.motorista_foto, e.motorista_veiculo,
                   e.coletado_em, e.entregue_em, e.created_at AS dispatch_at
            FROM om_entregas e
            WHERE e.referencia_id = ?
            ORDER BY e.id DESC
            LIMIT 1
        ");
        $stmtDel->execute([$order_id]);
        $delRow = $stmtDel->fetch();
        if ($delRow) {
            $delivery = [
                "id" => (int)$delRow['id'],
                "status" => $delRow['delivery_status'],
                "boraum_status" => $delRow['boraum_status'] ?? null,
                "driver_name" => $delRow['motorista_nome'] ?: ($order['driver_name'] ?? null),
                "driver_phone" => $delRow['motorista_telefone'] ?: ($order['driver_phone'] ?? null),
                "driver_photo" => $delRow['motorista_foto'] ?: ($order['driver_photo'] ?? null),
                "driver_vehicle" => $delRow['motorista_veiculo'] ?? null,
                "picked_up_at" => $delRow['coletado_em'] ?? ($order['picked_up_at'] ?? null),
                "delivered_at" => $delRow['entregue_em'] ?? null,
                "dispatched_at" => $delRow['dispatch_at'] ?? null
            ];
        }
    } catch (Exception $e) {
        // om_entregas may not exist yet, ignore
    }

    // Fallback: if no om_entregas row but order has driver columns
    if (!$delivery && !empty($order['driver_name'])) {
        $delivery = [
            "id" => null,
            "status" => null,
            "boraum_status" => null,
            "driver_name" => $order['driver_name'],
            "driver_phone" => $order['driver_phone'] ?? null,
            "driver_photo" => $order['driver_photo'] ?? null,
            "driver_vehicle" => null,
            "picked_up_at" => $order['picked_up_at'] ?? null,
            "delivered_at" => $order['delivered_at'] ?? null,
            "dispatched_at" => null
        ];
    }

    // Montar resposta
    $data = [
        "order" => [
            "order_id" => (int)$order['order_id'],
            "order_number" => $order['order_number'],
            "status" => $order['status'],
            "subtotal" => (float)$order['subtotal'],
            "delivery_fee" => (float)$order['delivery_fee'],
            "total" => (float)$order['total'],
            "tip_amount" => (float)($order['tip_amount'] ?? 0),
            "coupon_discount" => (float)($order['coupon_discount'] ?? 0),
            "payment_method" => $order['forma_pagamento'] ?? $order['payment_method'] ?? null,
            "delivery_address" => $order['delivery_address'],
            "notes" => $order['notes'],
            "is_pickup" => (bool)($order['is_pickup'] ?? false),
            "schedule_date" => $order['schedule_date'] ?? null,
            "schedule_time" => $order['schedule_time'] ?? null,
            "codigo_entrega" => $order['codigo_entrega'] ?? null,
            "date_added" => $order['date_added'],
            "confirmed_at" => $order['confirmed_at'] ?? null,
            "delivered_at" => $order['delivered_at'] ?? null,
            "cancelled_at" => $order['cancelled_at'] ?? null,
            "driver_name" => $order['driver_name'] ?? null,
            "driver_phone" => $order['driver_phone'] ?? null,
            "driver_photo" => $order['driver_photo'] ?? null,
            "picked_up_at" => $order['picked_up_at'] ?? null
        ],
        "customer" => [
            "id" => (int)$order['customer_id'],
            "name" => $order['customer_name'],
            "phone" => $order['customer_phone'] ?? null,
            "email" => $order['customer_email'] ?? null
        ],
        "shopper" => $order['shopper_id'] ? [
            "id" => (int)$order['shopper_id'],
            "name" => $order['shopper_name'],
            "phone" => $order['shopper_phone']
        ] : null,
        "delivery" => $delivery,
        "items" => $items,
        "total_items" => count($items)
    ];

    response(true, $data, "Detalhes do pedido");

} catch (Exception $e) {
    error_log("[partner/order-detail] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
