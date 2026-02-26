<?php
/**
 * POST /api/mercado/subscriptions/subscribe.php
 * Assinar um plano SuperBora Club
 * Body: { plan_id, payment_method }
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

    $planId = (int)($input['plan_id'] ?? 0);
    $paymentMethod = $input['payment_method'] ?? 'credit_card';

    if (!$planId) {
        response(false, null, "Plano obrigatorio", 400);
    }

    // SECURITY: Validate payment method whitelist
    $allowedPaymentMethods = ['credit_card', 'pix', 'boleto'];
    if (!in_array($paymentMethod, $allowedPaymentMethods)) {
        response(false, null, "Metodo de pagamento invalido", 400);
    }

    // Verificar se ja tem assinatura ativa ou pendente (prevent duplicate subscriptions)
    $stmt = $db->prepare("
        SELECT id FROM om_subscriptions
        WHERE customer_id = ? AND status IN ('trial', 'active', 'pending_payment')
    ");
    $stmt->execute([$customerId]);
    if ($stmt->fetch()) {
        response(false, null, "Voce ja possui uma assinatura ativa", 400);
    }

    // Buscar plano
    $stmt = $db->prepare("
        SELECT id, name, slug, description, price, billing_cycle,
               free_delivery, cashback_multiplier, discount_percent,
               priority_support, express_delivery_free, trial_days, status
        FROM om_subscription_plans WHERE id = ? AND status = 'active'
    ");
    $stmt->execute([$planId]);
    $plan = $stmt->fetch();

    if (!$plan) {
        response(false, null, "Plano nao encontrado", 404);
    }

    $trialDays = (int)$plan['trial_days'];
    $now = new DateTime();

    if ($trialDays > 0) {
        $status = 'trial';
        $trialEndsAt = (clone $now)->modify("+{$trialDays} days");
        $periodStart = $trialEndsAt;
    } else {
        // Without a trial, subscription requires payment confirmation before activation.
        // Payment confirmation webhook should update status from 'pending_payment' to 'active'.
        $status = 'pending_payment';
        $trialEndsAt = null;
        $periodStart = $now;
    }

    // Calcular fim do periodo baseado no billing_cycle
    $periodEnd = clone $periodStart;
    switch ($plan['billing_cycle']) {
        case 'monthly':
            $periodEnd->modify('+1 month');
            break;
        case 'quarterly':
            $periodEnd->modify('+3 months');
            break;
        case 'yearly':
            $periodEnd->modify('+1 year');
            break;
    }

    // Criar assinatura
    $stmt = $db->prepare("
        INSERT INTO om_subscriptions
        (customer_id, plan_id, status, trial_ends_at, current_period_start, current_period_end, payment_method)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $customerId,
        $planId,
        $status,
        $trialEndsAt ? $trialEndsAt->format('Y-m-d H:i:s') : null,
        $periodStart->format('Y-m-d H:i:s'),
        $periodEnd->format('Y-m-d H:i:s'),
        $paymentMethod
    ]);

    $subscriptionId = (int)$db->lastInsertId();

    response(true, [
        'subscription_id' => $subscriptionId,
        'plan' => $plan['name'],
        'status' => $status,
        'trial_days' => $trialDays,
        'trial_ends_at' => $trialEndsAt ? $trialEndsAt->format('Y-m-d') : null,
        'period_end' => $periodEnd->format('Y-m-d'),
        'message' => $trialDays > 0
            ? "Parabens! Seu periodo de teste de {$trialDays} dias comecou."
            : "Assinatura ativada com sucesso!"
    ]);

} catch (Exception $e) {
    error_log("[subscriptions/subscribe] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar assinatura", 500);
}
