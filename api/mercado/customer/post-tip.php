<?php
/**
 * POST /api/mercado/customer/post-tip.php
 * Dar gorjeta apos a entrega
 * Body: { order_id, amount, message? }
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Token ausente", 401);

    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== 'customer') {
        response(false, null, "Nao autorizado", 401);
    }

    $customerId = (int)$payload['uid'];
    $input = getInput();

    $orderId = (int)($input['order_id'] ?? 0);
    $amount = (float)($input['amount'] ?? 0);
    $message = strip_tags(trim($input['message'] ?? ''));

    if (!$orderId) {
        response(false, null, "ID do pedido obrigatorio", 400);
    }

    if ($amount <= 0) {
        response(false, null, "Valor deve ser maior que zero", 400);
    }

    if ($amount > 100) {
        response(false, null, "Valor maximo de gorjeta: R$ 100", 400);
    }

    $db->beginTransaction();

    try {
        // Lock order row and verify ownership + status + tip not already given atomically
        $stmt = $db->prepare("
            SELECT order_id, status, shopper_id, post_tip_given
            FROM om_market_orders
            WHERE order_id = ? AND customer_id = ?
            FOR UPDATE
        ");
        $stmt->execute([$orderId, $customerId]);
        $order = $stmt->fetch();

        if (!$order) {
            $db->rollBack();
            response(false, null, "Pedido nao encontrado", 404);
        }

        if ($order['status'] !== 'entregue') {
            $db->rollBack();
            response(false, null, "Gorjeta so pode ser dada apos a entrega", 400);
        }

        if ($order['post_tip_given']) {
            $db->rollBack();
            response(false, null, "Gorjeta ja foi dada para este pedido", 400);
        }

        $shopperId = $order['shopper_id'];

        // Registrar gorjeta
        $stmt = $db->prepare("
            INSERT INTO om_post_delivery_tips
            (order_id, customer_id, shopper_id, amount, message, status)
            VALUES (?, ?, ?, ?, ?, 'pending')
        ");
        $stmt->execute([$orderId, $customerId, $shopperId, $amount, $message ?: null]);

        // Atualizar pedido
        $db->prepare("
            UPDATE om_market_orders
            SET post_tip_given = 1, post_tip_amount = ?
            WHERE order_id = ?
        ")->execute([$amount, $orderId]);

        // Creditar na carteira do shopper (se tiver)
        if ($shopperId) {
            $db->prepare("
                UPDATE om_market_shoppers
                SET saldo = saldo + ?
                WHERE shopper_id = ?
            ")->execute([$amount, $shopperId]);

            // Marcar como pago (scoped to this customer's tip to prevent updating other tips)
            $db->prepare("
                UPDATE om_post_delivery_tips
                SET status = 'paid', paid_at = NOW()
                WHERE order_id = ? AND customer_id = ? AND status = 'pending'
            ")->execute([$orderId, $customerId]);
        }

        $db->commit();

        response(true, [
            'message' => 'Gorjeta enviada com sucesso! Obrigado pela generosidade.',
            'amount' => $amount,
            'amount_formatted' => 'R$ ' . number_format($amount, 2, ',', '.')
        ]);

    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    error_log("[customer/post-tip] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar gorjeta", 500);
}
