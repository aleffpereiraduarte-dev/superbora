<?php
/**
 * POST /api/mercado/boraum/cupom.php
 *
 * Valida um cupom antes do checkout (preview do desconto).
 * Body: { "code": "CUPOM10", "partner_id": 123, "subtotal": 50.00 }
 *
 * Retorna:
 *   { valid: true, discount, discount_type, message }
 *   { valid: false, discount: 0, message }
 */
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/auth.php";

setCorsHeaders();

try {
    $db = getDB();

    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        response(false, null, "Metodo nao permitido", 405);
    }

    // Auth obrigatorio
    $user = requirePassageiro($db);

    $input = getInput();
    $code       = strtoupper(trim($input['code'] ?? ''));
    $partnerId  = (int)($input['partner_id'] ?? 0);
    $subtotal   = (float)($input['subtotal'] ?? 0);

    if (empty($code)) {
        response(true, [
            "valid"   => false,
            "discount" => 0,
            "message" => "Codigo do cupom e obrigatorio"
        ]);
    }

    // Buscar cupom ativo
    $stmt = $db->prepare("
        SELECT id, code, name, discount_type, discount_value, max_discount,
               min_order_value, max_uses, current_uses, max_uses_per_user,
               valid_from, valid_until, first_order_only, specific_partners
        FROM om_market_coupons
        WHERE code = ? AND status = 'active'
        LIMIT 1
    ");
    $stmt->execute([$code]);
    $coupon = $stmt->fetch();

    if (!$coupon) {
        response(true, [
            "valid"   => false,
            "discount" => 0,
            "message" => "Cupom nao encontrado"
        ]);
    }

    // Verificar validade temporal
    if (!empty($coupon['valid_from']) && strtotime($coupon['valid_from']) > time()) {
        response(true, [
            "valid"   => false,
            "discount" => 0,
            "message" => "Cupom ainda nao esta ativo"
        ]);
    }

    if (!empty($coupon['valid_until']) && strtotime($coupon['valid_until']) < time()) {
        response(true, [
            "valid"   => false,
            "discount" => 0,
            "message" => "Cupom expirado"
        ]);
    }

    // Verificar limite de usos global
    if (!empty($coupon['max_uses']) && (int)$coupon['max_uses'] > 0) {
        if ((int)($coupon['current_uses'] ?? 0) >= (int)$coupon['max_uses']) {
            response(true, [
                "valid"   => false,
                "discount" => 0,
                "message" => "Cupom esgotado"
            ]);
        }
    }

    // Verificar limite por usuario
    if (!empty($coupon['max_uses_per_user']) && (int)$coupon['max_uses_per_user'] > 0 && $user['customer_id']) {
        $stmtUsage = $db->prepare("
            SELECT COUNT(*) as uses FROM om_market_coupon_usage
            WHERE coupon_id = ? AND customer_id = ?
        ");
        $stmtUsage->execute([$coupon['id'], $user['customer_id']]);
        $userUses = (int)$stmtUsage->fetch()['uses'];

        if ($userUses >= (int)$coupon['max_uses_per_user']) {
            response(true, [
                "valid"   => false,
                "discount" => 0,
                "message" => "Voce ja usou este cupom"
            ]);
        }
    }

    // Verificar pedido minimo
    $minOrder = (float)($coupon['min_order_value'] ?? 0);
    if ($minOrder > 0 && $subtotal < $minOrder) {
        response(true, [
            "valid"   => false,
            "discount" => 0,
            "message" => "Pedido minimo de R$ " . number_format($minOrder, 2, ',', '.') . " para este cupom"
        ]);
    }

    // Verificar primeiro pedido
    if (!empty($coupon['first_order_only']) && $user['customer_id']) {
        $stmtOrders = $db->prepare("
            SELECT COUNT(*) as total FROM om_market_orders
            WHERE customer_id = ? AND status NOT IN ('cancelled','rejected')
        ");
        $stmtOrders->execute([$user['customer_id']]);
        $orderCount = (int)$stmtOrders->fetch()['total'];

        if ($orderCount > 0) {
            response(true, [
                "valid"   => false,
                "discount" => 0,
                "message" => "Cupom valido apenas para o primeiro pedido"
            ]);
        }
    }

    // Verificar parceiro especifico
    if (!empty($coupon['specific_partners']) && $partnerId > 0) {
        $partners = json_decode($coupon['specific_partners'], true);
        if (is_array($partners) && count($partners) > 0 && !in_array($partnerId, $partners)) {
            response(true, [
                "valid"   => false,
                "discount" => 0,
                "message" => "Cupom nao valido para esta loja"
            ]);
        }
    }

    // Calcular desconto
    $discount = 0;
    $discountType = $coupon['discount_type'] ?? 'fixed';
    $message = '';

    switch ($discountType) {
        case 'percentage':
            $discount = round($subtotal * (float)$coupon['discount_value'] / 100, 2);
            if (!empty($coupon['max_discount']) && $discount > (float)$coupon['max_discount']) {
                $discount = (float)$coupon['max_discount'];
            }
            $message = "Cupom de " . (int)$coupon['discount_value'] . "% aplicado!";
            if (!empty($coupon['max_discount'])) {
                $message .= " (max R$ " . number_format((float)$coupon['max_discount'], 2, ',', '.') . ")";
            }
            break;

        case 'fixed':
            $discount = min((float)$coupon['discount_value'], $subtotal);
            $message = "Cupom de R$ " . number_format($discount, 2, ',', '.') . " aplicado!";
            break;

        case 'free_delivery':
            $discount = 0;
            $message = "Frete gratis aplicado!";
            $discountType = 'free_delivery';
            break;

        case 'cashback':
            $discount = round($subtotal * (float)$coupon['discount_value'] / 100, 2);
            if (!empty($coupon['max_discount']) && $discount > (float)$coupon['max_discount']) {
                $discount = (float)$coupon['max_discount'];
            }
            $message = "Cashback de " . (int)$coupon['discount_value'] . "% (R$ " . number_format($discount, 2, ',', '.') . ")";
            break;
    }

    response(true, [
        "valid"         => true,
        "discount"      => round($discount, 2),
        "discount_type" => $discountType,
        "coupon_name"   => $coupon['name'] ?? $code,
        "message"       => $message
    ]);

} catch (Exception $e) {
    error_log("[boraum/cupom] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
