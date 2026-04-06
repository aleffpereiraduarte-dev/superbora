<?php
/**
 * POST /api/mercado/orders/cancel.php
 * Customer cancels their own order
 * Body: { "order_id": 123, "reason": "optional reason" }
 * Only allowed for status: pending, pendente, aceito, confirmed, confirmado
 */

require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    response(false, null, "Metodo nao permitido", 405);
}

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    // 1. Authenticate customer
    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Autenticacao necessaria", 401);
    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== 'customer') response(false, null, "Token invalido", 401);
    $customerId = (int)$payload['uid'];

    $input = getInput();
    $orderId = (int)($input['order_id'] ?? 0);
    $reason = strip_tags(trim(substr($input['reason'] ?? '', 0, 500)));

    if (!$orderId) response(false, null, "order_id obrigatorio", 400);

    // Allowed statuses for cancellation
    $cancellableStatuses = ['pendente', 'aceito', 'confirmado'];

    $db->beginTransaction();

    try {
        // 2. Fetch order with lock and verify ownership — explicit columns only
        $stmt = $db->prepare("
            SELECT order_id, order_number, status, total, subtotal, delivery_fee,
                   customer_id, shopper_id, partner_id, payment_method, payment_status,
                   loyalty_points_used, loyalty_points_earned
            FROM om_market_orders WHERE order_id = ? AND customer_id = ? FOR UPDATE
        ");
        $stmt->execute([$orderId, $customerId]);
        $order = $stmt->fetch();

        if (!$order) {
            $db->rollBack();
            response(false, null, "Pedido nao encontrado", 404);
        }

        // 3. Check if status allows cancellation
        if (!in_array($order['status'], $cancellableStatuses)) {
            $db->rollBack();
            response(false, null, "Pedido nao pode ser cancelado (status: {$order['status']})", 400);
        }

        // Track if order was paid (for auto-refund after cancel)
        $paymentStatus = $order['payment_status'] ?? '';
        $wasPaid = in_array($paymentStatus, ['paid', 'pago', 'captured']);
        $paymentMethod = $order['payment_method'] ?? '';

        // 4. Update order to cancelled (with status guard to prevent race condition)
        $cancelReason = $reason ?: 'Cancelado pelo cliente';
        $stmt = $db->prepare("
            UPDATE om_market_orders
            SET status = 'cancelado',
                cancelled_at = NOW(),
                cancel_reason = ?,
                cancellation_reason = ?,
                cancelled_by = 'customer',
                date_modified = NOW()
            WHERE order_id = ? AND status IN ('pendente', 'aceito', 'confirmado')
        ");
        $stmt->execute([$cancelReason, $cancelReason, $orderId]);
        if ($stmt->rowCount() === 0) {
            $db->rollBack();
            response(false, null, "Pedido ja foi processado ou cancelado", 409);
        }

        // 5. Record event in om_market_order_events
        $stmt = $db->prepare("
            INSERT INTO om_market_order_events (order_id, event_type, message, created_by, created_at)
            VALUES (?, 'customer_cancel', ?, ?, NOW())
        ");
        $stmt->execute([$orderId, "Pedido cancelado pelo cliente. Motivo: $cancelReason", "customer:$customerId"]);

        // 6. Restore product stock
        $stmt = $db->prepare("SELECT product_id, quantity FROM om_market_order_items WHERE order_id = ?");
        $stmt->execute([$orderId]);
        $items = $stmt->fetchAll();

        foreach ($items as $item) {
            $stmtStock = $db->prepare("UPDATE om_market_products SET quantity = quantity + ? WHERE product_id = ?");
            $stmtStock->execute([$item['quantity'], $item['product_id']]);
        }

        // 7. Restore loyalty points if used
        $loyaltyPointsUsed = (int)($order['loyalty_points_used'] ?? 0);
        if ($loyaltyPointsUsed > 0) {
            $db->prepare("
                UPDATE om_market_loyalty_points SET current_points = current_points + ? WHERE customer_id = ?
            ")->execute([$loyaltyPointsUsed, $customerId]);

            $orderNumber = $order['order_number'] ?? $orderId;
            $db->prepare("
                INSERT INTO om_market_loyalty_transactions (customer_id, type, points, description, created_at)
                VALUES (?, 'refund', ?, ?, NOW())
            ")->execute([$customerId, $loyaltyPointsUsed, "Estorno - Cancelamento pedido #$orderNumber"]);
        }

        // Reverse earned points for this order
        $loyaltyPointsEarned = (int)($order['loyalty_points_earned'] ?? 0);
        if ($loyaltyPointsEarned > 0) {
            $db->prepare("
                UPDATE om_market_loyalty_points SET current_points = GREATEST(0, current_points - ?) WHERE customer_id = ?
            ")->execute([$loyaltyPointsEarned, $customerId]);

            $orderNumber = $order['order_number'] ?? $orderId;
            $db->prepare("
                INSERT INTO om_market_loyalty_transactions (customer_id, type, points, description, created_at)
                VALUES (?, 'reversal', ?, ?, NOW())
            ")->execute([$customerId, -$loyaltyPointsEarned, "Estorno pontos ganhos - Cancelamento pedido #$orderNumber"]);
        }

        // Release shopper if assigned
        if (!empty($order['shopper_id'])) {
            $db->prepare("UPDATE om_market_shoppers SET disponivel = 1, pedido_atual_id = NULL WHERE shopper_id = ?")
                ->execute([(int)$order['shopper_id']]);
        }

        $db->commit();

        error_log("[orders/cancel] Pedido #{$orderId} cancelado pelo cliente #{$customerId}. Motivo: {$cancelReason}");

        // 8. Auto-refund if order was paid (after commit, external API calls)
        $refundInfo = null;
        if ($wasPaid) {
            // PIX refund via EFI
            if ($paymentMethod === 'pix') {
                try {
                    require_once dirname(__DIR__, 3) . '/includes/classes/EfiClient.php';
                    $efi = new EfiClient();

                    // Find e2eId
                    $orderStmt = $db->prepare("SELECT payment_id, total FROM om_market_orders WHERE order_id = ?");
                    $orderStmt->execute([$orderId]);
                    $orderData = $orderStmt->fetch();
                    $e2eId = $orderData['payment_id'] ?? '';
                    $refundAmount = (float)($orderData['total'] ?? 0);

                    if (empty($e2eId)) {
                        $intentStmt = $db->prepare("SELECT correlation_id FROM om_pix_intents WHERE order_id = ? ORDER BY created_at DESC LIMIT 1");
                        $intentStmt->execute([$orderId]);
                        $intentRow = $intentStmt->fetch();
                        $txid = $intentRow['correlation_id'] ?? '';

                        if (empty($txid)) {
                            $txStmt = $db->prepare("SELECT pagarme_order_id FROM om_pagarme_transacoes WHERE pedido_id = ? AND tipo = 'pix' ORDER BY created_at DESC LIMIT 1");
                            $txStmt->execute([$orderId]);
                            $txRow = $txStmt->fetch();
                            $txid = $txRow['pagarme_order_id'] ?? '';
                        }

                        if (!empty($txid)) {
                            $chargeStatus = $efi->checkChargeStatus($txid);
                            $e2eId = $chargeStatus['e2e_id'] ?? '';
                        }
                    }

                    if (!empty($e2eId)) {
                        $refResult = $efi->refundPix($e2eId, $refundAmount);
                        if ($refResult['success'] ?? false) {
                            $db->prepare("UPDATE om_pagarme_transacoes SET status = 'refunded' WHERE pedido_id = ? AND tipo = 'pix'")->execute([$orderId]);
                            $refundInfo = 'PIX refund processado via EFI';
                            error_log("[orders/cancel] PIX refund pedido #$orderId OK e2eId=$e2eId");
                        } else {
                            $refundInfo = 'PIX refund FAILED: ' . ($refResult['error'] ?? 'unknown');
                            error_log("[orders/cancel] PIX refund FAILED pedido #$orderId: " . ($refResult['error'] ?? 'unknown'));
                        }
                    }
                } catch (Exception $e) {
                    error_log("[orders/cancel] PIX refund error: " . $e->getMessage());
                }
            }
            // EFI card refund
            if (in_array($paymentMethod, ['efi_card', 'credito', 'debito'])) {
                try {
                    require_once dirname(__DIR__, 3) . '/includes/classes/EfiClient.php';
                    $orderStmt = $db->prepare("SELECT efi_charge_id FROM om_market_orders WHERE order_id = ?");
                    $orderStmt->execute([$orderId]);
                    $efiData = $orderStmt->fetch();
                    $efiChargeId = (int)($efiData['efi_charge_id'] ?? 0);
                    if ($efiChargeId) {
                        $efi = new EfiClient();
                        $cardRefResult = $efi->refundCard($efiChargeId);
                        if ($cardRefResult['success'] ?? false) {
                            $refundInfo = 'EFI card refund processado';
                            error_log("[orders/cancel] EFI card refund pedido #$orderId OK");
                        } else {
                            $refundInfo = 'EFI card refund FAILED: ' . ($cardRefResult['error'] ?? 'unknown');
                            error_log("[orders/cancel] EFI card refund FAILED pedido #$orderId: " . ($cardRefResult['error'] ?? 'unknown'));
                        }
                    }
                } catch (Exception $e) {
                    error_log("[orders/cancel] EFI card refund error: " . $e->getMessage());
                }
            }
            // Stripe refund (Apple Pay / Google Pay only)
            if (in_array($paymentMethod, ['stripe_card', 'stripe_wallet'])) {
                try {
                    $orderStmt = $db->prepare("SELECT stripe_payment_intent_id, payment_id, total FROM om_market_orders WHERE order_id = ?");
                    $orderStmt->execute([$orderId]);
                    $stripeData = $orderStmt->fetch();
                    $stripePi = $stripeData['stripe_payment_intent_id'] ?? $stripeData['payment_id'] ?? '';
                    if ($stripePi) {
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
                        if ($STRIPE_SK) {
                            $idempotencyKey = "refund_orders_cancel_{$orderId}_{$stripePi}";
                            $ch = curl_init("https://api.stripe.com/v1/refunds");
                            curl_setopt_array($ch, [
                                CURLOPT_POST => true,
                                CURLOPT_POSTFIELDS => http_build_query([
                                    'payment_intent' => $stripePi,
                                    'reason' => 'requested_by_customer',
                                    'metadata[order_id]' => $orderId,
                                    'metadata[source]' => 'superbora_orders_cancel',
                                ]),
                                CURLOPT_HTTPHEADER => [
                                    'Authorization: Bearer ' . $STRIPE_SK,
                                    'Content-Type: application/x-www-form-urlencoded',
                                    'Idempotency-Key: ' . $idempotencyKey,
                                ],
                                CURLOPT_RETURNTRANSFER => true,
                                CURLOPT_TIMEOUT => 15,
                            ]);
                            $stripeResult = curl_exec($ch);
                            $stripeCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                            curl_close($ch);
                            $stripeRefundData = json_decode($stripeResult, true);
                            if ($stripeCode >= 200 && $stripeCode < 300 && !empty($stripeRefundData['id'])) {
                                $refundInfo = 'Stripe refund processado';
                                error_log("[orders/cancel] Stripe refund pedido #$orderId OK refund_id={$stripeRefundData['id']}");
                            } else {
                                $refundInfo = 'Stripe refund FAILED';
                                error_log("[orders/cancel] Stripe refund FAILED pedido #$orderId HTTP=$stripeCode");
                            }
                        }
                    }
                } catch (Exception $e) {
                    error_log("[orders/cancel] Stripe refund error: " . $e->getMessage());
                }
            }
        }

        // 9. Return success
        $msg = "Pedido cancelado com sucesso";
        if ($wasPaid) $msg .= ". Reembolso sera processado automaticamente.";

        response(true, [
            "order_id" => $orderId,
            "status" => "cancelado",
            "reason" => $cancelReason,
            "points_restored" => $loyaltyPointsUsed,
            "points_reversed" => $loyaltyPointsEarned,
            "refund" => $refundInfo,
            "was_paid" => $wasPaid,
        ], $msg);

    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    error_log("[orders/cancel] Erro: " . $e->getMessage());
    response(false, null, "Erro ao cancelar pedido", 500);
}
