<?php
/**
 * GET /api/mercado/customer/coupons.php
 * Returns available coupons for the authenticated customer
 * Used by checkout.jsx to show applicable coupons
 */
require_once __DIR__ . "/../config/database.php";

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    response(false, null, "Metodo nao permitido", 405);
}

try {
    $db = getDB();
    $customerId = requireCustomerAuth();
    $partnerId = (int)($_GET['partner_id'] ?? 0);

    // Schema real: om_market_coupons usa status/valid_until/specific_partners (JSONB)
    $now = date('Y-m-d H:i:s');

    $partnerFilter = '';
    $extraParams = [];
    if ($partnerId) {
        $partnerFilter = "AND (
            c.specific_partners IS NULL OR c.specific_partners = ''
            OR c.specific_partners = '[]'
            OR c.specific_partners::jsonb @> ?::jsonb
        )";
        $extraParams[] = json_encode([$partnerId]);
    }

    $stmt = $db->prepare("
        SELECT c.id, c.code, c.description, c.discount_type, c.discount_value,
               c.min_order_value, c.max_discount,
               c.valid_from, c.valid_until, c.max_uses,
               c.first_order_only, c.specific_partners,
               (SELECT COUNT(*) FROM om_market_coupon_usage WHERE coupon_id = c.id) AS uses_count,
               (SELECT COUNT(*) FROM om_market_coupon_usage WHERE coupon_id = c.id AND customer_id = ?) AS used_by_customer
        FROM om_market_coupons c
        WHERE c.status = 'active'
          AND (c.valid_from IS NULL OR c.valid_from <= ?)
          AND (c.valid_until IS NULL OR c.valid_until >= ?)
          {$partnerFilter}
        ORDER BY c.discount_value DESC
        LIMIT 50
    ");
    $stmt->execute(array_merge([$customerId, $now, $now], $extraParams));
    $coupons = $stmt->fetchAll();

    $result = [];
    foreach ($coupons as $c) {
        // Filter out coupons that exceeded max_uses or first_order_only already used
        if ($c['max_uses'] && $c['uses_count'] >= $c['max_uses']) continue;
        if ($c['first_order_only'] && $c['used_by_customer'] > 0) continue;

        $result[] = [
            'id' => (int)$c['id'],
            'code' => $c['code'],
            'description' => $c['description'],
            'discount_type' => $c['discount_type'],
            'discount_value' => (float)$c['discount_value'],
            'min_order_value' => (float)($c['min_order_value'] ?? 0),
            'max_discount' => $c['max_discount'] ? (float)$c['max_discount'] : null,
            'first_order_only' => (bool)$c['first_order_only'],
            'valid_until' => $c['valid_until'],
        ];
    }

    response(true, ['coupons' => $result]);

} catch (Exception $e) {
    error_log("[customer/coupons] Erro: " . $e->getMessage());
    response(false, null, "Erro ao listar cupons", 500);
}
