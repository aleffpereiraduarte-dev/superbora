<?php
/**
 * POST /api/mercado/checkout/efi-card-payment.php
 * Card Payment-First: Tokenize + charge card via EFI, then create order.
 * Synchronous — no webhook needed (unlike PIX).
 *
 * Body: same as criar-pix.php + card data:
 *   card_number, card_cvv, card_exp_month, card_exp_year, card_brand, card_name
 *   OR saved_card_token (reusable token from previous payment)
 *   installments (1-12)
 *
 * Returns: { success, order_id, order_number, charge_id }
 */
require_once __DIR__ . "/../config/database.php";
setCorsHeaders();
require_once dirname(__DIR__, 2) . "/rate-limit/RateLimiter.php";
require_once dirname(__DIR__, 3) . "/includes/classes/EfiClient.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmPricing.php";
require_once dirname(__DIR__, 3) . "/includes/classes/PusherService.php";
require_once dirname(__DIR__) . "/helpers/notify.php";
require_once dirname(__DIR__) . "/helpers/ws-customer-broadcast.php";

if (!RateLimiter::check(10, 60)) {
    exit;
}

try {
    $input = getInput();
    $db = getDB();
    $customer_id = requireCustomerAuth();

    // ═══ CARD DATA ═══
    $savedCardToken = trim($input['saved_card_token'] ?? '');
    $paymentTokenFromClient = trim($input['payment_token'] ?? '');
    $cardLast4Input = trim($input['card_last4'] ?? '');
    $cardBrand = strtolower(trim($input['card_brand'] ?? 'visa'));
    $cardName = trim($input['card_name'] ?? '');
    $cardExpMonth = trim($input['card_exp_month'] ?? '');
    $cardExpYear = trim($input['card_exp_year'] ?? '');
    $installments = max(1, min(12, (int)($input['installments'] ?? 1)));
    $saveCard = (bool)($input['save_card'] ?? false);

    $hasSavedToken = !empty($savedCardToken) && strpos($savedCardToken, 'tok_') !== 0;
    $hasPaymentToken = !empty($paymentTokenFromClient) && preg_match('/^[a-fA-F0-9]{30,50}$/', $paymentTokenFromClient);

    if (!$hasSavedToken && !$hasPaymentToken) {
        response(false, null, "Token de pagamento obrigatorio", 400);
    }

    // ═══ ORDER DATA (same as criar-pix.php) ═══
    $partner_id = (int)($input["partner_id"] ?? 0);
    $is_pickup = (int)($input["is_pickup"] ?? 0);
    $tip = min(max(0, (float)($input["tip"] ?? 0)), OmPricing::GORJETA_MAX);
    $coupon_id = (int)($input["coupon_id"] ?? 0);
    $cpf_nota = preg_replace('/[^0-9]/', '', $input["cpf_nota"] ?? "");
    if ($cpf_nota && strlen($cpf_nota) !== 11) $cpf_nota = "";

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

    $is_route_primary = !empty($input['is_route_primary']);
    $route_partner_ids = $input['route_partner_ids'] ?? [];
    if (!is_array($route_partner_ids)) $route_partner_ids = [];

    $use_points = (int)($input['use_points'] ?? 0);
    $use_cashback = (float)($input['use_cashback'] ?? 0);
    $use_gift_card = (float)($input['use_gift_card'] ?? 0);

    $service_fee = OmPricing::TAXA_SERVICO;

    // ═══ VALIDATE PARTNERS ═══
    $allPartnerIds = [];
    if ($partner_id) $allPartnerIds[] = $partner_id;
    foreach ($route_partner_ids as $rpid) {
        $rpid = (int)$rpid;
        if ($rpid > 0 && !in_array($rpid, $allPartnerIds)) $allPartnerIds[] = $rpid;
    }
    if (empty($allPartnerIds)) {
        response(false, null, "partner_id obrigatorio", 400);
    }

    $partners = [];
    $partnerNames = [];
    $partnerStmt = $db->prepare("SELECT * FROM om_market_partners WHERE partner_id = ?");
    foreach ($allPartnerIds as $pid) {
        $partnerStmt->execute([$pid]);
        $p = $partnerStmt->fetch(PDO::FETCH_ASSOC);
        if (!$p) response(false, null, "Parceiro #{$pid} nao encontrado", 404);
        if (!$p['is_open']) {
            $pName = $p['trade_name'] ?: $p['name'];
            response(false, null, "{$pName} esta fechada no momento", 400);
        }
        $partners[$pid] = $p;
        $partnerNames[] = $p['trade_name'] ?: $p['name'];
    }
    $partner = $partners[$allPartnerIds[0]];

    // ═══ CUSTOMER DATA ═══
    $custStmt = $db->prepare("SELECT name, phone, email, cpf FROM om_customers WHERE customer_id = ?");
    $custStmt->execute([$customer_id]);
    $custData = $custStmt->fetch(PDO::FETCH_ASSOC);
    if (!$custData) response(false, null, "Cliente nao encontrado", 404);

    $customer_name = trim($custData['name'] ?: 'Cliente');
    $customer_phone = preg_replace('/[^0-9]/', '', $custData['phone'] ?? '');
    if (strlen($customer_phone) === 13 && substr($customer_phone, 0, 2) === '55') {
        $customer_phone = substr($customer_phone, 2);
    }
    $customer_email = $custData['email'] ?? '';
    $customer_cpf = preg_replace('/\D/', '', $custData['cpf'] ?? '');

    // ═══ VALIDATE CART + STOCK ═══
    $db->beginTransaction();
    $placeholders = implode(',', array_fill(0, count($allPartnerIds), '?'));
    $cartSql = "SELECT c.product_id, c.partner_id, c.quantity, c.notes as item_notes,
                       p.price, p.special_price, p.name as product_name, p.quantity as stock
                FROM om_market_cart c
                INNER JOIN om_market_products p ON c.product_id = p.product_id
                WHERE c.customer_id = ? AND c.partner_id IN ({$placeholders})
                ORDER BY c.partner_id, c.cart_id ASC
                FOR UPDATE OF p";
    $cartStmt = $db->prepare($cartSql);
    $cartStmt->execute(array_merge([$customer_id], $allPartnerIds));
    $cartItems = $cartStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($cartItems)) {
        $db->rollBack();
        response(false, null, "Carrinho vazio", 400);
    }

    $subtotal = 0;
    $orderItems = [];
    foreach ($cartItems as $item) {
        $qty = (int)$item['quantity'];
        if ($qty <= 0) continue;
        $stock = (int)$item['stock'];
        if ($qty > $stock) {
            $db->rollBack();
            response(false, null, "Estoque insuficiente para {$item['product_name']} (disponivel: {$stock})", 400);
        }
        $price = ($item['special_price'] && (float)$item['special_price'] > 0 && (float)$item['special_price'] < (float)$item['price'])
            ? (float)$item['special_price'] : (float)$item['price'];
        if ($price <= 0) {
            $db->rollBack();
            response(false, null, "Preco invalido para {$item['product_name']}", 400);
        }
        $itemTotal = $price * $qty;
        $subtotal += $itemTotal;
        $orderItems[] = [
            'product_id' => (int)$item['product_id'],
            'partner_id' => (int)$item['partner_id'],
            'name' => $item['product_name'],
            'price' => $price,
            'quantity' => $qty,
            'subtotal' => $itemTotal,
            'notes' => $item['item_notes'] ?? '',
        ];
    }

    if (empty($orderItems)) {
        if ($db->inTransaction()) $db->rollBack();
        response(false, null, "Carrinho vazio", 400);
    }
    $db->commit();

    // ═══ DELIVERY FEE (server-side) ═══
    $usaBoraUm = !$is_pickup && !$partner['entrega_propria'];
    $express_fee_raw = (float)($input['express_fee'] ?? 0);
    $express_enabled = !empty($partner['delivery_express']) || !empty($partner['express_disponivel']);
    $express_fee = $express_enabled ? max(0, min(OmPricing::EXPRESS_FEE_MAX, $express_fee_raw)) : 0;

    $distancia_km = 3.0;
    $lat_cliente = (float)($input['lat'] ?? $input['latitude'] ?? 0);
    $lng_cliente = (float)($input['lng'] ?? $input['longitude'] ?? 0);
    $lat_parceiro = (float)($partner['latitude'] ?? $partner['lat'] ?? 0);
    $lng_parceiro = (float)($partner['longitude'] ?? $partner['lng'] ?? 0);
    if ($lat_cliente && ($lat_cliente < -90 || $lat_cliente > 90)) $lat_cliente = 0;
    if ($lng_cliente && ($lng_cliente < -180 || $lng_cliente > 180)) $lng_cliente = 0;
    if ($usaBoraUm && $lat_parceiro && $lat_cliente) {
        $distancia_km = OmPricing::calcularDistancia($lat_parceiro, $lng_parceiro, $lat_cliente, $lng_cliente);
    }

    if ($usaBoraUm && $lat_parceiro && $lat_cliente) {
        $raio_km = (float)($partner['delivery_radius_km'] ?? 0);
        if ($raio_km > 0 && $distancia_km > $raio_km) {
            response(false, null, "Voce esta fora da area de entrega (" . number_format($distancia_km, 1, ',', '') . " km, maximo " . number_format($raio_km, 1, ',', '') . " km)", 400);
        }
    }
    if ($usaBoraUm) {
        $minimoBoraUm = OmPricing::getMinimoBoraUm($distancia_km);
        if ($subtotal < $minimoBoraUm) {
            response(false, null, "Pedido minimo R$ " . number_format($minimoBoraUm, 2, ',', '.') . " para esta distancia", 400);
        }
    }

    $freteCalc = OmPricing::calcularFrete($partner, $subtotal, $distancia_km, (bool)$is_pickup, $usaBoraUm, $db, $customer_id);
    $base_delivery_fee = $freteCalc['frete'];
    $delivery_fee = $base_delivery_fee + ($express_fee > 0 && !$is_pickup ? $express_fee : 0);

    // ═══ DISCOUNTS ═══
    $coupon_discount = 0;
    if ($coupon_id > 0) {
        $coupStmt = $db->prepare("SELECT id, discount_type, discount_value, min_order_value, max_discount_value, max_uses, current_uses, expires_at FROM om_market_coupons WHERE id = ? AND status = 'active' AND (partner_id IS NULL OR partner_id = 0 OR partner_id = ?)");
        $coupStmt->execute([$coupon_id, $partner_id]);
        $coupon = $coupStmt->fetch(PDO::FETCH_ASSOC);
        if ($coupon) {
            if ($coupon['expires_at'] && strtotime($coupon['expires_at']) < time()) $coupon = null;
            elseif ($coupon['max_uses'] > 0 && $coupon['current_uses'] >= $coupon['max_uses']) $coupon = null;
            elseif ($coupon['min_order_value'] > 0 && $subtotal < $coupon['min_order_value']) $coupon = null;
            if ($coupon) {
                if ($coupon['discount_type'] === 'percentage') {
                    $coupon_discount = round($subtotal * ($coupon['discount_value'] / 100), 2);
                } else {
                    $coupon_discount = (float)$coupon['discount_value'];
                }
                if ($coupon['max_discount_value'] > 0) {
                    $coupon_discount = min($coupon_discount, (float)$coupon['max_discount_value']);
                }
            }
        }
    }

    $pointsDiscount = 0;
    if ($use_points > 0) {
        $ptStmt = $db->prepare("SELECT current_points FROM om_market_loyalty_points WHERE customer_id = ?");
        $ptStmt->execute([$customer_id]);
        $currentPoints = (int)($ptStmt->fetchColumn() ?: 0);
        if ($currentPoints > 0) {
            $pointsToUse = min($use_points, $currentPoints);
            $pointsDiscount = round($pointsToUse * OmPricing::PONTO_VALOR, 2);
            $maxLoyaltyDiscount = round($subtotal * OmPricing::PONTOS_MAX_DESCONTO_PCT, 2);
            if ($pointsDiscount > $maxLoyaltyDiscount) {
                $pointsToUse = (int)floor($maxLoyaltyDiscount / OmPricing::PONTO_VALOR);
                $pointsDiscount = round($pointsToUse * OmPricing::PONTO_VALOR, 2);
            }
        }
    }

    $cashbackDiscount = 0;
    if ($use_cashback > 0) {
        $cbStmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM om_cashback WHERE customer_id = ? AND type IN ('earned','bonus') AND status = 'available' AND (expires_at IS NULL OR expires_at > NOW())");
        $cbStmt->execute([$customer_id]);
        $cbBal = (float)($cbStmt->fetchColumn() ?: 0);
        if ($cbBal > 0) $cashbackDiscount = min($use_cashback, $cbBal);
    }

    // ═══ CALCULATE TOTAL ═══
    $total = max(0, $subtotal + $delivery_fee + $service_fee + $tip - $coupon_discount - $pointsDiscount - $cashbackDiscount);

    if ($total < 1.00) {
        response(false, null, "Valor minimo para cartao: R$ 1,00", 400);
    }

    // ═══ TOKENIZE + CHARGE CARD VIA EFI ═══
    $efi = new EfiClient();
    if (!$efi->isConfigured()) {
        response(false, null, "Sistema de pagamento indisponivel", 503);
    }

    // Get payment token (from client-side EFI SDK or saved card)
    $paymentToken = '';
    $cardLast4 = $cardLast4Input;
    $cardBrandFinal = $cardBrand;

    if ($hasPaymentToken) {
        // Client already tokenized via EFI WebView SDK
        $paymentToken = $paymentTokenFromClient;
    } elseif ($hasSavedToken) {
        // Use saved card token
        $paymentToken = $savedCardToken;
        $savedStmt = $db->prepare("SELECT card_last4, card_brand FROM om_market_saved_cards WHERE card_token = ? AND customer_id = ?");
        $savedStmt->execute([$savedCardToken, $customer_id]);
        $savedCard = $savedStmt->fetch(PDO::FETCH_ASSOC);
        if ($savedCard) {
            $cardLast4 = $savedCard['card_last4'];
            $cardBrandFinal = $savedCard['card_brand'];
        }
    }

    // Build customer data for EFI
    $efiCustomer = [
        'name' => $cardName ?: $customer_name,
        'cpf' => $cpf_nota ?: $customer_cpf,
        'email' => $customer_email ?: 'cliente@superbora.com.br',
        'phone' => $customer_phone,
    ];

    $combinedPartnerName = implode(' + ', $partnerNames);
    $description = "SuperBora - " . $combinedPartnerName;

    // Charge
    $chargeResult = $efi->chargeCard($total, $description, $efiCustomer, $paymentToken, $installments);

    if (!$chargeResult['success']) {
        $errorMsg = $chargeResult['error'] ?? 'Erro ao processar pagamento';
        // Map common EFI errors to user-friendly messages
        if (stripos($errorMsg, 'recusad') !== false || stripos($errorMsg, 'denied') !== false) {
            $errorMsg = 'Cartao recusado. Verifique os dados ou tente outro cartao.';
        } elseif (stripos($errorMsg, 'saldo') !== false || stripos($errorMsg, 'insufficient') !== false) {
            $errorMsg = 'Saldo insuficiente. Tente outro cartao.';
        } elseif (stripos($errorMsg, 'expirad') !== false || stripos($errorMsg, 'expired') !== false) {
            $errorMsg = 'Cartao vencido. Atualize seus dados.';
        }
        response(false, null, $errorMsg, 400);
    }

    $efiChargeId = $chargeResult['charge_id'];

    // ═══ SAVE CARD (if requested and new card) ═══
    if ($saveCard && $hasPaymentToken && !empty($paymentToken) && !empty($cardLast4)) {
        try {
            $existsStmt = $db->prepare("SELECT id FROM om_market_saved_cards WHERE customer_id = ? AND card_last4 = ? AND card_brand = ?");
            $existsStmt->execute([$customer_id, $cardLast4, $cardBrandFinal]);
            if (!$existsStmt->fetch()) {
                $countStmt = $db->prepare("SELECT COUNT(*) FROM om_market_saved_cards WHERE customer_id = ?");
                $countStmt->execute([$customer_id]);
                $isFirst = ((int)$countStmt->fetchColumn()) === 0;

                $db->prepare("INSERT INTO om_market_saved_cards (customer_id, card_token, card_last4, card_brand, card_exp_month, card_exp_year, is_default) VALUES (?, ?, ?, ?, ?, ?, ?)")
                   ->execute([$customer_id, $paymentToken, $cardLast4, $cardBrandFinal, (int)$cardExpMonth, (int)$cardExpYear, $isFirst ? 1 : 0]);
            }
        } catch (\Exception $e) {
            error_log("[efi-card] Save card error (non-blocking): " . $e->getMessage());
        }
    }

    // ═══ CREATE ORDER (same as efi webhook) ═══
    $db->beginTransaction();

    // Re-lock stock and decrement
    foreach ($orderItems as $item) {
        $stmtLock = $db->prepare("SELECT quantity FROM om_market_products WHERE product_id = ? FOR UPDATE");
        $stmtLock->execute([$item['product_id']]);
    }

    // Delivery type
    $delivery_type = 'boraum';
    if ($is_pickup) {
        $delivery_type = 'retirada';
    } elseif ($partner['entrega_propria']) {
        $delivery_type = 'proprio';
    }

    $codigo_entrega = strtoupper(bin2hex(random_bytes(3)));
    $timer_started = date('Y-m-d H:i:s');
    $timer_expires = date('Y-m-d H:i:s', strtotime('+5 minutes'));

    $orderStmt = $db->prepare("INSERT INTO om_market_orders (
        order_number, partner_id, customer_id,
        customer_name, customer_phone, customer_email,
        status, subtotal, delivery_fee, total, tip_amount,
        delivery_address, shipping_address, shipping_cep, shipping_city, shipping_state,
        notes, codigo_entrega, forma_pagamento,
        coupon_id, coupon_discount, loyalty_points_used, loyalty_discount, cashback_discount,
        is_pickup, schedule_date, schedule_time,
        timer_started, timer_expires,
        delivery_type, cpf_nota, service_fee,
        pix_paid, pagamento_status, payment_status,
        payment_id, efi_charge_id, date_added
    ) VALUES (?, ?, ?, ?, ?, ?, 'aceito', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'cartao_efi',
              ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
              false, 'pago', 'paid', ?, ?, NOW())
    RETURNING order_id");

    $orderStmt->execute([
        'SB-TEMP', $partner_id, $customer_id,
        $customer_name, $customer_phone, $customer_email,
        round($subtotal, 2),
        round($delivery_fee, 2),
        round($total, 2),
        round($tip, 2),
        $address, $address,
        $shipping_cep, $shipping_city, $shipping_state,
        $notes, $codigo_entrega,
        $coupon_id ?: null,
        round($coupon_discount, 2),
        $use_points,
        round($pointsDiscount, 2),
        round($cashbackDiscount, 2),
        $is_pickup,
        $schedule_date ?: null,
        $schedule_time ?: null,
        $timer_started, $timer_expires,
        $delivery_type, $cpf_nota ?: null,
        $service_fee,
        "efi_card_{$efiChargeId}", $efiChargeId,
    ]);

    $orderId = (int)$orderStmt->fetchColumn();
    $order_number = 'SB' . str_pad($orderId, 5, '0', STR_PAD_LEFT);
    $db->prepare("UPDATE om_market_orders SET order_number = ? WHERE order_id = ?")->execute([$order_number, $orderId]);

    // Create order items + decrement stock
    $stmtItem = $db->prepare("INSERT INTO om_market_order_items (order_id, product_id, name, quantity, price, total) VALUES (?, ?, ?, ?, ?, ?)");
    foreach ($orderItems as $item) {
        $price = (float)$item['price'];
        $qty = (int)$item['quantity'];
        $itemTotal = round($price * $qty, 2);
        $stmtItem->execute([$orderId, $item['product_id'], $item['name'], $qty, $price, $itemTotal]);
        $db->prepare("UPDATE om_market_products SET quantity = GREATEST(0, quantity - ?) WHERE product_id = ?")
           ->execute([$qty, $item['product_id']]);
    }

    // Coupon usage
    if ($coupon_id > 0) {
        $db->prepare("INSERT INTO om_market_coupon_usage (coupon_id, customer_id, order_id) VALUES (?, ?, ?) ON CONFLICT DO NOTHING")
           ->execute([$coupon_id, $customer_id, $orderId]);
        $db->prepare("UPDATE om_market_coupons SET current_uses = current_uses + 1 WHERE id = ? AND (max_uses IS NULL OR max_uses = 0 OR current_uses < max_uses)")
           ->execute([$coupon_id]);
    }

    // Deduct loyalty points
    if ($use_points > 0) {
        $db->prepare("UPDATE om_market_loyalty_points SET current_points = GREATEST(0, current_points - ?), updated_at = NOW() WHERE customer_id = ? AND current_points >= ?")
           ->execute([$use_points, $customer_id, $use_points]);
        $db->prepare("INSERT INTO om_market_loyalty_transactions (customer_id, points, type, source, reference_id, description, created_at) VALUES (?, ?, 'redeem', 'checkout', ?, ?, NOW())")
           ->execute([$customer_id, -$use_points, $orderId, "Resgate no pedido #{$order_number}"]);
    }

    // Deduct cashback
    if ($cashbackDiscount > 0) {
        $remaining = $cashbackDiscount;
        $cbRows = $db->prepare("SELECT id, COALESCE(amount, 0) as amount FROM om_cashback WHERE customer_id = ? AND type IN ('earned','bonus') AND status = 'available' AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY expires_at ASC NULLS LAST FOR UPDATE");
        $cbRows->execute([$customer_id]);
        foreach ($cbRows->fetchAll() as $cb) {
            if ($remaining <= 0) break;
            $use = min($remaining, (float)$cb['amount']);
            if ($use >= (float)$cb['amount']) {
                $db->prepare("UPDATE om_cashback SET status = 'used', order_id = ? WHERE id = ?")->execute([$orderId, $cb['id']]);
            } else {
                $db->prepare("UPDATE om_cashback SET amount = amount - ? WHERE id = ?")->execute([$use, $cb['id']]);
            }
            $remaining -= $use;
        }
    }

    // Clear cart
    $db->prepare("DELETE FROM om_market_cart WHERE customer_id = ? AND partner_id IN ({$placeholders})")
       ->execute(array_merge([$customer_id], $allPartnerIds));

    $db->commit();

    // ═══ NOTIFICATIONS ═══
    try {
        wsBroadcastToCustomer($customer_id, 'order_update', [
            'order_id' => $orderId,
            'order_number' => $order_number,
            'status' => 'aceito',
            'payment_status' => 'paid',
        ]);
        wsBroadcastToOrder($orderId, 'order_update', [
            'order_id' => $orderId,
            'order_number' => $order_number,
            'status' => 'aceito',
        ]);
    } catch (\Throwable $e) {
        error_log("[efi-card] WS broadcast error: " . $e->getMessage());
    }

    try {
        notifyPartner($db, $partner_id,
            'Novo pedido - Cartao confirmado!',
            "Pedido #{$order_number} - R$ " . number_format($total, 2, ',', '.') . " - {$customer_name}",
            '/painel/mercado/pedidos.php'
        );
    } catch (\Exception $e) {
        error_log("[efi-card] notifyPartner error: " . $e->getMessage());
    }

    try {
        PusherService::newOrder($partner_id, [
            'order_id' => $orderId,
            'order_number' => $order_number,
            'customer_name' => $customer_name,
            'total' => $total,
            'payment_method' => 'cartao_efi',
            'created_at' => date('c'),
        ]);
    } catch (\Exception $e) {
        error_log("[efi-card] Pusher error: " . $e->getMessage());
    }

    error_log("[efi-card] Card payment OK: order #{$orderId} ({$order_number}) charge={$efiChargeId} total=R$ {$total} card=****{$cardLast4}");

    response(true, [
        'order_id' => $orderId,
        'order_number' => $order_number,
        'charge_id' => $efiChargeId,
        'total' => round($total, 2),
        'installments' => $installments,
        'card_last4' => $cardLast4,
        'card_brand' => $cardBrandFinal,
    ], "Pedido confirmado!");

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log("[efi-card] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar pagamento. Tente novamente.", 500);
}
