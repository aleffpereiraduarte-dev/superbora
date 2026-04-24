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
require_once __DIR__ . "/../helpers/cache.php";

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

    // Remoção de cupom: applyCoupon('') do frontend chega aqui com code vazio.
    // Antes isso dava erro "Informe o codigo" e o front mostrava alerta de erro;
    // agora removemos o cupom do Redis e retornamos sucesso (idempotente).
    if (empty($code)) {
        $cartCouponKey = "cart_coupon:c{$customer_id}";
        cacheDelete($cartCouponKey);
        cacheInvalidateCart((int)$customer_id, '');
        try {
            require_once __DIR__ . '/../helpers/ws-customer-broadcast.php';
            wsBroadcastToCustomer((int)$customer_id, 'cart_updated', ['action' => 'coupon_removed']);
        } catch (Throwable $e) { /* best effort */ }
        response(true, ["valido" => true, "removed" => true], "Cupom removido");
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
        SELECT COALESCE(SUM(
            CASE WHEN p.special_price IS NOT NULL AND p.special_price > 0 AND p.special_price < p.price
                 THEN p.special_price ELSE p.price END
            * c.quantity), 0) AS subtotal
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

    // Structured reason codes allow the mobile app to render specific copy.
    // Keep the user-friendly message for legacy clients that only read `message`.
    $failReason = function($reason, $message, $extra = []) {
        response(false, array_merge(["valido" => false, "reason" => $reason], $extra), $message, 400);
    };

    if (!$cupom) {
        $failReason('not_found', "Cupom invalido");
    }

    // Validar datas
    $now = date('Y-m-d H:i:s');
    if (!empty($cupom['valid_from']) && $now < $cupom['valid_from']) {
        $failReason('not_yet_active', "Cupom ainda nao esta ativo", ["valid_from" => $cupom['valid_from']]);
    }
    if (!empty($cupom['valid_until']) && $now > $cupom['valid_until']) {
        $failReason('expired', "Cupom expirado", ["valid_until" => $cupom['valid_until']]);
    }

    // Validar max_uses global — checar BOTH current_uses na tabela coupons E
    // historico em coupon_usage. Antes so checava usage, entao um UPDATE manual
    // em current_uses sem adicionar usage linha deixava cupom reutilizavel.
    if (!empty($cupom['max_uses']) && (int)$cupom['max_uses'] > 0) {
        $maxUses = (int)$cupom['max_uses'];
        $currentUses = (int)($cupom['current_uses'] ?? 0);
        if ($currentUses >= $maxUses) {
            $failReason('max_usage', "Cupom esgotado");
        }
        $stmt = $db->prepare("SELECT COUNT(*) FROM om_market_coupon_usage WHERE coupon_id = ?");
        $stmt->execute([$cupom['id']]);
        $totalUses = (int)$stmt->fetchColumn();
        if ($totalUses >= $maxUses) {
            $failReason('max_usage', "Cupom esgotado");
        }
    }

    // Validar max_uses_per_user
    if ($customer_id && !empty($cupom['max_uses_per_user']) && (int)$cupom['max_uses_per_user'] > 0) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM om_market_coupon_usage WHERE coupon_id = ? AND customer_id = ?");
        $stmt->execute([$cupom['id'], $customer_id]);
        $userUses = (int)$stmt->fetchColumn();
        if ($userUses >= (int)$cupom['max_uses_per_user']) {
            $failReason('already_used', "Voce ja usou este cupom o maximo de vezes permitido");
        }
    }

    // Validar min_order_value
    if (!empty($cupom['min_order_value']) && $subtotal < (float)$cupom['min_order_value']) {
        $minVal = number_format((float)$cupom['min_order_value'], 2, ',', '.');
        $failReason('min_order', "Pedido minimo para este cupom: R$ $minVal", ["min_order_value" => (float)$cupom['min_order_value']]);
    }

    // Validar first_order_only
    if (!empty($cupom['first_order_only']) && (int)$cupom['first_order_only'] === 1 && $customer_id) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM om_market_orders WHERE customer_id = ? AND status NOT IN ('cancelado')");
        $stmt->execute([$customer_id]);
        $orderCount = (int)$stmt->fetchColumn();
        if ($orderCount > 0) {
            $failReason('not_for_user', "Cupom valido apenas para primeiro pedido");
        }
    }

    // Validar specific_partners
    if (!empty($cupom['specific_partners'])) {
        $partners = json_decode($cupom['specific_partners'], true);
        if (is_array($partners) && !empty($partners)) {
            // Use cart's actual partner if client didn't provide partner_id
            $checkPartnerId = $partner_id;
            if (!$checkPartnerId && $customer_id) {
                $stmtCartPartner = $db->prepare("SELECT DISTINCT partner_id FROM om_market_cart WHERE customer_id = ? LIMIT 1");
                $stmtCartPartner->execute([$customer_id]);
                $checkPartnerId = (int)$stmtCartPartner->fetchColumn();
            }
            if ($checkPartnerId && !in_array($checkPartnerId, $partners)) {
                $failReason('partner_mismatch', "Cupom nao valido para esta loja");
            }
        }
    }

    // Validar specific_customers — cupom exclusivo pra lista de customer_ids (IA de campanhas)
    if (!empty($cupom['specific_customers'])) {
        $allowed = json_decode($cupom['specific_customers'], true);
        if (is_array($allowed) && !empty($allowed)) {
            if (!$customer_id || !in_array((int)$customer_id, array_map('intval', $allowed), true)) {
                $failReason('not_for_user', "Cupom exclusivo — nao disponivel para voce");
            }
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

    if ($customer_id > 0) {
        // Persiste cupom aplicado no Redis. Antes o endpoint só retornava o
        // desconto calculado e broadcastava WS — mas /listar.php não tinha
        // noção do cupom ativo, então ao fazer fetchCart depois, o discount
        // voltava pra 0 e o user via "cupom aplicou mas desconto sumiu".
        // Agora o listar.php lê essa key e recalcula o desconto autoritariamente.
        $cartCouponKey = "cart_coupon:c{$customer_id}";
        cacheSet($cartCouponKey, [
            'coupon_id' => (int)$cupom['id'],
            'code' => $code,
            'discount_type' => $discount_type,
            'discount_value' => $discount_value,
            'max_discount' => $max_discount,
            'min_order_value' => isset($cupom['min_order_value']) ? (float)$cupom['min_order_value'] : 0,
            'specific_partners' => $cupom['specific_partners'] ?? null,
            'descricao' => $descricao,
            'free_delivery' => $discount_type === 'free_delivery',
        ], 86400); // TTL 24h — cobre sessão de compra normal sem lixo eterno

        // Invalidate listar cache so clients reacting to the WS push fetch fresh.
        cacheInvalidateCart((int)$customer_id, '');
        try {
            require_once __DIR__ . '/../helpers/ws-customer-broadcast.php';
            wsBroadcastToCustomer((int)$customer_id, 'cart_updated', [
                'action' => 'coupon',
                'coupon_code' => $code,
                'discount' => round($desconto, 2),
            ]);
        } catch (Throwable $e) { /* best effort */ }
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
