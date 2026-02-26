<?php
/**
 * GET /api/mercado/subscriptions/plans.php
 * Lista planos de assinatura disponiveis
 */
require_once __DIR__ . "/../config/database.php";

setCorsHeaders();

try {
    $db = getDB();

    // Check if table exists
    $tableExists = $db->query("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'om_subscription_plans')")->fetchColumn();
    if (!$tableExists) {
        response(true, ['plans' => [], 'message' => 'Planos ainda nao configurados']);
    }

    $stmt = $db->prepare("
        SELECT id, name, slug, description, price, billing_cycle,
               free_delivery, cashback_multiplier, discount_percent,
               priority_support, express_delivery_free, trial_days
        FROM om_subscription_plans
        WHERE status = 'active'
        ORDER BY sort_order ASC
    ");
    $stmt->execute();
    $plans = $stmt->fetchAll();

    $formattedPlans = array_map(function($plan) {
        return [
            'id' => (int)$plan['id'],
            'name' => $plan['name'],
            'slug' => $plan['slug'],
            'description' => $plan['description'],
            'price' => (float)$plan['price'],
            'price_formatted' => 'R$ ' . number_format($plan['price'], 2, ',', '.'),
            'billing_cycle' => $plan['billing_cycle'],
            'billing_label' => match($plan['billing_cycle']) {
                'monthly' => '/mes',
                'quarterly' => '/trimestre',
                'yearly' => '/ano',
                default => ''
            },
            'benefits' => [
                'free_delivery' => (bool)$plan['free_delivery'],
                'cashback_multiplier' => (float)$plan['cashback_multiplier'],
                'discount_percent' => (float)$plan['discount_percent'],
                'priority_support' => (bool)$plan['priority_support'],
                'express_delivery_free' => (bool)$plan['express_delivery_free'],
            ],
            'trial_days' => (int)$plan['trial_days'],
        ];
    }, $plans);

    response(true, ['plans' => $formattedPlans]);

} catch (Exception $e) {
    error_log("[subscriptions/plans] Erro: " . $e->getMessage());
    response(false, null, "Erro ao carregar planos", 500);
}
