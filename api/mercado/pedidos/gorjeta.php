<?php
/**
 * POST /api/mercado/pedidos/gorjeta.php
 * B4: Salvar gorjeta pro entregador
 * Body: { "order_id": 1, "customer_id": 1, "amount": 5.00 }
 */
require_once __DIR__ . "/../config/database.php";

try {
    $input = getInput();

    // Autenticação obrigatória
    $customer_id = requireCustomerAuth();

    $db = getDB();

    $order_id = (int)($input["order_id"] ?? 0);
    $amount = max(0, min(100, (float)($input["amount"] ?? 0)));

    if (!$order_id) {
        response(false, null, "order_id obrigatorio", 400);
    }
    if ($amount <= 0) {
        response(false, null, "Valor da gorjeta deve ser positivo", 400);
    }

    $db->beginTransaction();

    try {
        // Verificar pedido com lock para evitar race condition
        $stmt = $db->prepare("
            SELECT order_id, customer_id, status, total, tip_amount
            FROM om_market_orders
            WHERE order_id = ?
            FOR UPDATE
        ");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch();

        if (!$order) {
            $db->rollBack();
            response(false, null, "Pedido nao encontrado", 404);
        }

        // Verificar se e o cliente dono do pedido
        if ((int)$order['customer_id'] !== $customer_id) {
            $db->rollBack();
            response(false, null, "Sem permissao", 403);
        }

        // Gorjeta so pode ser dada durante/apos entrega
        $allowedStatuses = ['em_entrega', 'delivering', 'out_for_delivery', 'entregue', 'retirado'];
        if (!in_array($order['status'], $allowedStatuses)) {
            $db->rollBack();
            response(false, null, "Gorjeta disponivel apenas durante ou apos a entrega", 400);
        }

        // Atualizar gorjeta atomicamente: new total = (total - old_tip + new_tip)
        $oldTip = (float)($order['tip_amount'] ?? 0);
        $newTip = $oldTip + $amount;
        $newTotal = (float)$order['total'] - $oldTip + $newTip;

        $stmt = $db->prepare("
            UPDATE om_market_orders
            SET tip_amount = ?, total = ?
            WHERE order_id = ?
        ");
        $stmt->execute([$newTip, round($newTotal, 2), $order_id]);

        $db->commit();

        response(true, [
            "order_id" => $order_id,
            "gorjeta_total" => round($newTip, 2),
            "novo_total" => round($newTotal, 2)
        ], "Gorjeta enviada! Obrigado pela generosidade.");

    } catch (Exception $txEx) {
        if ($db->inTransaction()) $db->rollBack();
        throw $txEx;
    }

} catch (Exception $e) {
    error_log("[Gorjeta] Erro: " . $e->getMessage());
    response(false, null, "Erro ao salvar gorjeta", 500);
}
