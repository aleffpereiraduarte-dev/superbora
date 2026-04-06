<?php
/**
 * POST /api/mercado/webhooks/efi.php
 * Webhook receiver para notificacoes PIX da EFI (Efi/Gerencianet)
 *
 * EFI sends POST with body: { "pix": [{ "endToEndId": "...", "txid": "...", "valor": "...", "horario": "..." }] }
 *
 * Two flows:
 * 1. PIX Intent (payment-first): txid starts with "SB" → look up om_pix_intents by txid → create order
 * 2. Legacy order-based PIX: txid contains order_id → update existing order
 *
 * Security: EFI PIX webhooks use mTLS. We also verify the txid against our database.
 */

require_once __DIR__ . '/../config/database.php';
require_once dirname(__DIR__, 3) . '/includes/classes/EfiClient.php';
require_once dirname(__DIR__, 3) . '/includes/classes/OmPricing.php';
require_once dirname(__DIR__, 3) . '/includes/classes/PusherService.php';
require_once dirname(__DIR__) . '/helpers/notify.php';
require_once dirname(__DIR__) . '/helpers/ws-customer-broadcast.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// EFI also sends GET to verify the webhook endpoint exists
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    http_response_code(200);
    echo json_encode(['status' => 'active']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$rawBody = file_get_contents('php://input');
if (!$rawBody) {
    http_response_code(400);
    echo json_encode(['error' => 'Empty body']);
    exit;
}

$payload = EfiClient::parseWebhookPayload($rawBody);
if (!$payload || empty($payload['pix'])) {
    error_log("[efi-webhook] Invalid payload: " . substr($rawBody, 0, 500));
    http_response_code(400);
    echo json_encode(['error' => 'Invalid payload']);
    exit;
}

$pixList = $payload['pix'];
error_log("[efi-webhook] Received " . count($pixList) . " PIX notification(s)");

$efi = new EfiClient();

foreach ($pixList as $pix) {
    $txid = $pix['txid'] ?? '';
    $e2eId = $pix['endToEndId'] ?? '';
    $valor = $pix['valor'] ?? '0';
    $horario = $pix['horario'] ?? '';

    if (empty($txid)) {
        error_log("[efi-webhook] PIX without txid, e2e={$e2eId}");
        continue;
    }

    error_log("[efi-webhook] PIX received: txid={$txid} e2e={$e2eId} valor={$valor}");

    // Verify the charge is really paid by calling EFI API
    $chargeStatus = $efi->checkChargeStatus($txid);
    if (!$chargeStatus['success'] || !$chargeStatus['paid']) {
        error_log("[efi-webhook] Charge {$txid} not confirmed as CONCLUIDA (status={$chargeStatus['status']}) — skipping");
        continue;
    }
    $verifiedAmount = (float)($chargeStatus['paid_amount'] ?? $valor);

    // ═══ WALLET DEPOSIT FLOW ═══
    // Check if this txid matches a pending wallet deposit
    try {
        $db = getDB();

        // Quick non-locking check first (avoid unnecessary transactions)
        $walletCheckStmt = $db->prepare("
            SELECT id, customer_id, amount, status
            FROM om_wallet_deposits
            WHERE txid = ? AND status = 'pending'
            LIMIT 1
        ");
        $walletCheckStmt->execute([$txid]);
        $walletDeposit = $walletCheckStmt->fetch(PDO::FETCH_ASSOC);

        if ($walletDeposit) {
            $db->beginTransaction();

            // Re-fetch with lock inside transaction
            $lockStmt = $db->prepare("SELECT id, customer_id, amount, status FROM om_wallet_deposits WHERE id = ? FOR UPDATE");
            $lockStmt->execute([$walletDeposit['id']]);
            $walletDeposit = $lockStmt->fetch(PDO::FETCH_ASSOC);
            $currentStatus = $walletDeposit['status'] ?? '';

            if ($currentStatus === 'pending') {
                $depositAmount = (float)$walletDeposit['amount'];
                $depositCustomerId = (int)$walletDeposit['customer_id'];

                // Validate amount (tolerance: 5 centavos)
                if (abs($verifiedAmount - $depositAmount) > 0.05) {
                    error_log("[efi-webhook] SECURITY: Wallet deposit amount mismatch for txid={$txid}: paid={$verifiedAmount} expected={$depositAmount}");
                    $db->rollBack();
                    continue;
                }

                // Mark deposit as paid
                $db->prepare("UPDATE om_wallet_deposits SET status = 'paid', paid_at = NOW() WHERE id = ?")
                   ->execute([$walletDeposit['id']]);

                // Credit wallet balance
                $db->prepare("
                    INSERT INTO om_cashback_wallet (customer_id, balance, total_earned)
                    VALUES (?, ?, ?)
                    ON CONFLICT (customer_id) DO UPDATE SET
                        balance = om_cashback_wallet.balance + EXCLUDED.balance,
                        total_earned = om_cashback_wallet.total_earned + EXCLUDED.total_earned
                ")->execute([$depositCustomerId, $depositAmount, $depositAmount]);

                // Get new balance for transaction record
                $balStmt = $db->prepare("SELECT COALESCE(balance, 0) FROM om_cashback_wallet WHERE customer_id = ?");
                $balStmt->execute([$depositCustomerId]);
                $newBalance = (float)$balStmt->fetchColumn();

                // Record cashback transaction
                $db->prepare("
                    INSERT INTO om_cashback_transactions
                    (customer_id, type, amount, balance_after, description)
                    VALUES (?, 'credit', ?, ?, ?)
                ")->execute([
                    $depositCustomerId,
                    $depositAmount,
                    $newBalance,
                    'Deposito via PIX - R$ ' . number_format($depositAmount, 2, ',', '.')
                ]);

                $db->commit();

                // WebSocket broadcast: notify customer of deposit confirmation
                try {
                    if (function_exists('wsBroadcastToCustomer')) {
                        wsBroadcastToCustomer($depositCustomerId, 'wallet_deposit_confirmed', [
                            'deposit_id' => (int)$walletDeposit['id'],
                            'amount' => $depositAmount,
                            'new_balance' => $newBalance,
                            'status' => 'paid',
                        ]);
                    }
                } catch (\Throwable $wsErr) {
                    error_log("[efi-webhook] WS broadcast error (wallet deposit): " . $wsErr->getMessage());
                }

                error_log("[efi-webhook] Wallet deposit #{$walletDeposit['id']} confirmed: R\$ {$depositAmount} for customer #{$depositCustomerId}, new balance: R\$ {$newBalance}");
            } else {
                $db->rollBack();
                error_log("[efi-webhook] Wallet deposit #{$walletDeposit['id']} already processed (status={$currentStatus})");
            }
            continue; // Skip order processing — this was a wallet deposit, not an order
        }
    } catch (\Exception $walletErr) {
        if (isset($db) && $db->inTransaction()) $db->rollBack();
        error_log("[efi-webhook] Wallet deposit check error: " . $walletErr->getMessage());
        // Fall through to order processing — deposit check failure should not block orders
    }

    // ═══ PIX INTENT FLOW (payment-first) ═══
    // Our txids start with "SB" — look up by txid in om_pix_intents
    if (strpos($txid, 'SB') === 0) {
        try {
            $db = getDB();

            $db->beginTransaction();

            // Lock and fetch intent by txid (stored in correlation_id)
            $intentStmt = $db->prepare("
                SELECT * FROM om_pix_intents
                WHERE correlation_id = ? FOR UPDATE
            ");
            $intentStmt->execute([$txid]);
            $intent = $intentStmt->fetch(PDO::FETCH_ASSOC);

            if (!$intent) {
                $db->rollBack();
                error_log("[efi-webhook] Intent not found for txid={$txid}");
                continue;
            }

            // Skip if already processed
            if ($intent['status'] !== 'pending') {
                $db->rollBack();
                error_log("[efi-webhook] Intent already processed: txid={$txid} status={$intent['status']}");
                continue;
            }

            // Mark intent as paid
            $db->prepare("UPDATE om_pix_intents SET status = 'paid', paid_at = NOW() WHERE intent_id = ?")
               ->execute([$intent['intent_id']]);

            // Deserialize cart snapshot
            $cart = json_decode($intent['cart_snapshot'], true);
            if (!$cart) {
                $db->rollBack();
                error_log("[efi-webhook] Invalid cart_snapshot for txid={$txid}");
                continue;
            }

            $customer_id = (int)$cart['customer_id'];
            $partner_id = (int)$cart['partner_id'];
            $items = $cart['items'] ?? [];

            // Validate amount paid matches expected total (tolerance: 5 centavos)
            $expectedTotal = (float)($intent['amount'] ?? $cart['total'] ?? 0);
            if ($expectedTotal > 0 && abs($verifiedAmount - $expectedTotal) > 0.05) {
                error_log("[efi-webhook] SECURITY: Amount mismatch for txid={$txid}: paid={$verifiedAmount} expected={$expectedTotal}");
                $db->rollBack();
                continue;
            }

            // Re-validate and lock stock
            foreach ($items as $item) {
                $stmtLock = $db->prepare("SELECT quantity FROM om_market_products WHERE product_id = ? FOR UPDATE");
                $stmtLock->execute([$item['product_id']]);
                $estoque = (int)$stmtLock->fetchColumn();
                if ((int)$item['quantity'] > $estoque) {
                    error_log("[efi-webhook] WARNING: Stock insufficient for product #{$item['product_id']} ({$item['name']}). Creating order anyway (PIX paid).");
                }
            }

            // Determine delivery type
            $is_pickup = (int)($cart['is_pickup'] ?? 0);
            $delivery_type = 'boraum';
            if ($is_pickup) {
                $delivery_type = 'retirada';
            } else {
                $partnerStmt = $db->prepare("SELECT entrega_propria FROM om_market_partners WHERE partner_id = ?");
                $partnerStmt->execute([$partner_id]);
                $entregaPropria = (bool)$partnerStmt->fetchColumn();
                if ($entregaPropria) $delivery_type = 'proprio';
            }

            // Generate delivery code
            $codigo_entrega = strtoupper(bin2hex(random_bytes(3)));
            $timer_started = date('Y-m-d H:i:s');
            $timer_expires = date('Y-m-d H:i:s', strtotime('+5 minutes'));

            // Create order
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
                round((float)($cart['subtotal'] ?? 0), 2),
                round((float)($cart['delivery_fee'] ?? 0), 2),
                round((float)($cart['total'] ?? 0), 2),
                round((float)($cart['tip'] ?? 0), 2),
                $cart['address'] ?? '', $cart['address'] ?? '',
                $cart['cep'] ?? '', $cart['city'] ?? '', $cart['state'] ?? '',
                $cart['notes'] ?? '', $codigo_entrega,
                (int)($cart['coupon_id'] ?? 0) ?: null,
                round((float)($cart['coupon_discount'] ?? 0), 2),
                (int)($cart['points_used'] ?? 0),
                round((float)($cart['points_discount'] ?? 0), 2),
                round((float)($cart['cashback_discount'] ?? 0), 2),
                $is_pickup,
                ($cart['schedule_date'] ?? '') ?: null,
                ($cart['schedule_time'] ?? '') ?: null,
                $timer_started, $timer_expires,
                $delivery_type, ($cart['cpf_nota'] ?? '') ?: null,
                OmPricing::TAXA_SERVICO,
                $e2eId,
            ]);

            $orderId = (int)$orderStmt->fetchColumn();

            // Generate proper order_number
            $order_number = 'SB' . str_pad($orderId, 5, '0', STR_PAD_LEFT);
            $db->prepare("UPDATE om_market_orders SET order_number = ? WHERE order_id = ?")->execute([$order_number, $orderId]);

            // Create order items and decrement stock
            $stmtItem = $db->prepare("INSERT INTO om_market_order_items (order_id, product_id, name, quantity, price, total) VALUES (?, ?, ?, ?, ?, ?)");
            foreach ($items as $item) {
                $price = (float)$item['price'];
                $qty = (int)$item['quantity'];
                $itemTotal = round($price * $qty, 2);
                $stmtItem->execute([$orderId, $item['product_id'], $item['name'], $qty, $price, $itemTotal]);

                $db->prepare("UPDATE om_market_products SET quantity = GREATEST(0, quantity - ?) WHERE product_id = ?")
                   ->execute([$qty, $item['product_id']]);
            }

            // Register coupon usage
            $coupon_id = (int)($cart['coupon_id'] ?? 0);
            if ($coupon_id > 0) {
                $db->prepare("INSERT INTO om_market_coupon_usage (coupon_id, customer_id, order_id) VALUES (?, ?, ?) ON CONFLICT DO NOTHING")
                   ->execute([$coupon_id, $customer_id, $orderId]);
                $stmtCoupon = $db->prepare("UPDATE om_market_coupons SET current_uses = current_uses + 1 WHERE id = ? AND (max_uses IS NULL OR max_uses = 0 OR current_uses < max_uses)");
                $stmtCoupon->execute([$coupon_id]);
                if ($stmtCoupon->rowCount() === 0) {
                    error_log("[efi-webhook] WARNING: Coupon #{$coupon_id} exceeded max_uses for order (PIX already paid, proceeding)");
                }
            }

            // Deduct loyalty points
            $pointsUsed = (int)($cart['points_used'] ?? 0);
            if ($pointsUsed > 0) {
                $stmtPoints = $db->prepare("UPDATE om_market_loyalty_points SET current_points = GREATEST(0, current_points - ?), updated_at = NOW() WHERE customer_id = ? AND current_points >= ?");
                $stmtPoints->execute([$pointsUsed, $customer_id, $pointsUsed]);
                if ($stmtPoints->rowCount() === 0) {
                    $db->prepare("UPDATE om_market_loyalty_points SET current_points = 0, updated_at = NOW() WHERE customer_id = ? AND current_points > 0")
                       ->execute([$customer_id]);
                }
                $db->prepare("INSERT INTO om_market_loyalty_transactions (customer_id, points, type, source, reference_id, description, created_at) VALUES (?, ?, 'redeem', 'checkout', ?, ?, NOW())")
                   ->execute([$customer_id, -$pointsUsed, $orderId, "Resgate no pedido #{$order_number}"]);
            }

            // Deduct cashback
            $cashbackDiscount = (float)($cart['cashback_discount'] ?? 0);
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

            // Clear cart for this partner
            $db->prepare("DELETE FROM om_market_cart WHERE customer_id = ? AND partner_id = ?")
               ->execute([$customer_id, $partner_id]);

            // Link intent to order
            $db->prepare("UPDATE om_pix_intents SET order_id = ? WHERE intent_id = ?")
               ->execute([$orderId, $intent['intent_id']]);

            $db->commit();

            // WebSocket broadcast
            try {
                if ($customer_id) {
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
                wsBroadcastToOrder($orderId, 'order_update', [
                    'order_id' => $orderId,
                    'order_number' => $order_number,
                    'status' => 'aceito',
                ]);
            } catch (\Throwable $e) {
                error_log("[efi-webhook] WS broadcast error: " . $e->getMessage());
            }

            // Notify partner
            $orderTotal = round((float)($cart['total'] ?? 0), 2);
            $custName = $cart['customer_name'] ?? 'Cliente';

            try {
                notifyPartner($db, $partner_id,
                    'Novo pedido - PIX confirmado!',
                    "Pedido #{$order_number} - R$ " . number_format($orderTotal, 2, ',', '.') . " - {$custName}",
                    '/painel/mercado/pedidos.php'
                );
            } catch (\Exception $e) {
                error_log("[efi-webhook] notifyPartner error: " . $e->getMessage());
            }

            try {
                PusherService::newOrder($partner_id, [
                    'order_id' => $orderId,
                    'order_number' => $order_number,
                    'customer_name' => $custName,
                    'total' => $orderTotal,
                    'payment_method' => 'pix',
                    'pix_paid' => true,
                    'created_at' => date('c'),
                ]);
                PusherService::orderUpdate($partner_id, [
                    'status' => 'aceito',
                    'payment_status' => 'pago',
                    'order_id' => $orderId,
                    'order_number' => $order_number,
                    'message' => 'PIX confirmado! Pedido criado.',
                ]);
                PusherService::trigger("pix-intent-{$intent['intent_id']}", 'paid', [
                    'order_id' => $orderId,
                    'order_number' => $order_number,
                ]);
            } catch (\Exception $e) {
                error_log("[efi-webhook] Pusher error: " . $e->getMessage());
            }

            error_log("[efi-webhook] PIX intent txid={$txid} → order #{$orderId} ({$order_number}) created");

        } catch (\Exception $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log("[efi-webhook] PIX intent error: " . $e->getMessage());
        }
        continue;
    }

    // ═══ LEGACY ORDER-BASED PIX ═══
    // For orders created before migration that used Woovi, or legacy flow
    try {
        $db = getDB();

        // Try to find order by payment_id = txid
        $db->beginTransaction();

        $lockStmt = $db->prepare("SELECT order_id, status, pix_paid, customer_id, partner_id, order_number, total FROM om_market_orders WHERE (payment_id = ? OR pix_code LIKE ?) AND status != 'cancelado' LIMIT 1 FOR UPDATE");
        $lockStmt->execute([$txid, '%' . $txid . '%']);
        $order = $lockStmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            $db->rollBack();
            error_log("[efi-webhook] No order found for txid={$txid} (legacy flow)");
            continue;
        }

        if ($order['pix_paid']) {
            $db->rollBack();
            error_log("[efi-webhook] Order #{$order['order_id']} already paid — skipping");
            continue;
        }

        $orderId = (int)$order['order_id'];

        $db->prepare("UPDATE om_market_orders SET pix_paid = true, pagamento_status = 'pago', payment_status = 'paid', payment_id = ?, status = CASE WHEN status = 'pendente' THEN 'aceito' ELSE status END, date_modified = NOW() WHERE order_id = ?")
           ->execute([$e2eId, $orderId]);

        $db->commit();

        // WebSocket + notifications
        try {
            $customerId = (int)$order['customer_id'];
            if ($customerId) {
                wsBroadcastToCustomer($customerId, 'pix_confirmed', [
                    'order_id' => $orderId,
                    'order_number' => $order['order_number'],
                    'status' => 'aceito',
                    'payment_status' => 'paid',
                ]);
            }
            wsBroadcastToOrder($orderId, 'order_update', [
                'order_id' => $orderId,
                'status' => 'aceito',
                'payment_status' => 'paid',
            ]);
        } catch (\Throwable $e) {}

        try {
            $partnerId = (int)$order['partner_id'];
            notifyPartner($db, $partnerId,
                'PIX confirmado!',
                "Pedido #{$order['order_number']} - PIX de R$ " . number_format((float)$order['total'], 2, ',', '.') . " confirmado",
                '/painel/mercado/pedidos.php'
            );
            PusherService::orderUpdate($partnerId, [
                'status' => 'aceito', 'payment_status' => 'pago',
                'order_id' => $orderId, 'message' => 'PIX confirmado!',
            ]);
        } catch (\Exception $e) {}

        error_log("[efi-webhook] Legacy order #{$orderId} PIX confirmed txid={$txid}");

    } catch (\Exception $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        error_log("[efi-webhook] Legacy flow error: " . $e->getMessage());
    }
}

http_response_code(200);
echo json_encode(['ok' => true]);
