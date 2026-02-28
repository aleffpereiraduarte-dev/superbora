<?php
/**
 * GET /api/mercado/orders/detail.php?id=X
 * Detalhes completos de um pedido
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

    $orderId = (int)($_GET['id'] ?? 0);
    if (!$orderId) response(false, null, "ID do pedido obrigatorio", 400);

    $stmt = $db->prepare("
        SELECT o.*,
               p.trade_name, p.logo as partner_logo,
               p.phone as partner_phone, p.categoria as partner_category,
               p.address as partner_address,
               sh.name as shopper_name, sh.phone as shopper_phone, sh.photo as shopper_photo, sh.rating as shopper_rating
        FROM om_market_orders o
        LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
        LEFT JOIN om_market_shoppers sh ON o.shopper_id = sh.shopper_id
        WHERE o.order_id = ? AND o.customer_id = ?
    ");
    $stmt->execute([$orderId, $customerId]);
    $order = $stmt->fetch();

    if (!$order) response(false, null, "Pedido nao encontrado", 404);

    // Items
    $stmtItems = $db->prepare("
        SELECT oi.*, prod.image as product_image
        FROM om_market_order_items oi
        LEFT JOIN om_market_products prod ON oi.product_id = prod.product_id
        WHERE oi.order_id = ?
    ");
    $stmtItems->execute([$orderId]);
    $rawItems = $stmtItems->fetchAll();

    $items = [];
    foreach ($rawItems as $item) {
        $items[] = [
            "product_id" => (int)$item['product_id'],
            "name" => $item['product_name'] ?? $item['name'] ?? '',
            "quantity" => (int)$item['quantity'],
            "price" => (float)$item['price'],
            "total" => (float)($item['total'] ?? ($item['price'] * $item['quantity'])),
            "image" => $item['product_image'] ?? $item['image'] ?? null
        ];
    }

    // Determinar fluxo baseado na categoria do parceiro
    $categoria = strtolower(trim($order['partner_categoria'] ?? $order['partner_category'] ?? 'mercado'));
    $flow = in_array($categoria, ['mercado', 'supermercado']) ? 'mercado' : 'restaurante';
    $currentStatus = $order['status'];

    // Timeline adaptativa por tipo de estabelecimento
    if ($flow === 'mercado') {
        // Mercado: Pedido feito → Shopper a caminho → Coletando → Em entrega → Entregue
        $timelineSteps = [
            ['key' => 'criado', 'label' => 'Pedido feito', 'field' => 'date_added'],
            ['key' => 'shopper_aceito', 'label' => 'Shopper a caminho', 'field' => 'accepted_at'],
            ['key' => 'coletando', 'label' => 'Coletando itens', 'field' => 'shopping_started_at'],
            ['key' => 'entrega', 'label' => 'Em entrega', 'field' => 'delivering_at'],
            ['key' => 'entregue', 'label' => 'Entregue', 'field' => 'delivered_at'],
        ];
    } else {
        // Restaurante/farmacia/loja: Pedido feito → Confirmado → Preparando → Saiu p/ entrega → Entregue
        $timelineSteps = [
            ['key' => 'criado', 'label' => 'Pedido feito', 'field' => 'date_added'],
            ['key' => 'confirmado', 'label' => 'Confirmado', 'field' => 'accepted_at'],
            ['key' => 'preparando', 'label' => 'Preparando', 'field' => 'preparing_started_at'],
            ['key' => 'entrega', 'label' => 'Saiu p/ entrega', 'field' => 'delivering_at'],
            ['key' => 'entregue', 'label' => 'Entregue', 'field' => 'delivered_at'],
        ];
    }

    $cancelled = in_array($currentStatus, ['cancelled', 'cancelado']);

    $timeline = [];
    $foundCurrent = false;
    foreach ($timelineSteps as $i => $step) {
        $date = $order[$step['field']] ?? null;
        $completed = !empty($date);
        $current = false;

        if (!$foundCurrent && !$completed) {
            // Previous step is current
            if ($i > 0 && !empty($timeline)) {
                $timeline[count($timeline) - 1]['current'] = true;
            }
            $foundCurrent = true;
        }

        $timeline[] = [
            "status" => $step['key'],
            "label" => $step['label'],
            "date" => $date,
            "completed" => $completed,
            "current" => $current,
        ];
    }

    // If all completed, mark last as current
    if (!$foundCurrent && !empty($timeline)) {
        $timeline[count($timeline) - 1]['current'] = true;
    }

    // If cancelled, replace timeline
    if ($cancelled) {
        $timeline = [
            ["status" => "criado", "label" => "Pedido realizado", "date" => $order['date_added'], "completed" => true, "current" => false],
            ["status" => "cancelado", "label" => "Cancelado", "date" => $order['cancelled_at'] ?? $order['date_modified'], "completed" => true, "current" => true, "error" => true]
        ];
    }

    $paymentMethod = $order['forma_pagamento'] ?: $order['payment_method'];
    $deliveryCode = $order['codigo_entrega'] ?: $order['delivery_code'] ?: $order['verification_code'];

    // Check for existing review
    $review = null;
    try {
        $stmtReview = $db->prepare("SELECT rating, comment, created_at FROM om_market_order_reviews WHERE order_id = ? AND customer_id = ? LIMIT 1");
        $stmtReview->execute([$orderId, $customerId]);
        $reviewRow = $stmtReview->fetch();
        if ($reviewRow) {
            $review = [
                'rating' => (int)$reviewRow['rating'],
                'comment' => $reviewRow['comment'],
                'created_at' => $reviewRow['created_at'],
            ];
        }
    } catch (Exception $e) {
        // Table may not exist yet, ignore
    }

    response(true, [
        "order" => [
            "id" => (int)$order['order_id'],
            "order_number" => $order['order_number'],
            "status" => $order['status'],
            "flow" => $flow,
            "subtotal" => (float)$order['subtotal'],
            "delivery_fee" => (float)$order['delivery_fee'],
            "service_fee" => (float)($order['service_fee'] ?? 0),
            "tip" => (float)($order['tip_amount'] ?? 0),
            "coupon_discount" => (float)($order['coupon_discount'] ?? 0),
            "total" => (float)$order['total'],
            "original_total" => isset($order['original_total']) ? (float)$order['original_total'] : null,
            "payment_method" => $paymentMethod,
            "address" => $order['delivery_address'] ?: $order['shipping_address'],
            "is_pickup" => (bool)($order['is_pickup'] ?? false),
            "notes" => $order['notes'],
            "delivery_instructions" => $order['delivery_instructions'] ?? null,
            "schedule_date" => $order['schedule_date'],
            "schedule_time" => $order['schedule_time'],
            "codigo_entrega" => $deliveryCode,
            "delivery_photo" => $order['delivery_photo'] ?? null,
            "delivery_photo_at" => $order['delivery_photo_at'] ?? null,
            "date" => $order['date_added'],
            "confirmed_at" => $order['confirmed_at'] ?? null,
            "preparing_at" => $order['shopping_started_at'] ?? null,
            "dispatched_at" => $order['delivering_at'] ?? null,
            "delivered_at" => $order['delivered_at'] ?? null,
            "status_updated_at" => $order['date_modified'] ?? $order['date_added'],
            "items" => $items,
            "timeline" => $timeline,
            "review" => $review,
            "partner" => [
                "id" => (int)$order['partner_id'],
                "name" => $order['trade_name'] ?: $order['partner_name'] ?: '',
                "logo" => $order['partner_logo'],
                "phone" => $order['partner_phone'],
                "category" => $order['partner_category'],
                "address" => $order['partner_address']
            ],
            "shopper" => !empty($order['shopper_name']) ? [
                "id" => (int)($order['shopper_id'] ?? 0),
                "name" => $order['shopper_name'],
                "phone" => $order['shopper_phone'] ?? null,
                "photo" => $order['shopper_photo'] ?? null,
                "rating" => isset($order['shopper_rating']) ? (float)$order['shopper_rating'] : null
            ] : null,
            "driver" => !empty($order['motorista_nome']) ? [
                "id" => (int)($order['motorista_id'] ?? 0),
                "name" => $order['motorista_nome'],
                "phone" => $order['motorista_telefone'] ?? null,
                "photo" => $order['motorista_foto'] ?? null,
                "vehicle" => $order['motorista_carro'] ?? null,
                "plate" => $order['motorista_placa'] ?? null
            ] : (!empty($order['driver_name']) ? [
                "id" => (int)($order['driver_id'] ?? 0),
                "name" => $order['driver_name'],
                "phone" => $order['driver_phone'] ?? null,
                "photo" => $order['driver_photo'] ?? null
            ] : null)
        ]
    ]);

} catch (Exception $e) {
    error_log("[API Order Detail] Erro: " . $e->getMessage());
    response(false, null, "Erro ao carregar pedido", 500);
}
