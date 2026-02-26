<?php
/**
 * GET /api/mercado/vitrine/orders/savings.php
 * Returns total savings for the authenticated customer
 */
require_once __DIR__ . "/../../config/database.php";
require_once dirname(__DIR__, 4) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    // Check authentication (signature-verified)
    $customerId = null;
    $token = om_auth()->getTokenFromRequest();
    if ($token) {
        $payload = om_auth()->validateToken($token);
        if ($payload && ($payload['type'] ?? '') === 'customer') {
            $customerId = (int)$payload['uid'];
        }
    }

    if (!$customerId) {
        response(true, ['total_savings' => 0]);
    }

    // Calculate total savings from delivered orders
    // Column is 'discount' (not 'discount_amount') + 'coupon_discount'
    $stmt = $db->prepare("
        SELECT
            COALESCE(SUM(discount), 0) + COALESCE(SUM(coupon_discount), 0) as total_savings,
            COUNT(*) as total_orders
        FROM om_market_orders
        WHERE customer_id = ?
        AND status IN ('entregue', 'concluido')
    ");
    $stmt->execute([$customerId]);
    $result = $stmt->fetch();

    response(true, [
        'total_savings' => floatval($result['total_savings'] ?? 0),
        'total_orders' => (int)($result['total_orders'] ?? 0)
    ]);
} catch (PDOException $e) {
    error_log("[savings] DB error for customer {$customerId}: " . $e->getMessage());
    response(false, null, "Erro ao calcular economias", 500);
}
