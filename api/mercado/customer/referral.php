<?php
/**
 * GET /api/mercado/customer/referral.php
 * Retorna dados do programa de indicacao do cliente
 *
 * Query: customer_id (int)
 * Response: { referral_code, total_referrals, total_earnings, referrals[] }
 */
require_once __DIR__ . "/../config/database.php";
setCorsHeaders();

try {
    $db = getDB();
    $customer_id = requireCustomerAuth();

    // Table om_referrals created via migration

    // Buscar ou criar código de indicação
    $stmt = $db->prepare("SELECT referral_code FROM om_referrals WHERE referrer_id = ? LIMIT 1");
    $stmt->execute([$customer_id]);
    $existing = $stmt->fetchColumn();

    if (!$existing) {
        // Gerar código único: SB + 8 chars (cryptographically random)
        $code = 'SB' . strtoupper(bin2hex(random_bytes(4)));
        $stmt = $db->prepare("INSERT INTO om_referrals (referrer_id, referral_code, status) VALUES (?, ?, 'pending')");
        $stmt->execute([$customer_id, $code]);
        $existing = $code;
    }

    // Buscar estatísticas
    $stmt = $db->prepare("
        SELECT
            COUNT(CASE WHEN status = 'completed' THEN 1 END) as total_referrals,
            COALESCE(SUM(CASE WHEN status = 'completed' THEN reward_amount ELSE 0 END), 0) as total_earnings
        FROM om_referrals
        WHERE referrer_id = ? AND referred_id IS NOT NULL
    ");
    $stmt->execute([$customer_id]);
    $stats = $stmt->fetch();

    // Últimas indicações
    $stmt = $db->prepare("
        SELECT r.status, r.reward_amount, r.created_at, r.completed_at,
               p.nome as referred_name
        FROM om_referrals r
        LEFT JOIN boraum_passageiros p ON p.passageiro_id = r.referred_id
        WHERE r.referrer_id = ? AND r.referred_id IS NOT NULL
        ORDER BY r.created_at DESC
        LIMIT 20
    ");
    $stmt->execute([$customer_id]);
    $referrals = $stmt->fetchAll();

    response(true, [
        'referral_code' => $existing,
        'referral_link' => 'https://superbora.com.br/mercado/vitrine/?ref=' . $existing,
        'total_referrals' => (int)$stats['total_referrals'],
        'total_earnings' => (float)$stats['total_earnings'],
        'reward_per_referral' => 10.00,
        'referrals' => $referrals
    ]);

} catch (Exception $e) {
    error_log("[referral] Erro: " . $e->getMessage());
    response(false, null, 'Erro interno do servidor', 500);
}
