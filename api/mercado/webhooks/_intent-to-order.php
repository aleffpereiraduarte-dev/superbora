<?php
/**
 * _intent-to-order.php — shared helper used by both PIX webhooks
 *
 * Both /webhooks/efi.php and /webhooks/mercadopago.php call processPaidPixIntent()
 * after they've validated the provider-specific signature/status. This file
 * encapsulates the order creation, stock decrement, coupon use, points deduction,
 * cashback consumption, cart clearing and notification fanout in ONE place so the
 * two providers stay in sync.
 *
 * Caller contract:
 *   - $db has been opened (will start its own transaction internally).
 *   - $intent is an array fetched from om_pix_intents (must be status='pending').
 *   - $verifiedAmount is the amount confirmed paid by the provider's API (not
 *     the webhook body).
 *   - $providerRef is a provider-specific identifier we store on the order so we
 *     can correlate later (EFI = endToEndId, MP = payment_id).
 *
 * Returns: int order_id of the newly-created order.
 * Throws:  \RuntimeException on validation/DB failures.
 */

if (!function_exists('processPaidPixIntent')) {

function processPaidPixIntent(PDO $db, array $intent, float $verifiedAmount, string $providerRef): int
{
    // Lock the intent inside its own transaction (idempotency guarantee)
    $db->beginTransaction();
    try {
        // Re-fetch with FOR UPDATE to ensure no parallel webhook beats us
        $stmt = $db->prepare("SELECT intent_id, status, cart_snapshot FROM om_pix_intents WHERE intent_id = ? FOR UPDATE");
        $stmt->execute([$intent['intent_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['status'] !== 'pending') {
            $db->rollBack();
            throw new \RuntimeException('Intent already processed or missing');
        }

        // Mark as paid
        $db->prepare("UPDATE om_pix_intents SET status = 'paid', paid_at = NOW() WHERE intent_id = ?")
            ->execute([$intent['intent_id']]);

        $cart = json_decode($row['cart_snapshot'] ?? '[]', true) ?: [];
        if (empty($cart) || empty($cart['customer_id']) || empty($cart['partner_id']) || empty($cart['items'])) {
            $db->rollBack();
            throw new \RuntimeException('Invalid cart_snapshot');
        }

        $customer_id = (int)$cart['customer_id'];
        $partner_id  = (int)$cart['partner_id'];
        $items       = $cart['items'];
        $is_pickup   = (int)($cart['is_pickup'] ?? 0);

        // Determine delivery type
        $delivery_type = 'boraum';
        if ($is_pickup) {
            $delivery_type = 'retirada';
        } else {
            $partnerStmt = $db->prepare("SELECT entrega_propria FROM om_market_partners WHERE partner_id = ?");
            $partnerStmt->execute([$partner_id]);
            $entregaPropria = (bool)$partnerStmt->fetchColumn();
            if ($entregaPropria) $delivery_type = 'proprio';
        }

        // Generate delivery code + 5-min acceptance timer
        $codigo_entrega  = strtoupper(bin2hex(random_bytes(3)));
        $timer_started   = date('Y-m-d H:i:s');
        $timer_expires   = date('Y-m-d H:i:s', strtotime('+5 minutes'));

        // Insert order
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
            payment_id, date_added
        ) VALUES (?, ?, ?, ?, ?, ?, 'aceito', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pix',
                  ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                  true, 'pago', 'paid', ?, NOW())
        RETURNING order_id");

        $orderStmt->execute([
            'SB-TEMP', $partner_id, $customer_id,
            $cart['customer_name'] ?? 'Cliente', $cart['customer_phone'] ?? '', $cart['customer_email'] ?? '',
            round((float)($cart['subtotal']     ?? 0), 2),
            round((float)($cart['delivery_fee'] ?? 0), 2),
            round((float)($cart['total']        ?? 0), 2),
            round((float)($cart['tip']          ?? 0), 2),
            $cart['address'] ?? '', $cart['address'] ?? '',
            $cart['cep']     ?? '', $cart['city']    ?? '', $cart['state'] ?? '',
            $cart['notes']   ?? '', $codigo_entrega,
            (int)($cart['coupon_id'] ?? 0) ?: null,
            round((float)($cart['coupon_discount']   ?? 0), 2),
            (int)($cart['points_used'] ?? 0),
            round((float)($cart['points_discount']   ?? 0), 2),
            round((float)($cart['cashback_discount'] ?? 0), 2),
            $is_pickup,
            ($cart['schedule_date'] ?? '') ?: null,
            ($cart['schedule_time'] ?? '') ?: null,
            $timer_started, $timer_expires,
            $delivery_type, ($cart['cpf_nota'] ?? '') ?: null,
            class_exists('OmPricing') ? OmPricing::TAXA_SERVICO : 0,
            $providerRef,
        ]);

        $orderId = (int)$orderStmt->fetchColumn();

        // Final order_number from id
        $order_number = 'SB' . str_pad((string)$orderId, 5, '0', STR_PAD_LEFT);
        $db->prepare("UPDATE om_market_orders SET order_number = ? WHERE order_id = ?")->execute([$order_number, $orderId]);

        // Order items + stock decrement
        $stmtItem = $db->prepare("INSERT INTO om_market_order_items (order_id, product_id, name, quantity, price, total) VALUES (?, ?, ?, ?, ?, ?)");
        $stmtStock = $db->prepare("UPDATE om_market_products SET quantity = GREATEST(0, quantity - ?) WHERE product_id = ?");
        foreach ($items as $item) {
            $price = (float)$item['price'];
            $qty   = (int)$item['quantity'];
            $stmtItem->execute([$orderId, $item['product_id'], $item['name'], $qty, $price, round($price * $qty, 2)]);
            $stmtStock->execute([$qty, $item['product_id']]);
        }

        // Coupon usage
        $coupon_id = (int)($cart['coupon_id'] ?? 0);
        if ($coupon_id > 0) {
            $db->prepare("INSERT INTO om_market_coupon_usage (coupon_id, customer_id, order_id) VALUES (?, ?, ?) ON CONFLICT DO NOTHING")
               ->execute([$coupon_id, $customer_id, $orderId]);
            $db->prepare("UPDATE om_market_coupons SET current_uses = current_uses + 1 WHERE id = ? AND (max_uses IS NULL OR max_uses = 0 OR current_uses < max_uses)")
               ->execute([$coupon_id]);
        }

        // Loyalty points deduction
        $pointsUsed = (int)($cart['points_used'] ?? 0);
        if ($pointsUsed > 0) {
            $stmtPts = $db->prepare("UPDATE om_market_loyalty_points SET current_points = GREATEST(0, current_points - ?), updated_at = NOW() WHERE customer_id = ?");
            $stmtPts->execute([$pointsUsed, $customer_id]);
            $db->prepare("INSERT INTO om_market_loyalty_transactions (customer_id, points, type, source, reference_id, description, created_at) VALUES (?, ?, 'redeem', 'checkout', ?, ?, NOW())")
               ->execute([$customer_id, -$pointsUsed, $orderId, "Resgate no pedido #{$order_number}"]);
        }

        // Cashback consumption (FIFO by expiry)
        $cashbackDiscount = (float)($cart['cashback_discount'] ?? 0);
        if ($cashbackDiscount > 0) {
            $remaining = $cashbackDiscount;
            $cbRows = $db->prepare("SELECT id, COALESCE(amount, 0) as amount FROM om_cashback WHERE customer_id = ? AND type IN ('earned','bonus') AND status = 'available' AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY expires_at ASC NULLS LAST FOR UPDATE");
            $cbRows->execute([$customer_id]);
            foreach ($cbRows->fetchAll(PDO::FETCH_ASSOC) as $cb) {
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

        // Clear cart for this partner
        $db->prepare("DELETE FROM om_market_cart WHERE customer_id = ? AND partner_id = ?")
           ->execute([$customer_id, $partner_id]);

        // Link intent → order
        $db->prepare("UPDATE om_pix_intents SET order_id = ? WHERE intent_id = ?")
           ->execute([$orderId, $intent['intent_id']]);

        $db->commit();

        // Best-effort fanout — never bubble exceptions to the webhook caller
        try {
            if (function_exists('wsBroadcastToCustomer')) {
                wsBroadcastToCustomer($customer_id, 'pix_confirmed', [
                    'order_id' => $orderId,
                    'order_number' => $order_number,
                    'status' => 'aceito',
                    'payment_status' => 'paid',
                ]);
                wsBroadcastToCustomer($customer_id, 'order_update', [
                    'order_id' => $orderId,
                    'order_number' => $order_number,
                    'status' => 'aceito',
                ]);
            }
            if (function_exists('wsBroadcastToOrder')) {
                wsBroadcastToOrder($orderId, 'order_update', [
                    'order_id' => $orderId,
                    'order_number' => $order_number,
                    'status' => 'aceito',
                ]);
            }
        } catch (\Throwable $e) {
            error_log('[intent-to-order] WS broadcast failed: ' . $e->getMessage());
        }

        try {
            if (function_exists('notifyPartner')) {
                notifyPartner($db, $partner_id, 'novo_pedido_pago', [
                    'order_id' => $orderId,
                    'order_number' => $order_number,
                    'customer_name' => $cart['customer_name'] ?? 'Cliente',
                    'total' => round((float)($cart['total'] ?? 0), 2),
                ]);
            }
        } catch (\Throwable $e) {
            error_log('[intent-to-order] notifyPartner failed: ' . $e->getMessage());
        }

        return $orderId;
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

}
