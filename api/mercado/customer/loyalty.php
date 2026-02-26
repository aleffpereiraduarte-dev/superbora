<?php
/**
 * GET /api/mercado/customer/loyalty.php
 * Retorna saldo de pontos, tier e historico de fidelidade
 */

require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Autenticacao necessaria", 401);
    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== 'customer') response(false, null, "Token invalido", 401);
    $customerId = (int)$payload['uid'];

    // Buscar saldo de pontos
    $stmt = $db->prepare("SELECT current_points FROM om_market_loyalty_points WHERE customer_id = ?");
    $stmt->execute([$customerId]);
    $points = $stmt->fetch();
    $balance = $points ? (int)$points['current_points'] : 0;

    // Buscar tier atual
    $stmt = $db->prepare("
        SELECT tier_name, min_points, benefits, badge_icon
        FROM om_market_loyalty_tiers
        WHERE min_points <= ? AND is_active = '1'
        ORDER BY min_points DESC
        LIMIT 1
    ");
    $stmt->execute([$balance]);
    $tier = $stmt->fetch();

    // Buscar proximo tier
    $stmt = $db->prepare("
        SELECT tier_name, min_points, benefits
        FROM om_market_loyalty_tiers
        WHERE min_points > ? AND is_active = '1'
        ORDER BY min_points ASC
        LIMIT 1
    ");
    $stmt->execute([$balance]);
    $nextTier = $stmt->fetch();

    // Historico recente de transacoes
    $stmt = $db->prepare("
        SELECT id, type, points, description, created_at
        FROM om_market_loyalty_transactions
        WHERE customer_id = ?
        ORDER BY created_at DESC
        LIMIT 20
    ");
    $stmt->execute([$customerId]);
    $transactions = $stmt->fetchAll();

    response(true, [
        'points_balance' => $balance,
        'points_value' => round($balance * 0.01, 2), // 1 ponto = R$ 0.01
        'tier' => $tier ? [
            'name' => $tier['tier_name'],
            'min_points' => (int)$tier['min_points'],
            'benefits' => $tier['benefits'],
            'icon' => $tier['badge_icon'] ?? null
        ] : ['name' => 'Bronze', 'min_points' => 0, 'benefits' => 'Acumule pontos a cada compra'],
        'next_tier' => $nextTier ? [
            'name' => $nextTier['tier_name'],
            'min_points' => (int)$nextTier['min_points'],
            'points_needed' => (int)$nextTier['min_points'] - $balance
        ] : null,
        'recent_transactions' => $transactions
    ]);

} catch (Exception $e) {
    error_log("[customer/loyalty] Erro: " . $e->getMessage());
    response(false, null, "Erro ao buscar dados de fidelidade", 500);
}
