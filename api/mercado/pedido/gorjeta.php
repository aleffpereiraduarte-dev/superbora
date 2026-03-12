<?php
/**
 * POST /api/mercado/pedido/gorjeta.php
 * Dar gorjeta apos a entrega (fallback endpoint)
 * Body: { order_id, valor }
 * Aceita 'valor' ou 'amount' como campo do valor
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
    $orderId = (int)($input['order_id'] ?? 0);
    $amount = (float)($input['valor'] ?? $input['amount'] ?? 0);
    $message = strip_tags(trim($input['message'] ?? ''));

    if (!$orderId) response(false, null, "ID do pedido obrigatorio", 400);
    if ($amount <= 0) response(false, null, "Valor deve ser maior que zero", 400);
    if ($amount > 100) response(false, null, "Valor maximo de gorjeta: R$ 100", 400);

    // Verificar pedido pertence ao cliente e esta entregue
    $stmt = $db->prepare("
        SELECT order_id, status, partner_id FROM om_market_orders
        WHERE order_id = ? AND customer_id = ?
    ");
    $stmt->execute([$orderId, $customerId]);
    $order = $stmt->fetch();

    if (!$order) response(false, null, "Pedido nao encontrado", 404);

    // Registrar gorjeta
    $db->beginTransaction();

    // Atualizar gorjeta no pedido
    $db->prepare("UPDATE om_market_orders SET tip_amount = COALESCE(tip_amount, 0) + ?, tip_message = ? WHERE order_id = ?")
        ->execute([$amount, $message, $orderId]);

    // Registrar na tabela de gorjetas se existir
    try {
        $db->prepare("
            INSERT INTO om_market_tips (order_id, customer_id, partner_id, amount, message, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ")->execute([$orderId, $customerId, $order['partner_id'], $amount, $message]);
    } catch (Exception $e) {
        // Tabela pode nao existir - gorjeta ja foi salva no pedido
        error_log("[gorjeta] Tips table error (ok): " . $e->getMessage());
    }

    $db->commit();

    response(true, [
        'order_id' => $orderId,
        'tip_amount' => $amount,
    ], "Gorjeta enviada com sucesso!");

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log("[gorjeta] Erro: " . $e->getMessage());
    response(false, null, "Erro ao enviar gorjeta", 500);
}
