<?php
/**
 * POST /api/mercado/carrinho/cupom.php
 * A1: Validar e aplicar cupom no carrinho
 * Body: { "code": "PROMO10", "customer_id": 1, "session_id": "xxx", "partner_id": 2, "subtotal": 50.00 }
 */
require_once __DIR__ . "/../config/database.php";
setCorsHeaders();
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 2) . "/cache/CacheHelper.php";

try {
    $input = getInput();
    $db = getDB();

    // SECURITY: Use authenticated customer_id when available
    OmAuth::getInstance()->setDb($db);
    $authCustomerId = 0;
    try {
        $token = om_auth()->getTokenFromRequest();
        if ($token) {
            $payload = om_auth()->validateToken($token);
            if ($payload && $payload['type'] === 'customer') {
                $authCustomerId = (int)$payload['uid'];
            }
        }
    } catch (Exception $e) { /* auth optional */ }

    $code = strtoupper(trim(substr($input["code"] ?? "", 0, 50)));
    $customer_id = $authCustomerId; // SECURITY: never trust client-supplied customer_id
    $session_id = preg_replace('/[^a-zA-Z0-9_-]/', '', $input["session_id"] ?? "");
    $partner_id = (int)($input["partner_id"] ?? 0);
    $cart_items_count = (int)($input["cart_items_count"] ?? 0);

    // Autenticação obrigatória para cupom
    if ($customer_id === 0) {
        response(false, null, "Faca login para usar cupom", 401);
    }

    if (empty($code)) {
        response(false, null, "Informe o codigo do cupom", 400);
    }

    // Calcular subtotal real do carrinho — authenticated uses customer_id only
    if ($customer_id > 0) {
        $whereClause = "c.customer_id = ?";
        $whereParams = [$customer_id];
    } else {
        $whereClause = "c.session_id = ?";
        $whereParams = [$session_id];
    }
    $stmtSub = $db->prepare("
        SELECT COALESCE(SUM(p.price * c.quantity), 0) AS subtotal
        FROM om_market_cart c
        INNER JOIN om_market_products p ON c.product_id = p.product_id
        WHERE {$whereClause}
    ");
    $stmtSub->execute($whereParams);
    $subtotal = (float)$stmtSub->fetchColumn();

    // Buscar cupom
    $stmt = $db->prepare("
        SELECT * FROM om_market_coupons
        WHERE code = ? AND status = 'active'
    ");
    $stmt->execute([$code]);
    $cupom = $stmt->fetch();

    if (!$cupom) {
        response(false, ["valido" => false], "Cupom invalido ou expirado", 400);
    }

    // Validar datas
    $now = date('Y-m-d H:i:s');
    if (!empty($cupom['valid_from']) && $now < $cupom['valid_from']) {
        response(false, ["valido" => false], "Cupom ainda nao esta ativo", 400);
    }
    if (!empty($cupom['valid_until']) && $now > $cupom['valid_until']) {
        response(false, ["valido" => false], "Cupom expirado", 400);
    }

    // Validar max_uses global
    if (!empty($cupom['max_uses']) && (int)$cupom['max_uses'] > 0) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM om_market_coupon_usage WHERE coupon_id = ?");
        $stmt->execute([$cupom['id']]);
        $totalUses = (int)$stmt->fetchColumn();
        if ($totalUses >= (int)$cupom['max_uses']) {
            response(false, ["valido" => false], "Cupom esgotado", 400);
        }
    }

    // Validar max_uses_per_user
    if ($customer_id && !empty($cupom['max_uses_per_user']) && (int)$cupom['max_uses_per_user'] > 0) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM om_market_coupon_usage WHERE coupon_id = ? AND customer_id = ?");
        $stmt->execute([$cupom['id'], $customer_id]);
        $userUses = (int)$stmt->fetchColumn();
        if ($userUses >= (int)$cupom['max_uses_per_user']) {
            response(false, ["valido" => false], "Voce ja usou este cupom o maximo de vezes permitido", 400);
        }
    }

    // Validar min_order_value
    if (!empty($cupom['min_order_value']) && $subtotal < (float)$cupom['min_order_value']) {
        $minVal = number_format((float)$cupom['min_order_value'], 2, ',', '.');
        response(false, ["valido" => false], "Pedido minimo para este cupom: R$ $minVal", 400);
    }

    // Validar first_order_only
    if (!empty($cupom['first_order_only']) && (int)$cupom['first_order_only'] === 1 && $customer_id) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM om_market_orders WHERE customer_id = ? AND status NOT IN ('cancelado')");
        $stmt->execute([$customer_id]);
        $orderCount = (int)$stmt->fetchColumn();
        if ($orderCount > 0) {
            response(false, ["valido" => false], "Cupom valido apenas para primeiro pedido", 400);
        }
    }

    // Validar specific_partners
    if (!empty($cupom['specific_partners'])) {
        $partners = json_decode($cupom['specific_partners'], true);
        if (is_array($partners) && !empty($partners) && $partner_id && !in_array($partner_id, $partners)) {
            response(false, ["valido" => false], "Cupom nao valido para esta loja", 400);
        }
    }

    // Calcular desconto
    $discount_type = $cupom['discount_type'] ?? 'percentage';
    $discount_value = (float)($cupom['discount_value'] ?? 0);
    $max_discount = !empty($cupom['max_discount']) ? (float)$cupom['max_discount'] : null;
    $desconto = 0;
    $descricao = '';

    switch ($discount_type) {
        case 'percentage':
            $desconto = round($subtotal * ($discount_value / 100), 2);
            if ($max_discount && $desconto > $max_discount) {
                $desconto = $max_discount;
            }
            $descricao = $discount_value . '% OFF' . ($max_discount ? ' (max R$ ' . number_format($max_discount, 2, ',', '.') . ')' : '');
            break;

        case 'fixed':
            $desconto = min($discount_value, $subtotal);
            $descricao = 'R$ ' . number_format($discount_value, 2, ',', '.') . ' OFF';
            break;

        case 'free_delivery':
            $desconto = 0; // Desconto aplicado na taxa de entrega, nao no subtotal
            $descricao = 'Entrega gratis';
            break;

        case 'cashback':
            $desconto = round($subtotal * ($discount_value / 100), 2);
            if ($max_discount && $desconto > $max_discount) {
                $desconto = $max_discount;
            }
            $descricao = $discount_value . '% cashback' . ($max_discount ? ' (max R$ ' . number_format($max_discount, 2, ',', '.') . ')' : '');
            break;

        default:
            $desconto = 0;
            $descricao = 'Desconto aplicado';
    }

    response(true, [
        "valido" => true,
        "desconto" => round($desconto, 2),
        "tipo" => $discount_type,
        "descricao" => $descricao,
        "cupom_id" => (int)$cupom['id'],
        "codigo" => $code,
        "free_delivery" => $discount_type === 'free_delivery'
    ], "Cupom aplicado!");

} catch (Exception $e) {
    error_log("[API Cupom] Erro: " . $e->getMessage());
    response(false, null, "Erro ao validar cupom", 500);
}
