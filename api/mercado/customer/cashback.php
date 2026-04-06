<?php
/**
 * GET /api/mercado/customer/cashback.php
 * Retorna saldo e histórico de cashback do cliente
 *
 * Query: customer_id (int)
 * Response: { wallet: { available, pending, total }, history[] }
 */
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../helpers/cache.php";
setCorsHeaders();

try {
    $db = getDB();
    $customer_id = requireCustomerAuth();

    // Table om_cashback created via migration

    // Cache cashback data for 30 seconds per customer
    $result = cachedQuery("cashback:{$customer_id}", 30, function() use ($db, $customer_id) {
        // SECURITY: Wrap expiry + balance read in a transaction to prevent race condition
        // (expiry could change rows between the UPDATE and SELECT, giving stale balance)
        $db->beginTransaction();

        // Expirar cashback vencido
        $db->prepare("
            UPDATE om_cashback SET status = 'expired'
            WHERE customer_id = ? AND status = 'available' AND expires_at IS NOT NULL AND expires_at < NOW()
        ")->execute([$customer_id]);

        // Calcular saldos (earned/bonus available minus used)
        $stmt = $db->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN type IN ('earned','bonus') AND status = 'available' THEN amount ELSE 0 END), 0)
                  - COALESCE(SUM(CASE WHEN type = 'used' THEN amount ELSE 0 END), 0) as available,
                COALESCE(SUM(CASE WHEN type IN ('earned','bonus') AND status = 'pending' THEN amount ELSE 0 END), 0) as pending,
                COALESCE(SUM(CASE WHEN type IN ('earned','bonus') AND status IN ('available','pending') THEN amount ELSE 0 END), 0)
                  - COALESCE(SUM(CASE WHEN type = 'used' THEN amount ELSE 0 END), 0) as total
            FROM om_cashback
            WHERE customer_id = ?
        ");
        $stmt->execute([$customer_id]);
        $wallet = $stmt->fetch();

        $db->commit();

        $available = max(0, (float)$wallet['available']);

        // Histórico recente
        $stmt = $db->prepare("
            SELECT type, amount, description, status, created_at
            FROM om_cashback
            WHERE customer_id = ?
            ORDER BY created_at DESC
            LIMIT 30
        ");
        $stmt->execute([$customer_id]);
        $history = $stmt->fetchAll();

        return [
            'wallet' => [
                'available' => round($available, 2),
                'pending' => round((float)$wallet['pending'], 2),
                'total' => round($available + (float)$wallet['pending'], 2)
            ],
            'history' => $history
        ];
    });

    response(true, $result);

} catch (Exception $e) {
    error_log("[cashback] Erro: " . $e->getMessage());
    response(false, null, 'Erro interno do servidor', 500);
}
