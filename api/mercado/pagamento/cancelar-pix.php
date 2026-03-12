<?php
/**
 * POST /api/mercado/pagamento/cancelar-pix.php
 * Cancela um PIX pendente por intent_id
 * Body: { intent_id }
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
    if (!$payload || $payload['type'] !== 'customer') response(false, null, "Nao autorizado", 401);
    $customerId = (int)$payload['uid'];

    $input = getInput();
    $intentId = trim($input['intent_id'] ?? '');
    if (empty($intentId)) response(false, null, "intent_id obrigatorio", 400);

    // Buscar pedido pelo payment_id/intent
    $stmt = $db->prepare("
        SELECT order_id, status, payment_status
        FROM om_market_orders
        WHERE (payment_id = ? OR stripe_payment_intent_id = ? OR pix_code = ?)
          AND customer_id = ?
        LIMIT 1
    ");
    $stmt->execute([$intentId, $intentId, $intentId, $customerId]);
    $order = $stmt->fetch();

    if (!$order) {
        // Silently succeed - app calls this with .catch(() => {})
        response(true, null, "OK");
    }

    // Só cancela se ainda estiver pendente
    if (in_array($order['status'], ['pendente', 'aguardando_pagamento']) && $order['payment_status'] !== 'paid') {
        $db->prepare("UPDATE om_market_orders SET status = 'cancelado', payment_status = 'cancelled', cancelled_at = NOW() WHERE order_id = ?")
            ->execute([$order['order_id']]);
        response(true, null, "PIX cancelado");
    }

    response(true, null, "OK");

} catch (Exception $e) {
    error_log("[cancelar-pix] Erro: " . $e->getMessage());
    response(false, null, "Erro ao cancelar", 500);
}
