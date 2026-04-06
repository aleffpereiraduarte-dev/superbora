<?php
/**
 * GET /api/mercado/orders/tracking-public.php?token=XXXXX
 * Public (unauthenticated) order tracking endpoint.
 * Returns limited order data — no personal info, no payment info, no full address.
 * Token expires after 24 hours or when the order is delivered.
 */
require_once __DIR__ . "/../config/database.php";

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    response(false, null, "Metodo nao permitido", 405);
}

try {
    $db = getDB();

    $token = trim($_GET['token'] ?? '');
    if (!$token || strlen($token) < 20 || strlen($token) > 100) {
        response(false, null, "Token de compartilhamento invalido", 400);
    }

    // Validate token format (hex only) to prevent injection
    if (!preg_match('/^[a-f0-9]+$/i', $token)) {
        response(false, null, "Token de compartilhamento invalido", 400);
    }

    // Look up the order by share token
    $stmt = $db->prepare("
        SELECT o.order_id, o.order_number, o.status, o.partner_id,
               o.shipping_neighborhood, o.shipping_city,
               o.date_added, o.confirmed_at, o.shopping_started_at,
               o.delivering_at, o.delivered_at, o.cancelled_at,
               o.delivery_type, o.is_pickup, o.schedule_date, o.schedule_time,
               o.share_token_expires_at, o.partner_categoria,
               o.driver_name, o.driver_photo,
               o.shopper_name,
               p.trade_name, p.name as partner_name_raw, p.logo as partner_logo,
               p.categoria as partner_category,
               p.latitude as partner_lat, p.longitude as partner_lng
        FROM om_market_orders o
        LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
        WHERE o.share_token = ?
    ");
    $stmt->execute([$token]);
    $order = $stmt->fetch();

    if (!$order) {
        response(false, null, "Link de acompanhamento nao encontrado ou expirado", 404);
    }

    // Check token expiration
    $expiresAt = $order['share_token_expires_at'];
    if ($expiresAt) {
        $expiryTime = new DateTime($expiresAt);
        $now = new DateTime();
        if ($now > $expiryTime) {
            response(false, null, "Link de acompanhamento expirado", 410);
        }
    }

    // Determine flow based on category
    $categoria = strtolower(trim($order['partner_categoria'] ?? $order['partner_category'] ?? 'mercado'));
    $flow = in_array($categoria, ['mercado', 'supermercado']) ? 'mercado' : 'restaurante';
    $currentStatus = $order['status'];

    // Build timeline (same logic as detail.php but without customer-specific data)
    if ($flow === 'mercado') {
        $timelineSteps = [
            ['key' => 'criado', 'label' => 'Pedido feito', 'field' => 'date_added'],
            ['key' => 'shopper_aceito', 'label' => 'Shopper a caminho', 'field' => 'confirmed_at'],
            ['key' => 'coletando', 'label' => 'Coletando itens', 'field' => 'shopping_started_at'],
            ['key' => 'entrega', 'label' => 'Em entrega', 'field' => 'delivering_at'],
            ['key' => 'entregue', 'label' => 'Entregue', 'field' => 'delivered_at'],
        ];
    } else {
        $timelineSteps = [
            ['key' => 'criado', 'label' => 'Pedido feito', 'field' => 'date_added'],
            ['key' => 'confirmado', 'label' => 'Confirmado', 'field' => 'confirmed_at'],
            ['key' => 'preparando', 'label' => 'Preparando', 'field' => 'shopping_started_at'],
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

    if (!$foundCurrent && !empty($timeline)) {
        $timeline[count($timeline) - 1]['current'] = true;
    }

    if ($cancelled) {
        $timeline = [
            ["status" => "criado", "label" => "Pedido realizado", "date" => $order['date_added'], "completed" => true, "current" => false],
            ["status" => "cancelado", "label" => "Cancelado", "date" => $order['cancelled_at'], "completed" => true, "current" => true, "error" => true]
        ];
    }

    // Fetch items (names and quantities only — no prices for public view)
    $stmtItems = $db->prepare("
        SELECT oi.product_name, oi.quantity, prod.image as product_image
        FROM om_market_order_items oi
        LEFT JOIN om_market_products prod ON oi.product_id = prod.product_id
        WHERE oi.order_id = ?
    ");
    $stmtItems->execute([$order['order_id']]);
    $rawItems = $stmtItems->fetchAll();

    $items = [];
    foreach ($rawItems as $item) {
        $items[] = [
            "name" => $item['product_name'] ?? '',
            "quantity" => (int)$item['quantity'],
            "image" => $item['product_image'] ?? null,
        ];
    }

    // Estimate ETA based on status timestamps (rough estimate for public view)
    $etaMinutes = null;
    $activeStatuses = ['pendente', 'pedido_feito', 'confirmado', 'aceito', 'shopper_aceito',
                       'preparando', 'em_preparo', 'coletando', 'pronto', 'saiu_entrega',
                       'em_entrega', 'out_for_delivery'];
    if (in_array($currentStatus, $activeStatuses)) {
        // Simple heuristic: estimate based on how far along the order is
        $deliveryStatuses = ['saiu_entrega', 'em_entrega', 'out_for_delivery'];
        if (in_array($currentStatus, $deliveryStatuses)) {
            $etaMinutes = 15; // Default delivery ETA
        } elseif (in_array($currentStatus, ['preparando', 'em_preparo', 'coletando', 'pronto'])) {
            $etaMinutes = 30;
        } else {
            $etaMinutes = 45;
        }
    }

    $partnerName = $order['trade_name'] ?: $order['partner_name_raw'] ?: '';

    // Build driver/shopper info (name + photo only, no phone)
    $deliveryPerson = null;
    if (!empty($order['driver_name'])) {
        $deliveryPerson = [
            'name' => $order['driver_name'],
            'photo' => $order['driver_photo'] ?? null,
        ];
    } elseif (!empty($order['shopper_name'])) {
        $deliveryPerson = [
            'name' => $order['shopper_name'],
            'photo' => null,
        ];
    }

    response(true, [
        'order_number' => $order['order_number'],
        'status' => $currentStatus,
        'flow' => $flow,
        'is_pickup' => (bool)($order['is_pickup'] ?? false),
        'date' => $order['date_added'],
        'delivered_at' => $order['delivered_at'],
        'eta_minutes' => $etaMinutes,
        'timeline' => $timeline,
        'items' => $items,
        'item_count' => count($items),
        'neighborhood' => $order['shipping_neighborhood'] ?? null,
        'city' => $order['shipping_city'] ?? null,
        'partner' => [
            'name' => $partnerName,
            'logo' => $order['partner_logo'],
            'category' => $order['partner_category'],
        ],
        'delivery_person' => $deliveryPerson,
        'schedule_date' => $order['schedule_date'],
        'schedule_time' => $order['schedule_time'],
    ], "Dados de acompanhamento publico");

} catch (Exception $e) {
    error_log("[API tracking-public] Erro: " . $e->getMessage());
    response(false, null, "Erro ao carregar acompanhamento", 500);
}
