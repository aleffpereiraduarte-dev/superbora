<?php
/**
 * POST /api/mercado/customer/loyalty-redeem.php
 * Resgata uma recompensa do programa de fidelidade
 * Body: { reward_id }
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
    $rewardId = (int)($input['reward_id'] ?? 0);
    if (!$rewardId) response(false, null, "reward_id obrigatorio", 400);

    $db->beginTransaction();

    // Buscar recompensa
    $stmt = $db->prepare("
        SELECT reward_id, name, points_cost, reward_type, reward_value, is_active
        FROM om_market_loyalty_rewards
        WHERE reward_id = ? AND is_active = '1'
        FOR UPDATE
    ");
    $stmt->execute([$rewardId]);
    $reward = $stmt->fetch();

    if (!$reward) {
        $db->rollBack();
        response(false, null, "Recompensa nao encontrada ou indisponivel", 404);
    }

    // Buscar saldo do cliente
    $stmt = $db->prepare("SELECT current_points FROM om_market_loyalty_points WHERE customer_id = ? FOR UPDATE");
    $stmt->execute([$customerId]);
    $points = $stmt->fetch();
    $balance = $points ? (int)$points['current_points'] : 0;

    if ($balance < (int)$reward['points_cost']) {
        $db->rollBack();
        response(false, null, "Pontos insuficientes. Voce tem $balance pontos, precisa de {$reward['points_cost']}", 400);
    }

    // Debitar pontos
    $db->prepare("UPDATE om_market_loyalty_points SET current_points = current_points - ? WHERE customer_id = ?")
        ->execute([$reward['points_cost'], $customerId]);

    // Registrar transacao
    $db->prepare("
        INSERT INTO om_market_loyalty_transactions (customer_id, type, points, description, reference_id, created_at)
        VALUES (?, 'debit', ?, ?, ?, NOW())
    ")->execute([$customerId, $reward['points_cost'], "Resgate: {$reward['name']}", $rewardId]);

    // Aplicar recompensa conforme tipo
    $rewardData = [];
    switch ($reward['reward_type']) {
        case 'coupon':
        case 'discount':
            // Gerar cupom unico
            $couponCode = 'FIDELIDADE_' . strtoupper(bin2hex(random_bytes(4)));
            try {
                $db->prepare("
                    INSERT INTO om_market_coupons (code, discount_type, discount_value, min_order, max_uses, uses_count, customer_id, expires_at, is_active, created_at)
                    VALUES (?, 'fixed', ?, 0, 1, 0, ?, NOW() + INTERVAL '30 days', '1', NOW())
                ")->execute([$couponCode, $reward['reward_value'], $customerId]);
            } catch (Exception $e) {
                error_log("[loyalty-redeem] Cupom create failed: " . $e->getMessage());
            }
            $rewardData['coupon_code'] = $couponCode;
            $rewardData['discount_value'] = $reward['reward_value'];
            break;

        case 'cashback':
            // Creditar cashback
            try {
                require_once dirname(__DIR__, 3) . '/helpers/cashback.php';
                creditCashback($customerId, (float)$reward['reward_value'], "Resgate fidelidade: {$reward['name']}");
            } catch (Exception $e) {
                error_log("[loyalty-redeem] Cashback credit failed: " . $e->getMessage());
            }
            $rewardData['cashback_value'] = $reward['reward_value'];
            break;

        case 'frete_gratis':
            $couponCode = 'FRETE_' . strtoupper(bin2hex(random_bytes(4)));
            try {
                $db->prepare("
                    INSERT INTO om_market_coupons (code, discount_type, discount_value, min_order, max_uses, uses_count, customer_id, free_delivery, expires_at, is_active, created_at)
                    VALUES (?, 'fixed', 0, 0, 1, 0, ?, true, NOW() + INTERVAL '30 days', '1', NOW())
                ")->execute([$couponCode, $customerId]);
            } catch (Exception $e) {
                error_log("[loyalty-redeem] Frete gratis coupon failed: " . $e->getMessage());
            }
            $rewardData['coupon_code'] = $couponCode;
            $rewardData['free_delivery'] = true;
            break;
    }

    $db->commit();

    $newBalance = $balance - (int)$reward['points_cost'];
    response(true, [
        'reward' => $reward['name'],
        'points_used' => (int)$reward['points_cost'],
        'new_balance' => $newBalance,
        'reward_data' => $rewardData,
    ], "Recompensa resgatada com sucesso!");

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log("[loyalty-redeem] Erro: " . $e->getMessage());
    response(false, null, "Erro ao resgatar recompensa", 500);
}
