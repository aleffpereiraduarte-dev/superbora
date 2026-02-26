<?php
/**
 * GET /api/mercado/subscriptions/status.php
 * Retorna status da assinatura do cliente
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

    $stmt = $db->prepare("
        SELECT s.*, p.name as plan_name, p.slug as plan_slug, p.price,
               p.free_delivery, p.cashback_multiplier, p.discount_percent,
               p.priority_support, p.express_delivery_free
        FROM om_subscriptions s
        INNER JOIN om_subscription_plans p ON s.plan_id = p.id
        WHERE s.customer_id = ? AND s.status IN ('trial', 'active', 'paused')
        ORDER BY s.created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$customerId]);
    $subscription = $stmt->fetch();

    if (!$subscription) {
        response(true, [
            'has_subscription' => false,
            'subscription' => null
        ]);
    }

    $now = new DateTime();
    $periodEnd = new DateTime($subscription['current_period_end']);
    $daysRemaining = max(0, (int)$now->diff($periodEnd)->days);

    response(true, [
        'has_subscription' => true,
        'subscription' => [
            'id' => (int)$subscription['id'],
            'plan_name' => $subscription['plan_name'],
            'plan_slug' => $subscription['plan_slug'],
            'status' => $subscription['status'],
            'status_label' => match($subscription['status']) {
                'trial' => 'Periodo de Teste',
                'active' => 'Ativa',
                'paused' => 'Pausada',
                'cancelled' => 'Cancelada',
                'expired' => 'Expirada',
                default => $subscription['status']
            },
            'price' => (float)$subscription['price'],
            'price_formatted' => 'R$ ' . number_format($subscription['price'], 2, ',', '.'),
            'trial_ends_at' => $subscription['trial_ends_at'],
            'current_period_end' => $subscription['current_period_end'],
            'days_remaining' => $daysRemaining,
            'auto_renew' => (bool)$subscription['auto_renew'],
            'benefits' => [
                'free_delivery' => (bool)$subscription['free_delivery'],
                'cashback_multiplier' => (float)$subscription['cashback_multiplier'],
                'discount_percent' => (float)$subscription['discount_percent'],
                'priority_support' => (bool)$subscription['priority_support'],
                'express_delivery_free' => (bool)$subscription['express_delivery_free'],
            ]
        ]
    ]);

} catch (Exception $e) {
    error_log("[subscriptions/status] Erro: " . $e->getMessage());
    response(false, null, "Erro ao carregar assinatura", 500);
}
