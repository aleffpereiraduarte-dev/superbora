<?php
/**
 * POST /api/mercado/webhooks/woovi.php
 * Webhook receiver para callbacks de payout da Woovi (OpenPix)
 *
 * Eventos:
 *   - OPENPIX:TRANSFER_COMPLETED → PIX enviado com sucesso
 *   - OPENPIX:TRANSFER_FAILED    → PIX falhou
 *
 * Seguranca: valida x-webhook-secret header
 */

require_once __DIR__ . '/../config/database.php';
require_once dirname(__DIR__, 3) . '/includes/classes/WooviClient.php';
require_once dirname(__DIR__, 3) . '/includes/classes/PusherService.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
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

// Verificar assinatura — Woovi uses RSA public key verification
$signature = $_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? $_SERVER['HTTP_X_OPENPIX_SIGNATURE'] ?? '';
$publicKey = $_ENV['WOOVI_WEBHOOK_PUBLIC_KEY'] ?? getenv('WOOVI_WEBHOOK_PUBLIC_KEY') ?: '';

if (empty($publicKey)) {
    error_log("[woovi-webhook] CRITICAL: WOOVI_WEBHOOK_PUBLIC_KEY not configured — rejecting");
    http_response_code(500);
    echo json_encode(['error' => 'Webhook not configured']);
    exit;
}

if (empty($signature)) {
    error_log("[woovi-webhook] Rejected: no signature header");
    http_response_code(401);
    echo json_encode(['error' => 'Missing signature']);
    exit;
}

if (!WooviClient::verifyWebhookSignature($rawBody, $signature, $publicKey)) {
    error_log("[woovi-webhook] Rejected: invalid signature — sig: " . substr($signature, 0, 20) . "...");
    http_response_code(401);
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

$payload = json_decode($rawBody, true);
if (!$payload) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

$event = $payload['event'] ?? $payload['type'] ?? '';
$transfer = $payload['transfer'] ?? $payload['pix'] ?? $payload;
$charge = $payload['charge'] ?? null;
$correlationId = $transfer['correlationID'] ?? $transfer['correlation_id'] ?? ($charge['correlationID'] ?? '');

error_log("[woovi-webhook] Evento: $event | correlationID: $correlationId");

if (empty($correlationId)) {
    http_response_code(200);
    echo json_encode(['ok' => true, 'message' => 'No correlationID, ignored']);
    exit;
}

// ═══ PIX INTENT EVENTS (payment-first flow) ═══
if (stripos($event, 'CHARGE') !== false && strpos($correlationId, 'pix_intent_') === 0) {
    try {
        $db = getDB();
        require_once dirname(__DIR__) . '/helpers/notify.php';
        require_once dirname(__DIR__, 3) . '/includes/classes/OmPricing.php';

        if (stripos($event, 'COMPLETED') !== false || stripos($event, 'CONFIRMED') !== false) {
            error_log("[woovi-webhook] PIX intent COMPLETED: $correlationId");

            $db->beginTransaction();

            // Lock and fetch intent
            $intentStmt = $db->prepare("
                SELECT * FROM om_pix_intents
                WHERE correlation_id = ? FOR UPDATE
            ");
            $intentStmt->execute([$correlationId]);
            $intent = $intentStmt->fetch(PDO::FETCH_ASSOC);

            if (!$intent) {
                $db->rollBack();
                error_log("[woovi-webhook] Intent not found: $correlationId");
                http_response_code(200);
                echo json_encode(['ok' => true, 'message' => 'Intent not found']);
                exit;
            }

            // Skip if already processed
            if ($intent['status'] !== 'pending') {
                $db->rollBack();
                error_log("[woovi-webhook] Intent already processed: $correlationId (status: {$intent['status']})");
                http_response_code(200);
                echo json_encode(['ok' => true, 'message' => 'Already processed']);
                exit;
            }

            // Mark intent as paid
            $db->prepare("UPDATE om_pix_intents SET status = 'paid', paid_at = NOW() WHERE intent_id = ?")
               ->execute([$intent['intent_id']]);

            // Deserialize cart snapshot
            $cart = json_decode($intent['cart_snapshot'], true);
            if (!$cart) {
                $db->rollBack();
                error_log("[woovi-webhook] Invalid cart_snapshot for intent: $correlationId");
                http_response_code(200);
                echo json_encode(['ok' => true, 'message' => 'Invalid cart']);
                exit;
            }

            $customer_id = (int)$cart['customer_id'];
            $partner_id = (int)$cart['partner_id'];
            $items = $cart['items'] ?? [];

            // Re-validate and lock stock
            foreach ($items as $item) {
                $stmtLock = $db->prepare("SELECT quantity FROM om_market_products WHERE product_id = ? FOR UPDATE");
                $stmtLock->execute([$item['product_id']]);
                $estoque = (int)$stmtLock->fetchColumn();
                if ((int)$item['quantity'] > $estoque) {
                    // Stock insufficient after payment — create order anyway but log warning
                    error_log("[woovi-webhook] WARNING: Stock insufficient for product #{$item['product_id']} ({$item['name']}). Needed: {$item['quantity']}, Available: {$estoque}. Creating order anyway (PIX paid).");
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
                date_added
            ) VALUES (?, ?, ?, ?, ?, ?, 'aceito', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pix',
                      ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                      true, 'pago', 'paid', NOW())
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

                // Decrement stock (defensive WHERE)
                $db->prepare("UPDATE om_market_products SET quantity = GREATEST(0, quantity - ?) WHERE product_id = ?")
                   ->execute([$qty, $item['product_id']]);
            }

            // Register coupon usage
            $coupon_id = (int)($cart['coupon_id'] ?? 0);
            if ($coupon_id > 0) {
                $db->prepare("INSERT INTO om_market_coupon_usage (coupon_id, customer_id, order_id) VALUES (?, ?, ?) ON CONFLICT DO NOTHING")
                   ->execute([$coupon_id, $customer_id, $orderId]);
                $db->prepare("UPDATE om_market_coupons SET current_uses = current_uses + 1 WHERE id = ?")->execute([$coupon_id]);
            }

            // Deduct loyalty points
            $pointsUsed = (int)($cart['points_used'] ?? 0);
            if ($pointsUsed > 0) {
                $db->prepare("UPDATE om_market_loyalty_points SET current_points = GREATEST(0, current_points - ?), updated_at = NOW() WHERE customer_id = ?")
                   ->execute([$pointsUsed, $customer_id]);
                $db->prepare("INSERT INTO om_market_loyalty_transactions (customer_id, points, type, source, reference_id, description, created_at) VALUES (?, ?, 'redeem', 'checkout', ?, ?, NOW())")
                   ->execute([$customer_id, -$pointsUsed, $orderId, "Resgate no pedido #$order_number"]);
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

            // Clear cart
            $db->prepare("DELETE FROM om_market_cart WHERE customer_id = ? AND partner_id = ?")
               ->execute([$customer_id, $partner_id]);

            // Link intent to order
            $db->prepare("UPDATE om_pix_intents SET order_id = ? WHERE intent_id = ?")
               ->execute([$orderId, $intent['intent_id']]);

            $db->commit();

            // Notify partner
            $partnerName = $cart['partner_name'] ?? '';
            $custName = $cart['customer_name'] ?? 'Cliente';
            $orderTotal = round((float)($cart['total'] ?? 0), 2);

            try {
                notifyPartner($db, $partner_id,
                    'Novo pedido - PIX confirmado!',
                    "Pedido #{$order_number} - R$ " . number_format($orderTotal, 2, ',', '.') . " - {$custName}",
                    '/painel/mercado/pedidos.php'
                );
            } catch (\Exception $e) {
                error_log("[woovi-webhook] notifyPartner intent erro: " . $e->getMessage());
            }

            try {
                PusherService::newOrder($partner_id, [
                    'order_id' => $orderId,
                    'order_number' => $order_number,
                    'customer_name' => $custName,
                    'total' => $orderTotal,
                    'payment_method' => 'pix',
                    'pix_paid' => true,
                    'created_at' => date('c')
                ]);
            } catch (\Exception $e) {
                error_log("[woovi-webhook] Pusher newOrder intent erro: " . $e->getMessage());
            }

            // Notify customer via Pusher (with order_id so frontend can navigate)
            try {
                PusherService::orderUpdate($orderId, [
                    'status' => 'aceito',
                    'payment_status' => 'pago',
                    'order_id' => $orderId,
                    'order_number' => $order_number,
                    'message' => 'PIX confirmado! Pedido criado.'
                ]);
                // Also push to intent channel so polling frontend catches it
                PusherService::trigger("pix-intent-{$intent['intent_id']}", 'paid', [
                    'order_id' => $orderId,
                    'order_number' => $order_number,
                ]);
            } catch (\Exception $e) {
                error_log("[woovi-webhook] Pusher intent erro: " . $e->getMessage());
            }

            error_log("[woovi-webhook] PIX intent $correlationId → order #{$orderId} ({$order_number}) created");

        } elseif (stripos($event, 'EXPIRED') !== false || stripos($event, 'FAILED') !== false) {
            error_log("[woovi-webhook] PIX intent EXPIRED/FAILED: $correlationId");
            $db = getDB();
            $db->prepare("UPDATE om_pix_intents SET status = 'expired' WHERE correlation_id = ? AND status = 'pending'")
               ->execute([$correlationId]);
        }

        http_response_code(200);
        echo json_encode(['ok' => true, 'event' => $event, 'type' => 'pix_intent']);
        exit;
    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        error_log("[woovi-webhook] PIX intent error: " . $e->getMessage());
        http_response_code(200);
        echo json_encode(['ok' => true, 'error' => 'Internal processing error']);
        exit;
    }
}

// ═══ CHARGE EVENTS (PIX payment from customer — legacy order flow) ═══
if (stripos($event, 'CHARGE') !== false || ($charge && strpos($correlationId, 'order_') === 0)) {
    try {
        $db = getDB();

        // Extract order_id from correlationId: "order_{id}_{timestamp}"
        preg_match('/order_(\d+)_/', $correlationId, $m);
        $orderId = $m[1] ?? 0;

        if (!$orderId) {
            error_log("[woovi-webhook] Could not extract order_id from correlationId: $correlationId");
            http_response_code(200);
            echo json_encode(['ok' => true, 'message' => 'No order_id found']);
            exit;
        }

        if (stripos($event, 'COMPLETED') !== false || stripos($event, 'CONFIRMED') !== false) {
            // PIX pago pelo cliente — confirmar pedido
            error_log("[woovi-webhook] Charge COMPLETED for order #{$orderId}");

            $db->beginTransaction();

            // Lock the order row and check current state (idempotency + race condition prevention)
            $lockStmt = $db->prepare("SELECT order_id, status, pix_paid FROM om_market_orders WHERE order_id = ? FOR UPDATE");
            $lockStmt->execute([$orderId]);
            $orderLock = $lockStmt->fetch();

            if (!$orderLock) {
                $db->rollBack();
                error_log("[woovi-webhook] Order #{$orderId} not found");
                http_response_code(200);
                echo json_encode(['ok' => true, 'message' => 'Order not found']);
                exit;
            }

            // Idempotency: skip if already paid
            if ($orderLock['pix_paid']) {
                $db->rollBack();
                error_log("[woovi-webhook] Order #{$orderId} already paid — skipping duplicate");
                http_response_code(200);
                echo json_encode(['ok' => true, 'message' => 'Already processed']);
                exit;
            }

            // Atualizar pedido: pix confirmado
            $db->prepare("UPDATE om_market_orders SET pix_paid = true, pagamento_status = 'pago', payment_status = 'paid', status = CASE WHEN status = 'pendente' THEN 'aceito' ELSE status END, date_modified = NOW() WHERE order_id = ?")->execute([$orderId]);

            // Atualizar transacao
            $db->prepare("UPDATE om_pagarme_transacoes SET status = 'paid' WHERE pedido_id = ? AND tipo = 'pix'")->execute([$orderId]);

            // Buscar dados do pedido para notificar parceiro
            $orderData = $db->prepare("SELECT partner_id, order_number, total, customer_id FROM om_market_orders WHERE order_id = ?");
            $orderData->execute([$orderId]);
            $orderInfo = $orderData->fetch();

            $db->commit();

            // Notificar parceiro AGORA que PIX foi confirmado
            if ($orderInfo) {
                $partnerId = (int)$orderInfo['partner_id'];
                $orderNumber = $orderInfo['order_number'];
                $orderTotal = (float)$orderInfo['total'];

                // Buscar nome do cliente
                $custStmt = $db->prepare("SELECT COALESCE(name, firstname || ' ' || lastname) as name FROM om_customers WHERE customer_id = ?");
                $custStmt->execute([$orderInfo['customer_id']]);
                $custName = $custStmt->fetchColumn() ?: 'Cliente';

                try {
                    require_once dirname(__DIR__) . '/helpers/notificar.php';
                    notifyPartner($db, $partnerId,
                        'Novo pedido - PIX confirmado!',
                        "Pedido #{$orderNumber} - R$ " . number_format($orderTotal, 2, ',', '.') . " - {$custName}",
                        '/painel/mercado/pedidos.php'
                    );
                } catch (\Exception $e) {
                    error_log("[woovi-webhook] notifyPartner erro: " . $e->getMessage());
                }

                try {
                    PusherService::newOrder($partnerId, [
                        'order_id' => $orderId,
                        'order_number' => $orderNumber,
                        'customer_name' => $custName,
                        'total' => $orderTotal,
                        'payment_method' => 'pix',
                        'pix_paid' => true,
                        'created_at' => date('c')
                    ]);
                } catch (\Exception $e) {
                    error_log("[woovi-webhook] Pusher newOrder erro: " . $e->getMessage());
                }
            }

            // Notificar cliente via Pusher
            try {
                PusherService::orderUpdate($orderId, [
                    'status' => 'aceito',
                    'payment_status' => 'pago',
                    'message' => 'PIX confirmado!'
                ]);
            } catch (\Exception $e) {
                error_log("[woovi-webhook] Pusher erro: " . $e->getMessage());
            }
        } elseif (stripos($event, 'EXPIRED') !== false || stripos($event, 'FAILED') !== false) {
            error_log("[woovi-webhook] Charge EXPIRED/FAILED for order #{$orderId}");

            $db->beginTransaction();

            // Lock and verify order is still pendente (idempotency — prevent double stock restore)
            $lockExpired = $db->prepare("SELECT order_id, status FROM om_market_orders WHERE order_id = ? FOR UPDATE");
            $lockExpired->execute([$orderId]);
            $expOrder = $lockExpired->fetch();

            if (!$expOrder || $expOrder['status'] !== 'pendente') {
                $db->rollBack();
                error_log("[woovi-webhook] Order #{$orderId} not pendente (status={$expOrder['status']}) — skipping expire");
            } else {
                $db->prepare("UPDATE om_market_orders SET status = 'cancelado', cancel_reason = 'PIX expirado', cancelled_at = NOW(), date_modified = NOW() WHERE order_id = ?")->execute([$orderId]);
                // Restore stock
                $items = $db->prepare("SELECT product_id, quantity FROM om_market_order_items WHERE order_id = ?");
                $items->execute([$orderId]);
                foreach ($items->fetchAll() as $item) {
                    if ($item['product_id']) {
                        $db->prepare("UPDATE om_market_products SET quantity = quantity + ? WHERE product_id = ?")->execute([$item['quantity'], $item['product_id']]);
                    }
                }
                $db->commit();
            }
        }

        http_response_code(200);
        echo json_encode(['ok' => true, 'event' => $event]);
        exit;
    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        error_log("[woovi-webhook] Charge error: " . $e->getMessage());
        http_response_code(200);
        echo json_encode(['ok' => true, 'error' => 'Internal processing error']);
        exit;
    }
}

// ═══ PAYOUT/TRANSFER EVENTS (PIX sent to partner) ═══
try {
    $db = getDB();
    $db->beginTransaction();

    // Buscar payout com lock
    $stmt = $db->prepare("
        SELECT id, partner_id, amount, status
        FROM om_woovi_payouts
        WHERE correlation_id = ?
        FOR UPDATE
    ");
    $stmt->execute([$correlationId]);
    $payout = $stmt->fetch();

    if (!$payout) {
        $db->rollBack();
        error_log("[woovi-webhook] Payout nao encontrado: $correlationId");
        http_response_code(200);
        echo json_encode(['ok' => true, 'message' => 'Payout not found']);
        exit;
    }

    // Ignorar se ja em estado terminal
    if (in_array($payout['status'], ['completed', 'failed', 'refunded'])) {
        $db->rollBack();
        http_response_code(200);
        echo json_encode(['ok' => true, 'message' => 'Already terminal']);
        exit;
    }

    $partnerId = (int)$payout['partner_id'];
    $amount = (float)$payout['amount'];
    $payoutId = (int)$payout['id'];

    if (stripos($event, 'COMPLETED') !== false || stripos($event, 'completed') !== false) {
        // PIX enviado com sucesso
        $wooviTxId = $transfer['transactionID'] ?? $transfer['endToEndId'] ?? '';

        $stmtUp = $db->prepare("
            UPDATE om_woovi_payouts
            SET status = 'completed',
                woovi_transaction_id = ?,
                processed_at = NOW(),
                woovi_raw_response = ?
            WHERE id = ?
        ");
        $stmtUp->execute([$wooviTxId, $rawBody, $payoutId]);

        // Atualizar total_sacado em om_mercado_saldo
        $stmtSaldo = $db->prepare("
            UPDATE om_mercado_saldo
            SET total_sacado = COALESCE(total_sacado, 0) + ?,
                updated_at = NOW()
            WHERE partner_id = ?
        ");
        $stmtSaldo->execute([$amount, $partnerId]);

        // Log no wallet
        $stmtLog = $db->prepare("
            INSERT INTO om_mercado_wallet (partner_id, tipo, valor, descricao, status, created_at)
            VALUES (?, 'saque_confirmado', ?, ?, 'completed', NOW())
        ");
        $stmtLog->execute([$partnerId, $amount, "PIX confirmado - Woovi #$correlationId"]);

        $db->commit();

        // Notificar parceiro via Pusher
        try {
            PusherService::payoutUpdate($partnerId, [
                'payout_id' => $payoutId,
                'amount' => $amount,
                'status' => 'completed',
                'message' => 'Saque PIX enviado com sucesso!'
            ]);
        } catch (\Exception $e) {
            error_log("[woovi-webhook] Pusher erro: " . $e->getMessage());
        }

        error_log("[woovi-webhook] Payout $correlationId COMPLETED para parceiro $partnerId");

    } elseif (stripos($event, 'FAILED') !== false || stripos($event, 'failed') !== false) {
        // PIX falhou - devolver saldo
        $failReason = $transfer['reason'] ?? $transfer['failReason'] ?? 'Falha no envio PIX';

        $stmtUp = $db->prepare("
            UPDATE om_woovi_payouts
            SET status = 'failed',
                failure_reason = ?,
                processed_at = NOW(),
                woovi_raw_response = ?
            WHERE id = ?
        ");
        $stmtUp->execute([$failReason, $rawBody, $payoutId]);

        // Devolver saldo
        $stmtSaldo = $db->prepare("
            UPDATE om_mercado_saldo
            SET saldo_disponivel = saldo_disponivel + ?,
                updated_at = NOW()
            WHERE partner_id = ?
        ");
        $stmtSaldo->execute([$amount, $partnerId]);

        // Log no wallet
        $stmtLog = $db->prepare("
            INSERT INTO om_mercado_wallet (partner_id, tipo, valor, descricao, status, created_at)
            VALUES (?, 'saque_estornado', ?, ?, 'refunded', NOW())
        ");
        $stmtLog->execute([$partnerId, $amount, "PIX falhou - saldo devolvido: $failReason"]);

        $db->commit();

        // Notificar parceiro
        try {
            PusherService::payoutUpdate($partnerId, [
                'payout_id' => $payoutId,
                'amount' => $amount,
                'status' => 'failed',
                'message' => "Saque falhou: $failReason. Saldo devolvido."
            ]);
        } catch (\Exception $e) {
            error_log("[woovi-webhook] Pusher erro: " . $e->getMessage());
        }

        error_log("[woovi-webhook] Payout $correlationId FAILED para parceiro $partnerId: $failReason");

    } else {
        // Evento desconhecido - ignorar
        $db->rollBack();
        error_log("[woovi-webhook] Evento desconhecido: $event");
    }

    http_response_code(200);
    echo json_encode(['ok' => true]);

} catch (\Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("[woovi-webhook] Erro: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal error']);
}
