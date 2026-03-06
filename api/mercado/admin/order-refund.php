<?php
/**
 * POST /api/mercado/admin/order-refund.php
 *
 * Processa reembolso real (Stripe / PIX / cashback) a partir do painel admin.
 *
 * Body: {
 *   order_id: int,
 *   amount: float,        // valor em reais (ex: 25.50)
 *   reason?: string,
 *   refund_items?: [{ item_id: int, quantity: int }],  // reembolso por item (opcional)
 *   skip_gateway?: bool   // true = so marca DB, nao chama Stripe/PIX (default false)
 * }
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";
require_once __DIR__ . "/../helpers/ws-customer-broadcast.php";
require_once __DIR__ . "/../helpers/notify.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();
    $admin_id = (int)$payload['uid'];

    if ($_SERVER["REQUEST_METHOD"] !== "POST") response(false, null, "Metodo nao permitido", 405);

    $input = getInput();
    $order_id = (int)($input['order_id'] ?? 0);
    $reason = strip_tags(trim($input['reason'] ?? ''));
    $skip_gateway = (bool)($input['skip_gateway'] ?? false);
    $refund_items = $input['refund_items'] ?? null;

    // If refund_items provided, calculate amount from items
    $amount = 0;
    if (is_array($refund_items) && count($refund_items) > 0) {
        // Will calculate below after fetching order items
    } else {
        $amount = (float)($input['amount'] ?? 0);
        $refund_items = null;
    }

    if (!$order_id) response(false, null, "order_id obrigatorio", 400);
    if (!$refund_items && $amount <= 0) response(false, null, "amount ou refund_items obrigatorio", 400);

    $db->beginTransaction();

    // SECURITY: Lock order row to prevent double-refund race condition
    $stmt = $db->prepare("SELECT * FROM om_market_orders WHERE order_id = ? FOR UPDATE");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch();
    if (!$order) { $db->rollBack(); response(false, null, "Pedido nao encontrado", 404); }

    if ($order['status'] === 'refunded') {
        $db->rollBack();
        response(false, null, "Pedido ja foi totalmente reembolsado", 409);
    }

    // Item-level refund: calculate amount from items
    $refunded_items_detail = [];
    if ($refund_items) {
        foreach ($refund_items as $ri) {
            $item_id = (int)($ri['item_id'] ?? 0);
            $qty = (int)($ri['quantity'] ?? 1);
            if (!$item_id || $qty < 1) continue;

            $stmt = $db->prepare("SELECT * FROM om_market_order_items WHERE id = ? AND order_id = ?");
            $stmt->execute([$item_id, $order_id]);
            $item = $stmt->fetch();
            if (!$item) continue;

            $maxQty = (int)$item['quantity'] - (int)($item['refunded_quantity'] ?? 0);
            $qty = min($qty, $maxQty);
            if ($qty < 1) continue;

            $itemRefund = round((float)$item['price'] * $qty, 2);
            $amount += $itemRefund;

            // Track refunded quantity on item
            try {
                $db->prepare("UPDATE om_market_order_items SET refunded_quantity = COALESCE(refunded_quantity, 0) + ? WHERE id = ?")->execute([$qty, $item_id]);
            } catch (\Exception $e) {
                // refunded_quantity column may not exist yet
            }

            $refunded_items_detail[] = [
                'item_id' => $item_id,
                'product_name' => $item['product_name'] ?? $item['name'] ?? '',
                'quantity' => $qty,
                'amount' => $itemRefund,
            ];
        }
        if ($amount <= 0) { $db->rollBack(); response(false, null, "Nenhum item valido para reembolso", 400); }
    }

    $previousRefund = (float)($order['refund_amount'] ?? 0);
    $orderTotal = (float)$order['total'];

    if (($previousRefund + $amount) > ($orderTotal + 0.01)) {
        $db->rollBack();
        response(false, null, "Valor do reembolso excede o total do pedido (ja reembolsado: R$ " . number_format($previousRefund, 2, ',', '.') . ")", 400);
    }

    $newRefundTotal = $previousRefund + $amount;
    $isFullyRefunded = (abs($newRefundTotal - $orderTotal) < 0.01);
    $newStatus = $isFullyRefunded ? 'refunded' : $order['status'];

    // Update order with refund amount and conditionally update status
    $stmt = $db->prepare("UPDATE om_market_orders SET refund_amount = ?, status = ?, updated_at = NOW() WHERE order_id = ?");
    $stmt->execute([$newRefundTotal, $newStatus, $order_id]);

    // Only mark sale as refunded if fully refunded
    if ($isFullyRefunded) {
        try {
            $db->prepare("UPDATE om_market_sales SET status = 'refunded' WHERE order_id = ?")->execute([$order_id]);
        } catch (\Exception $e) { /* table may not exist */ }
    }

    // Create refund record in om_market_refunds
    $refund_record_id = null;
    try {
        $stmt = $db->prepare("
            INSERT INTO om_market_refunds (order_id, customer_id, amount, reason, status, created_at, reviewed_at, reviewed_by)
            VALUES (?, ?, ?, ?, 'approved', NOW(), NOW(), ?)
            RETURNING id
        ");
        $stmt->execute([$order_id, $order['customer_id'], $amount, $reason ?: 'Reembolso admin', $admin_id]);
        $row = $stmt->fetch();
        $refund_record_id = $row ? (int)$row['id'] : null;
    } catch (\Exception $e) {
        error_log("[admin/order-refund] Refund record error: " . $e->getMessage());
    }

    // Timeline entry
    $refundType = $isFullyRefunded ? "total" : "parcial";
    $desc = "Reembolso {$refundType} de R$ " . number_format($amount, 2, ',', '.') . " processado (total reembolsado: R$ " . number_format($newRefundTotal, 2, ',', '.') . ")";
    if ($reason) $desc .= " - Motivo: {$reason}";
    if ($refunded_items_detail) {
        $itemNames = array_map(fn($i) => "{$i['quantity']}x {$i['product_name']}", $refunded_items_detail);
        $desc .= " - Itens: " . implode(', ', $itemNames);
    }

    $stmt = $db->prepare("
        INSERT INTO om_order_timeline (order_id, status, description, actor_type, actor_id, created_at)
        VALUES (?, ?, ?, 'admin', ?, NOW())
    ");
    $timelineStatus = $isFullyRefunded ? 'refunded' : 'partial_refund';
    $stmt->execute([$order_id, $timelineStatus, $desc, $admin_id]);

    $db->commit();

    // === AFTER COMMIT: Process actual payment gateway refund ===
    $gateway_status = 'db_only';
    $gateway_message = '';
    $stripe_refund_id = null;

    if (!$skip_gateway) {
        $paymentMethod = $order['forma_pagamento'] ?? $order['payment_method'] ?? '';
        $stripePi = $order['stripe_payment_intent_id'] ?? $order['payment_id'] ?? '';

        // --- STRIPE REFUND ---
        if (in_array($paymentMethod, ['stripe_card', 'stripe_wallet', 'credito', 'cartao_credito', 'cartao_debito', 'apple_pay', 'google_pay']) && $stripePi) {
            $stripeResult = refundStripeAdmin($stripePi, $order_id, $amount, $isFullyRefunded, $reason);
            $gateway_status = $stripeResult['status'];
            $gateway_message = $stripeResult['message'];
            $stripe_refund_id = $stripeResult['refund_id'] ?? null;
        }
        // --- PIX REFUND (via Woovi/OpenPix) ---
        elseif ($paymentMethod === 'pix') {
            $pixPaid = ($order['pagamento_status'] ?? '') === 'pago'
                    || ($order['payment_status'] ?? '') === 'paid'
                    || ($order['pix_paid'] ?? false);

            if ($pixPaid) {
                $pixResult = refundPixAdmin($db, $order_id, $amount, $reason);
                $gateway_status = $pixResult['status'];
                $gateway_message = $pixResult['message'];
            } else {
                $gateway_status = 'not_charged';
                $gateway_message = 'PIX nao foi pago, reembolso apenas no DB';
            }
        }
        // --- DINHEIRO / CARTAO NA ENTREGA ---
        elseif (in_array($paymentMethod, ['dinheiro', 'cartao_entrega'])) {
            $gateway_status = 'manual';
            $gateway_message = 'Pagamento na entrega — reembolso deve ser feito manualmente';
        }
        // --- UNKNOWN ---
        else {
            $gateway_status = 'unknown_method';
            $gateway_message = "Metodo de pagamento '{$paymentMethod}' nao suporta reembolso automatico";
        }
    } else {
        $gateway_status = 'skipped';
        $gateway_message = 'Gateway ignorado (skip_gateway=true)';
    }

    // Update refund record with gateway status
    if ($refund_record_id) {
        try {
            $db->prepare("UPDATE om_market_refunds SET admin_note = ? WHERE id = ?")->execute([$gateway_message, $refund_record_id]);
        } catch (\Exception $e) { /* admin_note column may not exist */ }
    }

    // Audit log
    om_audit()->log('refund', 'order', $order_id,
        ['refund_amount' => $previousRefund, 'status' => $order['status']],
        ['refund_amount' => $newRefundTotal, 'status' => $newStatus, 'amount' => $amount, 'gateway_status' => $gateway_status, 'reason' => $reason, 'items' => $refunded_items_detail ?: null],
        $desc
    );

    // Notify customer
    $customer_id = (int)$order['customer_id'];
    $orderNum = $order['order_number'] ?? "#{$order_id}";
    try {
        notifyCustomer($db, $customer_id,
            "Reembolso processado — Pedido {$orderNum}",
            "Seu reembolso de R$ " . number_format($amount, 2, ',', '.') . " foi processado." . ($reason ? " Motivo: {$reason}" : ""),
            '/pedidos',
            ['order_id' => $order_id, 'type' => 'refund_processed']
        );
    } catch (\Exception $e) {
        error_log("[admin/order-refund] Notify error: " . $e->getMessage());
    }

    // WebSocket broadcast
    try {
        wsBroadcastToCustomer($customer_id, 'order_update', [
            'order_id' => $order_id,
            'status' => $newStatus,
            'refund_amount' => $amount,
        ]);
    } catch (\Throwable $e) {}

    response(true, [
        'order_id' => $order_id,
        'refund_amount' => $amount,
        'total_refunded' => $newRefundTotal,
        'is_fully_refunded' => $isFullyRefunded,
        'new_status' => $newStatus,
        'gateway_status' => $gateway_status,
        'gateway_message' => $gateway_message,
        'stripe_refund_id' => $stripe_refund_id,
        'refund_record_id' => $refund_record_id,
        'refunded_items' => $refunded_items_detail ?: null,
    ], "Reembolso processado — {$gateway_message}");

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log("[admin/order-refund] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}

// ============================================================
// Gateway refund functions
// ============================================================

/**
 * Stripe refund via curl (partial or full)
 */
function refundStripeAdmin(string $paymentIntentId, int $orderId, float $amount, bool $isFull, string $reason): array {
    $stripeEnv = dirname(__DIR__, 3) . '/.env.stripe';
    $STRIPE_SK = '';
    if (file_exists($stripeEnv)) {
        foreach (file($stripeEnv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            if (strpos($line, '=') !== false) {
                [$key, $value] = explode('=', $line, 2);
                if (trim($key) === 'STRIPE_SECRET_KEY') $STRIPE_SK = trim($value);
            }
        }
    }
    if (empty($STRIPE_SK)) {
        return ['status' => 'config_error', 'message' => 'Stripe secret key nao configurada', 'refund_id' => null];
    }

    $postFields = [
        'payment_intent' => $paymentIntentId,
        'reason' => 'requested_by_customer',
        'metadata[order_id]' => $orderId,
        'metadata[source]' => 'superbora_admin_refund',
        'metadata[admin_reason]' => substr($reason, 0, 500),
    ];

    // Partial refund: pass amount in centavos
    if (!$isFull) {
        $postFields['amount'] = (int)round($amount * 100);
    }

    $idempotencyKey = "refund_admin_{$orderId}_" . md5($amount . '_' . time());

    $ch = curl_init("https://api.stripe.com/v1/refunds");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postFields),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $STRIPE_SK,
            'Content-Type: application/x-www-form-urlencoded',
            'Stripe-Version: 2023-10-16',
            'Idempotency-Key: ' . $idempotencyKey,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        error_log("[admin/order-refund] Stripe curl error pedido #{$orderId}: {$curlError}");
        return ['status' => 'network_error', 'message' => "Erro de conexao com Stripe: {$curlError}", 'refund_id' => null];
    }

    $refundData = json_decode($response, true);
    $refundOk = $httpCode >= 200 && $httpCode < 300 && !empty($refundData['id']);

    if ($refundOk) {
        error_log("[admin/order-refund] Stripe refund OK pedido #{$orderId} PI={$paymentIntentId} refund_id={$refundData['id']} amount=R\${$amount}");
        return [
            'status' => 'refunded',
            'message' => "Stripe reembolso processado (refund: {$refundData['id']})",
            'refund_id' => $refundData['id'],
        ];
    }

    $errorMsg = $refundData['error']['message'] ?? "HTTP {$httpCode}";
    error_log("[admin/order-refund] Stripe refund FAILED pedido #{$orderId} PI={$paymentIntentId} HTTP={$httpCode} error={$errorMsg}");

    // Check for specific Stripe errors
    $errorType = $refundData['error']['type'] ?? '';
    $errorCode = $refundData['error']['code'] ?? '';

    if ($errorCode === 'charge_already_refunded') {
        return ['status' => 'already_refunded', 'message' => 'Stripe: pagamento ja foi reembolsado anteriormente', 'refund_id' => null];
    }

    return ['status' => 'failed', 'message' => "Stripe erro: {$errorMsg}", 'refund_id' => null];
}

/**
 * PIX refund via Woovi/OpenPix
 */
function refundPixAdmin(PDO $db, int $orderId, float $amount, string $reason): array {
    try {
        // Find PIX correlation ID
        $stmt = $db->prepare("SELECT pagarme_order_id FROM om_pagarme_transacoes WHERE pedido_id = ? AND tipo = 'pix' ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$orderId]);
        $txRow = $stmt->fetch();

        if (empty($txRow['pagarme_order_id'])) {
            return ['status' => 'no_pix_record', 'message' => 'Sem registro PIX para reembolsar (sem correlation ID)'];
        }

        $correlationId = $txRow['pagarme_order_id'];

        require_once dirname(__DIR__, 3) . '/includes/classes/WooviClient.php';
        $woovi = new \WooviClient();
        $pixRefundResult = $woovi->refundCharge($correlationId, "Admin refund pedido #{$orderId}: {$reason}");

        $pixRefundOk = !empty($pixRefundResult['data']['refund']['status'])
            || !empty($pixRefundResult['data']['status'])
            || (isset($pixRefundResult['success']) && $pixRefundResult['success']);

        if ($pixRefundOk) {
            try {
                $db->prepare("UPDATE om_pagarme_transacoes SET status = 'refunded' WHERE pedido_id = ? AND tipo = 'pix'")->execute([$orderId]);
            } catch (\Exception $e) {}

            error_log("[admin/order-refund] PIX refund OK pedido #{$orderId} correlation={$correlationId}");
            return ['status' => 'refunded', 'message' => "PIX reembolso processado via Woovi (correlation: {$correlationId})"];
        }

        error_log("[admin/order-refund] PIX refund FAILED pedido #{$orderId} correlation={$correlationId}");
        return ['status' => 'failed', 'message' => 'PIX reembolso falhou — necessita processamento manual'];

    } catch (\Exception $e) {
        error_log("[admin/order-refund] PIX refund error pedido #{$orderId}: " . $e->getMessage());
        return ['status' => 'error', 'message' => 'Erro ao processar PIX refund: ' . $e->getMessage()];
    }
}
