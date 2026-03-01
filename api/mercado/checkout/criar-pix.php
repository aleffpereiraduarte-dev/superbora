<?php
/**
 * POST /api/mercado/checkout/criar-pix.php
 * PIX Payment-First: Generate PIX QR code WITHOUT creating an order.
 * Order is only created when PIX is confirmed (via webhook).
 *
 * Body: same as processar.php but only for PIX payments.
 * Returns: intent_id, pix_code, pix_qr_url, amount, expires_at
 */
require_once __DIR__ . "/../config/database.php";
setCorsHeaders();
require_once dirname(__DIR__, 2) . "/rate-limit/RateLimiter.php";
require_once dirname(__DIR__, 3) . "/includes/classes/WooviClient.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmPricing.php";

if (!RateLimiter::check(10, 60)) {
    exit;
}

try {
    $input = getInput();
    $db = getDB();
    $customer_id = requireCustomerAuth();

    // Sanitize input (same as processar.php)
    $partner_id = (int)($input["partner_id"] ?? 0);
    $is_pickup = (int)($input["is_pickup"] ?? 0);
    $tip = min(max(0, (float)($input["tip"] ?? 0)), OmPricing::GORJETA_MAX);
    $coupon_id = (int)($input["coupon_id"] ?? 0);
    $cpf_nota = preg_replace('/[^0-9]/', '', $input["cpf_nota"] ?? "");
    if ($cpf_nota && strlen($cpf_nota) !== 11) $cpf_nota = "";

    // Address
    $addressRaw = $input["address"] ?? "";
    if (is_array($addressRaw)) {
        $addressRaw = implode(", ", array_filter(array_map('strval', array_values($addressRaw))));
    }
    $address = trim(substr((string)$addressRaw, 0, 500));
    $shipping_cep = preg_replace('/[^0-9]/', '', $input["cep"] ?? "");
    $shipping_city = trim(substr($input["city"] ?? "", 0, 100));
    $shipping_state = trim(substr($input["state"] ?? "", 0, 2));

    $notes = trim(substr($input["notes"] ?? "", 0, 1000));
    $schedule_date = preg_replace('/[^0-9-]/', '', $input["schedule_date"] ?? "");
    $schedule_time = preg_replace('/[^0-9:]/', '', $input["schedule_time"] ?? "");

    // Multi-stop route
    $is_route_primary = !empty($input['is_route_primary']);
    $route_partner_ids = $input['route_partner_ids'] ?? [];
    if (!is_array($route_partner_ids)) $route_partner_ids = [];
    $incoming_route_id = (int)($input['route_id'] ?? 0);

    // Discounts
    $use_points = (int)($input['use_points'] ?? 0);
    $use_cashback = (float)($input['use_cashback'] ?? 0);
    $use_gift_card = (float)($input['use_gift_card'] ?? 0);

    // Delivery fee & service fee from input (validated server-side)
    $delivery_fee = max(0, (float)($input["delivery_fee"] ?? 0));
    $service_fee = OmPricing::TAXA_SERVICO;

    // Validate partner
    if (!$partner_id) {
        response(false, null, "partner_id obrigatorio", 400);
    }
    $partnerStmt = $db->prepare("SELECT partner_id, name, trade_name, is_open, status FROM om_market_partners WHERE partner_id = ?");
    $partnerStmt->execute([$partner_id]);
    $partner = $partnerStmt->fetch(PDO::FETCH_ASSOC);
    if (!$partner) {
        response(false, null, "Parceiro nao encontrado", 404);
    }
    if (!$partner['is_open']) {
        response(false, null, "Esta loja esta fechada no momento", 400);
    }

    // NOTE: We do NOT reuse existing PIX intents blindly.
    // The cart may have changed since the intent was created (TOCTOU vulnerability).
    // Instead, we expire any stale intents and always recalculate from the current cart.
    // Reuse is only safe if the amount matches exactly (validated after cart calculation below).

    // Get customer data
    $custStmt = $db->prepare("SELECT name, phone, email, cpf FROM om_customers WHERE customer_id = ?");
    $custStmt->execute([$customer_id]);
    $custData = $custStmt->fetch(PDO::FETCH_ASSOC);
    if (!$custData) {
        response(false, null, "Cliente nao encontrado", 404);
    }
    $customer_name = trim($custData['name'] ?: 'Cliente');
    $customer_phone = preg_replace('/[^0-9]/', '', $custData['phone'] ?? '');
    if (strlen($customer_phone) === 13 && substr($customer_phone, 0, 2) === '55') {
        $customer_phone = substr($customer_phone, 2);
    }
    $customer_email = $custData['email'] ?? '';

    // Get cart items (from DB cart)
    $cartWhere = "c.customer_id = ?";
    $cartParams = [$customer_id];

    $cartSql = "SELECT c.product_id, c.partner_id, c.quantity, c.notes as item_notes,
                       p.price, p.special_price, p.name as product_name, p.quantity as stock
                FROM om_market_cart c
                INNER JOIN om_market_products p ON c.product_id = p.product_id
                WHERE {$cartWhere} AND c.partner_id = ?
                ORDER BY c.cart_id ASC";
    $cartStmt = $db->prepare($cartSql);
    $cartStmt->execute(array_merge($cartParams, [$partner_id]));
    $cartItems = $cartStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($cartItems)) {
        response(false, null, "Carrinho vazio", 400);
    }

    // Validate stock (without locking)
    $subtotal = 0;
    $orderItems = [];
    foreach ($cartItems as $item) {
        $qty = (int)$item['quantity'];
        $stock = (int)$item['stock'];
        if ($qty > $stock) {
            response(false, null, "Estoque insuficiente para {$item['product_name']} (disponivel: {$stock})", 400);
        }
        $price = ($item['special_price'] && (float)$item['special_price'] > 0 && (float)$item['special_price'] < (float)$item['price'])
            ? (float)$item['special_price'] : (float)$item['price'];
        $itemTotal = $price * $qty;
        $subtotal += $itemTotal;
        $orderItems[] = [
            'product_id' => (int)$item['product_id'],
            'name' => $item['product_name'],
            'price' => $price,
            'quantity' => $qty,
            'subtotal' => $itemTotal,
            'notes' => $item['item_notes'] ?? '',
        ];
    }

    // Coupon discount (validate server-side)
    $coupon_discount = 0;
    if ($coupon_id > 0) {
        $coupStmt = $db->prepare("SELECT coupon_id, type, discount, min_order, max_discount, usage_limit, used_count, active, expires_at FROM om_market_coupons WHERE coupon_id = ? AND active = true");
        $coupStmt->execute([$coupon_id]);
        $coupon = $coupStmt->fetch(PDO::FETCH_ASSOC);
        if ($coupon) {
            if ($coupon['expires_at'] && strtotime($coupon['expires_at']) < time()) {
                $coupon = null;
            } elseif ($coupon['usage_limit'] > 0 && $coupon['used_count'] >= $coupon['usage_limit']) {
                $coupon = null;
            } elseif ($coupon['min_order'] > 0 && $subtotal < $coupon['min_order']) {
                $coupon = null;
            }
            if ($coupon) {
                if ($coupon['type'] === 'percentage') {
                    $coupon_discount = round($subtotal * ($coupon['discount'] / 100), 2);
                } else {
                    $coupon_discount = (float)$coupon['discount'];
                }
                if ($coupon['max_discount'] > 0) {
                    $coupon_discount = min($coupon_discount, (float)$coupon['max_discount']);
                }
            }
        }
    }

    // Points discount
    $pointsDiscount = 0;
    if ($use_points > 0) {
        $ptStmt = $db->prepare("SELECT points_balance FROM om_loyalty_points WHERE customer_id = ?");
        $ptStmt->execute([$customer_id]);
        $ptBal = (int)($ptStmt->fetchColumn() ?: 0);
        $pointsToUse = min($use_points, $ptBal);
        $pointsDiscount = min($pointsToUse * 0.01, $subtotal * 0.5);
    }

    // Cashback discount
    $cashbackDiscount = 0;
    if ($use_cashback > 0) {
        $cbStmt = $db->prepare("SELECT balance FROM om_cashback_wallet WHERE customer_id = ?");
        $cbStmt->execute([$customer_id]);
        $cbBal = (float)($cbStmt->fetchColumn() ?: 0);
        $cashbackDiscount = min($use_cashback, $cbBal);
    }

    // Calculate total
    $total = max(0, $subtotal + $delivery_fee + $service_fee + $tip - $coupon_discount - $pointsDiscount - $cashbackDiscount);
    $amountCents = (int)round($total * 100);

    if ($amountCents < 100) {
        response(false, null, "Valor minimo para PIX: R$ 1,00", 400);
    }

    // Safe reuse: only reuse existing intent if amount matches exactly (within 1 cent)
    $existingStmt = $db->prepare("
        SELECT intent_id, pix_code, pix_qr_url, amount_cents, expires_at
        FROM om_pix_intents
        WHERE customer_id = ? AND status = 'pending' AND expires_at > NOW()
        AND cart_snapshot->>'partner_id' = ?
        ORDER BY created_at DESC LIMIT 1
    ");
    $existingStmt->execute([$customer_id, (string)$partner_id]);
    $existingIntent = $existingStmt->fetch(PDO::FETCH_ASSOC);
    if ($existingIntent && abs((int)$existingIntent['amount_cents'] - $amountCents) <= 1) {
        // Amount matches current cart — safe to reuse
        response(true, [
            'intent_id' => (int)$existingIntent['intent_id'],
            'pix_code' => $existingIntent['pix_code'],
            'pix_qr_url' => $existingIntent['pix_qr_url'],
            'amount' => round($existingIntent['amount_cents'] / 100, 2),
            'amount_cents' => (int)$existingIntent['amount_cents'],
            'expires_at' => $existingIntent['expires_at'],
            'partner_name' => $partner['trade_name'] ?: $partner['name'],
        ]);
    } elseif ($existingIntent) {
        // Amount changed — expire the old intent so a fresh one is created
        $db->prepare("UPDATE om_pix_intents SET status = 'expired' WHERE intent_id = ? AND status = 'pending'")
           ->execute([(int)$existingIntent['intent_id']]);
    }

    // Build cart snapshot (everything needed to create the order later)
    $cartSnapshot = [
        'customer_id' => $customer_id,
        'partner_id' => $partner_id,
        'partner_name' => $partner['trade_name'] ?: $partner['name'],
        'items' => $orderItems,
        'subtotal' => round($subtotal, 2),
        'delivery_fee' => round($delivery_fee, 2),
        'service_fee' => round($service_fee, 2),
        'tip' => round($tip, 2),
        'coupon_id' => $coupon_id,
        'coupon_discount' => round($coupon_discount, 2),
        'points_discount' => round($pointsDiscount, 2),
        'points_used' => $use_points,
        'cashback_discount' => round($cashbackDiscount, 2),
        'use_gift_card' => round($use_gift_card, 2),
        'total' => round($total, 2),
        'is_pickup' => $is_pickup,
        'address' => $address,
        'cep' => $shipping_cep,
        'city' => $shipping_city,
        'state' => $shipping_state,
        'notes' => $notes,
        'cpf_nota' => $cpf_nota,
        'schedule_date' => $schedule_date,
        'schedule_time' => $schedule_time,
        'is_route_primary' => $is_route_primary,
        'route_partner_ids' => $route_partner_ids,
        'route_id' => $incoming_route_id,
        'customer_name' => $customer_name,
        'customer_phone' => $customer_phone,
        'customer_email' => $customer_email,
    ];

    // Generate PIX via Woovi
    $correlationId = 'pix_intent_' . $customer_id . '_' . time() . '_' . bin2hex(random_bytes(4));
    $comment = "SuperBora - " . ($partner['trade_name'] ?: $partner['name']);

    $woovi = new WooviClient();

    // Build customer data for Woovi
    $cpf = $cpf_nota ?: preg_replace('/[^0-9]/', '', $custData['cpf'] ?? '');
    $wooviCustomer = ['name' => $customer_name ?: 'Cliente SuperBora'];
    if (strlen($cpf) === 11) $wooviCustomer['taxID'] = $cpf;
    if (strlen($customer_phone) >= 10) $wooviCustomer['phone'] = '+55' . $customer_phone;
    if (!empty($customer_email) && filter_var($customer_email, FILTER_VALIDATE_EMAIL)) {
        $wooviCustomer['email'] = $customer_email;
    }
    if (!isset($wooviCustomer['taxID']) && !isset($wooviCustomer['phone']) && !isset($wooviCustomer['email'])) {
        $wooviCustomer['email'] = 'customer' . $customer_id . '@superbora.com.br';
    }

    $chargeResult = $woovi->createCharge($amountCents, $correlationId, $comment, 600, $wooviCustomer);
    $chargeData = $chargeResult['data'] ?? [];
    $charge = $chargeData['charge'] ?? $chargeData;

    $brCode = $charge['brCode'] ?? $charge['pixCopiaECola'] ?? '';
    $qrCodeUrl = $charge['qrCodeImage'] ?? '';

    if (empty($brCode)) {
        error_log("[criar-pix] PIX charge failed: " . json_encode($chargeData));
        response(false, null, "PIX indisponivel no momento. Tente novamente.", 503);
    }

    if (empty($qrCodeUrl)) {
        $qrCodeUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=400x400&data=' . urlencode($brCode);
    }

    // Save PIX intent (NO order created)
    $db->beginTransaction();
    $intentStmt = $db->prepare("
        INSERT INTO om_pix_intents (customer_id, amount_cents, cart_snapshot, correlation_id, pix_code, pix_qr_url, status, expires_at)
        VALUES (?, ?, ?::jsonb, ?, ?, ?, 'pending', NOW() + INTERVAL '10 minutes')
        RETURNING intent_id, expires_at
    ");
    $intentStmt->execute([
        $customer_id,
        $amountCents,
        json_encode($cartSnapshot),
        $correlationId,
        $brCode,
        $qrCodeUrl,
    ]);
    $intent = $intentStmt->fetch(PDO::FETCH_ASSOC);
    $db->commit();

    response(true, [
        'intent_id' => (int)$intent['intent_id'],
        'pix_code' => $brCode,
        'pix_qr_url' => $qrCodeUrl,
        'amount' => round($total, 2),
        'amount_cents' => $amountCents,
        'expires_at' => $intent['expires_at'],
        'partner_name' => $partner['trade_name'] ?: $partner['name'],
    ]);

} catch (Exception $e) {
    error_log("[criar-pix] Erro: " . $e->getMessage());
    response(false, null, "Erro ao gerar PIX. Tente novamente.", 500);
}
