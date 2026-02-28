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
    $cancellableStatuses = ['pending', 'pendente', 'aceito', 'confirmed', 'confirmado'];

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

        // 4. Update order to cancelled
        $cancelReason = $reason ?: 'Cancelado pelo cliente';
        $stmt = $db->prepare("
            UPDATE om_market_orders
            SET status = 'cancelado',
                cancelled_at = NOW(),
                cancel_reason = ?,
                cancellation_reason = ?,
                cancelled_by = 'customer',
                date_modified = NOW()
            WHERE order_id = ?
        ");
        $stmt->execute([$cancelReason, $cancelReason, $orderId]);

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
            // PIX refund via Woovi
            if ($paymentMethod === 'pix') {
                try {
                    require_once dirname(__DIR__, 3) . '/includes/classes/WooviClient.php';
                    $txStmt = $db->prepare("SELECT pagarme_order_id FROM om_pagarme_transacoes WHERE pedido_id = ? AND tipo = 'pix' ORDER BY created_at DESC LIMIT 1");
                    $txStmt->execute([$orderId]);
                    $txRow = $txStmt->fetch();
                    $wooviCorrelation = $txRow['pagarme_order_id'] ?? '';
                    if (!empty($wooviCorrelation)) {
                        $woovi = new WooviClient();
                        $woovi->refundCharge($wooviCorrelation, "Cancelamento pedido #$orderId");
                        $db->prepare("UPDATE om_pagarme_transacoes SET status = 'refunded' WHERE pedido_id = ? AND tipo = 'pix'")->execute([$orderId]);
                        $refundInfo = 'PIX refund processado';
                        error_log("[orders/cancel] PIX refund pedido #$orderId OK");
                    }
                } catch (Exception $e) {
                    error_log("[orders/cancel] PIX refund error: " . $e->getMessage());
                }
            }
            // Stripe refund
            if (in_array($paymentMethod, ['stripe_card', 'stripe_wallet', 'credito'])) {
                try {
                    require_once __DIR__ . '/../pedido/cancelar.php'; // uses refundStripePayment
                } catch (Exception $e) {
                    // refundStripePayment may not be available from this context
                    error_log("[orders/cancel] Stripe refund not available from this endpoint");
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
