<?php
/**
 * POST /api/mercado/boraum/cancelar.php
 * Cancelar pedido do passageiro BoraUm
 *
 * Body: { order_id, reason? }
 * Somente pedidos com status: pending, pendente, aceito, confirmed, confirmado
 * Reembolsa saldo automaticamente se pagou com saldo
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth.php';

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, "Metodo nao permitido", 405);
}

try {
    $db = getDB();
    $user = requirePassageiro($db);

    $input = getInput();
    $orderId = (int)($input['order_id'] ?? 0);
    $reason = trim(substr($input['reason'] ?? '', 0, 500));

    if (!$orderId) response(false, null, "order_id obrigatorio", 400);

    $customerId = $user['customer_id'];
    $passageiroId = $user['passageiro_id'];
    $cancellableStatuses = ['pending', 'pendente', 'aceito', 'confirmed', 'confirmado'];

    $db->beginTransaction();

    try {
        // Fetch order with lock
        $stmt = $db->prepare("SELECT * FROM om_market_orders WHERE order_id = ? AND customer_id = ? FOR UPDATE");
        $stmt->execute([$orderId, $customerId]);
        $order = $stmt->fetch();

        if (!$order) {
            $db->rollBack();
            response(false, null, "Pedido nao encontrado", 404);
        }

        if (!in_array($order['status'], $cancellableStatuses)) {
            $db->rollBack();
            response(false, null, "Pedido nao pode ser cancelado (status: {$order['status']}). Somente pedidos pendentes ou aceitos podem ser cancelados.", 400);
        }

        $cancelReason = $reason ?: 'Cancelado pelo passageiro BoraUm';

        // Cancel the order
        $db->prepare("
            UPDATE om_market_orders
            SET status = 'cancelado',
                cancelled_at = NOW(),
                cancel_reason = ?,
                cancellation_reason = ?,
                cancelled_by = 'customer',
                date_modified = NOW()
            WHERE order_id = ?
        ")->execute([$cancelReason, $cancelReason, $orderId]);

        // Record event
        try {
            $db->prepare("
                INSERT INTO om_market_order_events (order_id, event_type, message, created_by, created_at)
                VALUES (?, 'customer_cancel', ?, ?, NOW())
            ")->execute([$orderId, "Cancelado via BoraUm. Motivo: $cancelReason", "boraum_passenger:{$passageiroId}"]);
        } catch (Exception $e) {}

        // Restore stock
        $stmtItems = $db->prepare("SELECT product_id, quantity FROM om_market_order_items WHERE order_id = ?");
        $stmtItems->execute([$orderId]);
        foreach ($stmtItems->fetchAll() as $item) {
            $db->prepare("UPDATE om_market_products SET quantity = quantity + ? WHERE product_id = ?")->execute([$item['quantity'], $item['product_id']]);
        }

        // Refund saldo if paid with wallet
        $saldoRefund = 0;
        $paymentMethod = $order['forma_pagamento'] ?? $order['payment_method'] ?? '';
        if (in_array($paymentMethod, ['saldo', 'wallet', 'misto', 'wallet_mix'])) {
            // Check wallet for debit transactions related to this order
            $stmtWallet = $db->prepare("
                SELECT SUM(valor) as total_debitado
                FROM om_boraum_passenger_wallet
                WHERE passageiro_id = ? AND tipo = 'debit' AND referencia = ?
            ");
            $stmtWallet->execute([$passageiroId, "order:{$orderId}"]);
            $walletRow = $stmtWallet->fetch();
            $saldoRefund = abs((float)($walletRow['total_debitado'] ?? 0));

            if ($saldoRefund > 0) {
                // Refund to passenger balance
                $db->prepare("UPDATE boraum_passageiros SET saldo = saldo + ? WHERE id = ?")->execute([$saldoRefund, $passageiroId]);

                // Get new balance
                $stmtBal = $db->prepare("SELECT saldo FROM boraum_passageiros WHERE id = ?");
                $stmtBal->execute([$passageiroId]);
                $newSaldo = (float)$stmtBal->fetchColumn();

                // Record refund transaction
                $orderNumber = $order['order_number'] ?? $orderId;
                $db->prepare("
                    INSERT INTO om_boraum_passenger_wallet (passageiro_id, tipo, valor, descricao, referencia, saldo_apos, created_at)
                    VALUES (?, 'refund', ?, ?, ?, ?, NOW())
                ")->execute([$passageiroId, $saldoRefund, "Reembolso cancelamento pedido #{$orderNumber}", "refund_order:{$orderId}", $newSaldo]);
            }
        }

        // Restore loyalty points if used
        $loyaltyPointsUsed = (int)($order['loyalty_points_used'] ?? 0);
        if ($loyaltyPointsUsed > 0) {
            $db->prepare("UPDATE om_market_loyalty_points SET current_points = current_points + ? WHERE customer_id = ?")
                ->execute([$loyaltyPointsUsed, $customerId]);
        }

        // Release shopper if assigned
        if (!empty($order['shopper_id'])) {
            try {
                $db->prepare("UPDATE om_market_shoppers SET disponivel = 1, pedido_atual_id = NULL WHERE shopper_id = ?")
                    ->execute([(int)$order['shopper_id']]);
            } catch (Exception $e) {}
        }

        $db->commit();

        error_log("[BoraUm Cancel] Pedido #{$orderId} cancelado pelo passageiro #{$passageiroId}. Motivo: {$cancelReason}. Reembolso saldo: R$ " . number_format($saldoRefund, 2));

        response(true, [
            'order_id' => $orderId,
            'status' => 'cancelado',
            'reason' => $cancelReason,
            'saldo_reembolsado' => round($saldoRefund, 2),
            'pontos_restaurados' => $loyaltyPointsUsed,
        ], "Pedido cancelado com sucesso" . ($saldoRefund > 0 ? ". R$ " . number_format($saldoRefund, 2, ',', '.') . " devolvido ao seu saldo." : ""));

    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    error_log("[BoraUm Cancel] Erro: " . $e->getMessage());
    response(false, null, "Erro ao cancelar pedido", 500);
}
