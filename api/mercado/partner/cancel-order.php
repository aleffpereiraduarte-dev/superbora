<?php
/**
 * POST /api/mercado/partner/cancel-order.php - Cancelar pedido com motivo
 * GET /api/mercado/partner/cancel-order.php - Listar motivos de cancelamento
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/PusherService.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Token ausente", 401);

    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== OmAuth::USER_TYPE_PARTNER) {
        response(false, null, "Nao autorizado", 401);
    }

    $partnerId = $payload['uid'];
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        // Get cancellation reasons and stats from orders
        $stmt = $db->prepare("
            SELECT cancel_reason as reason, COUNT(*) as count
            FROM om_market_orders
            WHERE partner_id = ? AND status IN ('cancelado','cancelled')
            AND created_at >= NOW() - INTERVAL '30 days'
            AND cancel_reason IS NOT NULL AND cancel_reason != ''
            GROUP BY cancel_reason
            ORDER BY count DESC
        ");
        $stmt->execute([$partnerId]);
        $reasonStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Predefined reasons
        $predefinedReasons = [
            'out_of_stock' => 'Produto indisponivel',
            'too_busy' => 'Restaurante muito ocupado',
            'closing_soon' => 'Fechando em breve',
            'customer_request' => 'Pedido do cliente',
            'incorrect_order' => 'Erro no pedido',
            'delivery_issue' => 'Problema com entrega',
            'other' => 'Outro motivo',
        ];

        response(true, [
            'predefined_reasons' => $predefinedReasons,
            'reason_stats' => $reasonStats,
        ]);
    }

    if ($method === 'POST') {
        $input = getInput();
        $orderId = intval($input['order_id'] ?? 0);
        $reason = strip_tags(trim($input['reason'] ?? ''));
        $reasonDetails = strip_tags(trim($input['reason_details'] ?? ''));

        if (!$orderId) response(false, null, "ID do pedido obrigatorio", 400);
        if (empty($reason)) response(false, null, "Motivo obrigatorio", 400);

        // Verify order belongs to partner and is cancellable
        $stmt = $db->prepare("
            SELECT order_id, status, customer_id, coupon_id, loyalty_points_used, cashback_discount
            FROM om_market_orders
            WHERE order_id = ? AND partner_id = ?
        ");
        $stmt->execute([$orderId, $partnerId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) response(false, null, "Pedido nao encontrado", 404);

        $cancellableStatuses = ['pendente', 'pending', 'confirmado', 'confirmed', 'preparando', 'preparing'];
        if (!in_array(strtolower($order['status']), $cancellableStatuses)) {
            response(false, null, "Pedido nao pode ser cancelado neste status", 400);
        }

        $db->beginTransaction();
        try {
            // Update order status with cancellation reason
            // Bug 16 fix: Add status condition to prevent TOCTOU race
            $stmt = $db->prepare("
                UPDATE om_market_orders SET
                    status = 'cancelado',
                    cancel_reason = ?,
                    cancelled_by = 'partner',
                    cancelled_at = NOW(),
                    updated_at = NOW()
                WHERE order_id = ? AND partner_id = ?
                AND status IN ('pendente','pending','confirmado','confirmed','preparando','preparing','aceito')
            ");
            $stmt->execute([$reason . ($reasonDetails ? ': ' . $reasonDetails : ''), $orderId, $partnerId]);

            if ($stmt->rowCount() === 0) {
                $db->rollBack();
                response(false, null, "Pedido ja foi atualizado por outra acao. Atualize a pagina.", 409);
            }

            // Financial compensation
            $customerId = (int)($order['customer_id'] ?? 0);

            // 1. Restore coupon usage
            $couponId = (int)($order['coupon_id'] ?? 0);
            if ($couponId && $customerId) {
                $db->prepare("DELETE FROM om_market_coupon_usage WHERE coupon_id = ? AND customer_id = ? AND order_id = ?")
                   ->execute([$couponId, $customerId, $orderId]);
                $db->prepare("UPDATE om_market_coupons SET current_uses = GREATEST(0, current_uses - 1) WHERE id = ?")->execute([$couponId]);
            }

            // 2. Credit back loyalty points
            $pointsUsed = (int)($order['loyalty_points_used'] ?? 0);
            if ($pointsUsed > 0 && $customerId) {
                $db->prepare("UPDATE om_market_loyalty_points SET current_points = current_points + ?, updated_at = NOW() WHERE customer_id = ?")
                   ->execute([$pointsUsed, $customerId]);
                $db->prepare("INSERT INTO om_market_loyalty_transactions (customer_id, points, type, source, reference_id, description, created_at) VALUES (?, ?, 'refund', 'order_cancelled', ?, ?, NOW())")
                   ->execute([$customerId, $pointsUsed, $orderId, "Estorno cancelamento parceiro pedido #{$orderId}"]);
            }

            // 3. Reverse cashback
            $cashbackUsed = (float)($order['cashback_discount'] ?? 0);
            if ($cashbackUsed > 0) {
                require_once __DIR__ . '/../helpers/cashback.php';
                refundCashback($db, $orderId);
            }

            // 4. Restore stock
            $stmtItems = $db->prepare("SELECT product_id, quantity FROM om_market_order_items WHERE order_id = ?");
            $stmtItems->execute([$orderId]);
            $items = $stmtItems->fetchAll();
            foreach ($items as $item) {
                $db->prepare("UPDATE om_market_products SET quantity = quantity + ? WHERE product_id = ?")
                   ->execute([$item['quantity'], $item['product_id']]);
            }

            error_log("[cancel-order] CANCEL COMPENSATION order #{$orderId}: coupon={$couponId} points={$pointsUsed} cashback=R\${$cashbackUsed} partner={$partnerId}");

            $db->commit();

            // Pusher: notificar sobre o cancelamento do pedido em tempo real
            try {
                PusherService::orderUpdate($partnerId, [
                    'id' => $orderId,
                    'status' => 'cancelado',
                    'old_status' => $order['status'],
                    'action' => 'cancelar',
                    'cancel_reason' => $reason
                ]);
                PusherService::orderStatusUpdate($orderId, 'cancelado', 'Pedido cancelado pelo parceiro');
            } catch (Exception $pusherErr) {
                error_log("[cancel-order] Pusher erro: " . $pusherErr->getMessage());
            }

            response(true, null, "Pedido cancelado com sucesso");

        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[partner/cancel-order] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
