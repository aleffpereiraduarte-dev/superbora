<?php
/**
 * POST /api/mercado/subscriptions/cancel.php
 * Cancelar assinatura SuperBora Club
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

    $reason = strip_tags(trim($input['reason'] ?? ''));
    $feedback = strip_tags(trim($input['feedback'] ?? ''));

    // Buscar assinatura ativa
    $stmt = $db->prepare("
        SELECT s.*, p.name as plan_name
        FROM om_subscriptions s
        INNER JOIN om_subscription_plans p ON s.plan_id = p.id
        WHERE s.customer_id = ? AND s.status = 'active'
    ");
    $stmt->execute([$customerId]);
    $subscription = $stmt->fetch();

    if (!$subscription) {
        response(false, null, "Nenhuma assinatura ativa encontrada", 404);
    }

    // Cancelar assinatura
    $cancelReason = $reason;
    if ($feedback) {
        $cancelReason .= " | Feedback: " . $feedback;
    }

    $stmt = $db->prepare("
        UPDATE om_subscriptions
        SET status = 'cancelled',
            cancelled_at = NOW(),
            cancel_reason = ?
        WHERE id = ?
    ");
    $stmt->execute([$cancelReason, $subscription['id']]);

    // A assinatura continua valida ate o fim do periodo pago
    $endsAt = $subscription['current_period_end'];

    response(true, [
        'cancelled' => true,
        'valid_until' => $endsAt,
        'message' => 'Sua assinatura foi cancelada. Voce ainda pode usar os beneficios ate ' . date('d/m/Y', strtotime($endsAt)) . '.'
    ]);

} catch (Exception $e) {
    error_log("[subscriptions/cancel] Erro: " . $e->getMessage());
    response(false, null, "Erro ao cancelar assinatura", 500);
}
