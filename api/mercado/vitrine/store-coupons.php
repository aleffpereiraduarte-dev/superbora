<?php
/**
 * GET /api/mercado/vitrine/store-coupons.php
 * Lista cupons publicos disponiveis de uma loja
 *
 * Parameters:
 *   partner_id (required) - Store ID
 *   limit - Max coupons to return (default 5)
 */
require_once __DIR__ . "/../config/database.php";

setCorsHeaders();

header('Cache-Control: public, max-age=60');

try {
    $db = getDB();

    $partnerId = (int)($_GET['partner_id'] ?? 0);
    $limit = min(10, max(1, (int)($_GET['limit'] ?? 5)));

    if (!$partnerId) {
        response(false, null, "partner_id obrigatorio", 400);
    }

    $now = date('Y-m-d H:i:s');

    // Get public coupons that are:
    // - Active
    // - Not expired
    // - Either global or for this specific partner
    // - Public (not hidden)
    $stmt = $db->prepare("
        SELECT
            c.id,
            c.code,
            c.description,
            c.discount_type,
            c.discount_value,
            c.max_discount,
            c.min_order_value,
            c.valid_until,
            c.first_order_only,
            c.max_uses,
            (SELECT COUNT(*) FROM om_market_coupon_usage WHERE coupon_id = c.id) as uses_count
        FROM om_market_coupons c
        WHERE c.status = 'active'
          AND (c.valid_from IS NULL OR c.valid_from <= ?)
          AND (c.valid_until IS NULL OR c.valid_until >= ?)
          AND (
              c.specific_partners IS NULL
              OR c.specific_partners = ''
              OR c.specific_partners = '[]'
              OR c.specific_partners::jsonb @> ?::jsonb
          )
        ORDER BY c.discount_value DESC
        LIMIT ?
    ");
    $stmt->execute([$now, $now, json_encode([$partnerId]), $limit]);
    $coupons = $stmt->fetchAll();

    // Format coupons for display
    $formattedCoupons = [];
    foreach ($coupons as $coupon) {
        // Skip if max_uses reached
        if ($coupon['max_uses'] && $coupon['uses_count'] >= $coupon['max_uses']) {
            continue;
        }

        $discountText = '';
        switch ($coupon['discount_type']) {
            case 'percentage':
                $discountText = (int)$coupon['discount_value'] . '% OFF';
                break;
            case 'fixed':
                $discountText = 'R$ ' . number_format($coupon['discount_value'], 2, ',', '.') . ' OFF';
                break;
            case 'free_delivery':
                $discountText = 'Frete Gratis';
                break;
            case 'cashback':
                $discountText = (int)$coupon['discount_value'] . '% Cashback';
                break;
            default:
                $discountText = 'Desconto';
        }

        $conditions = [];
        if ($coupon['min_order_value'] > 0) {
            $conditions[] = 'Pedido min. R$ ' . number_format($coupon['min_order_value'], 2, ',', '.');
        }
        if ($coupon['first_order_only']) {
            $conditions[] = '1a compra';
        }
        if ($coupon['valid_until']) {
            $expiresDate = date('d/m', strtotime($coupon['valid_until']));
            $conditions[] = 'Ate ' . $expiresDate;
        }

        $formattedCoupons[] = [
            'id' => (int)$coupon['id'],
            'code' => $coupon['code'],
            'description' => $coupon['description'] ?: $discountText,
            'discount_text' => $discountText,
            'discount_type' => $coupon['discount_type'],
            'discount_value' => (float)$coupon['discount_value'],
            'max_discount' => $coupon['max_discount'] ? (float)$coupon['max_discount'] : null,
            'min_order_value' => (float)$coupon['min_order_value'],
            'conditions' => $conditions,
            'first_order_only' => (bool)$coupon['first_order_only'],
        ];
    }

    response(true, ['coupons' => $formattedCoupons]);

} catch (Exception $e) {
    error_log("[vitrine/store-coupons] Erro: " . $e->getMessage());
    response(false, ['coupons' => []], "Erro ao carregar cupons", 500);
}
