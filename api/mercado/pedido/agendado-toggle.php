<?php
/**
 * POST /api/mercado/pedido/agendado-toggle.php
 * Ativa/desativa um pedido recorrente
 * Body: { recurring_id, active (bool) }
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
    $recurringId = (int)($input['recurring_id'] ?? 0);
    $active = filter_var($input['active'] ?? false, FILTER_VALIDATE_BOOLEAN);

    if (!$recurringId) response(false, null, "recurring_id obrigatorio", 400);

    // Verificar ownership
    $stmt = $db->prepare("
        SELECT id, is_active FROM om_market_recurring_orders
        WHERE id = ? AND customer_id = ?
    ");
    $stmt->execute([$recurringId, $customerId]);
    $recurring = $stmt->fetch();

    if (!$recurring) response(false, null, "Pedido recorrente nao encontrado", 404);

    $newStatus = $active ? '1' : '0';
    $db->prepare("UPDATE om_market_recurring_orders SET is_active = ?, updated_at = NOW() WHERE id = ?")
        ->execute([$newStatus, $recurringId]);

    response(true, [
        'recurring_id' => $recurringId,
        'active' => $active,
    ], $active ? "Pedido reativado" : "Pedido pausado");

} catch (Exception $e) {
    error_log("[agendado-toggle] Erro: " . $e->getMessage());
    response(false, null, "Erro ao atualizar", 500);
}
