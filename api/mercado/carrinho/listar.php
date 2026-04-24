<?php
/**
 * GET /api/mercado/carrinho/listar.php?session_id=xxx
 */
require_once __DIR__ . "/../config/database.php";
setCorsHeaders();
require_once dirname(__DIR__, 3) . "/includes/classes/OmPricing.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once __DIR__ . "/../helpers/cache.php";

try {
    $db = getDB();

    // SECURITY: Use authenticated customer_id when available (ignore client input)
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
    } catch (Exception $e) {
        // Auth is optional for cart listing (anonymous sessions allowed)
    }

    $session_id = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($_GET["session_id"] ?? ''));
    $customer_id = $authCustomerId; // SECURITY: never trust client-supplied customer_id
    $routeMode = (int)($_GET["route_mode"] ?? 0);
    $primaryPartnerId = (int)($_GET["primary_partner_id"] ?? 0);

    if (!$session_id && !$customer_id) {
        response(true, ["itens" => [], "subtotal" => 0, "taxa_entrega" => 0, "total" => 0]);
    }

    // SECURITY: Validate session_id format for anonymous carts (must be UUID-like)
    if (!$customer_id && $session_id) {
        if (!preg_match('/^[a-f0-9]{8}-?[a-f0-9]{4}-?[a-f0-9]{4}-?[a-f0-9]{4}-?[a-f0-9]{12}$/i', $session_id)
            && !preg_match('/^sess_[a-zA-Z0-9_-]{20,60}$/', $session_id)) {
            response(true, ["itens" => [], "subtotal" => 0, "taxa_entrega" => 0, "total" => 0]);
        }
    }

    // --- Redis short-TTL cache (5s) -------------------------------------------------
    // listar.php is polled aggressively from the mobile app (checkout screen, tabs
    // badge, cart drawer). Cache the fully-computed response so repeated hits within
    // 5s skip the Postgres round-trip + PHP recomputation. Cache is invalidated by
    // adicionar/remover/limpar/cupom.php on every mutation, so staleness is bounded
    // to the 5s window only when no mutation happens (i.e. user just looking).
    // The route_mode/primary_partner_id params change the computed totals, so they
    // are part of the cache key.
    $cacheKey = $customer_id > 0
        ? "cart_listar:customer_{$customer_id}:rm{$routeMode}:pp{$primaryPartnerId}"
        : "cart_listar:sess_{$session_id}:rm{$routeMode}:pp{$primaryPartnerId}";
    $cached = cacheGet($cacheKey);
    if ($cached !== null) {
        response(true, $cached);
    }

    // Build WHERE clause — authenticated users use customer_id only, anonymous use session_id
    if ($customer_id > 0) {
        $whereClause = "c.customer_id = ?";
        $whereParams = [$customer_id];
    } else {
        $whereClause = "c.session_id = ?";
        $whereParams = [$session_id];
    }

    $sql = "SELECT c.cart_id, c.product_id, c.partner_id, c.quantity, p.price, c.notes,
                   p.name, p.image, p.unit, p.special_price, p.quantity as estoque,
                   pr.name as parceiro_nome, pr.delivery_fee, pr.free_delivery_above, pr.entrega_propria,
                   pr.is_open, pr.pause_until,
                   p.product_id AS product_exists, p.status AS product_status
            FROM om_market_cart c
            LEFT JOIN om_market_products p ON c.product_id = p.product_id
            LEFT JOIN om_market_partners pr ON c.partner_id = pr.partner_id
            WHERE {$whereClause}
            ORDER BY c.cart_id ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute($whereParams);
    $allCartItems = $stmt->fetchAll();

    // Remove cart items whose products have been deleted or deactivated
    $removedIds = [];
    $itens = [];
    foreach ($allCartItems as $item) {
        if (empty($item['product_exists']) || empty($item['name'])
            || (isset($item['product_status']) && (string)$item['product_status'] === '0')) {
            $removedIds[] = $item['cart_id'];
        } else {
            $itens[] = $item;
        }
    }

    // Clean up cart entries for deleted/inactive products
    if (!empty($removedIds)) {
        $placeholders = implode(',', array_fill(0, count($removedIds), '?'));
        $db->prepare("DELETE FROM om_market_cart WHERE cart_id IN ($placeholders)")->execute($removedIds);
    }

    if (empty($itens)) {
        $emptyPayload = ["itens" => [], "subtotal" => 0, "taxa_entrega" => 0, "total" => 0];
        cacheSet($cacheKey, $emptyPayload, 5);
        response(true, $emptyPayload);
    }

    // Calculate subtotal and per-partner fees
    $subtotal = 0;
    $partnerSubtotals = [];
    $partnerFees = [];

    foreach ($itens as $i) {
        $preco = ($i["special_price"] && floatval($i["special_price"]) > 0 && floatval($i["special_price"]) < floatval($i["price"]))
            ? floatval($i["special_price"]) : floatval($i["price"]);
        $itemTotal = $preco * $i["quantity"];
        $subtotal += $itemTotal;

        $pid = $i["partner_id"];
        $partnerSubtotals[$pid] = ($partnerSubtotals[$pid] ?? 0) + $itemTotal;
        if (!isset($partnerFees[$pid])) {
            $fee = floatval($i['delivery_fee'] ?? 0);
            // BoraUm minimum: via OmPricing (fonte unica)
            if (!$i['entrega_propria'] && $fee > 0 && $fee < OmPricing::BORAUM_MINIMO) {
                $fee = OmPricing::BORAUM_MINIMO;
            }
            $isOpen = (int)($i['is_open'] ?? 0) === 1;
            $isPaused = !empty($i['pause_until']) && strtotime($i['pause_until']) > time();
            $partnerFees[$pid] = [
                'nome' => $i['parceiro_nome'],
                'delivery_fee' => $fee,
                'free_delivery_above' => floatval($i['free_delivery_above'] ?? 0),
                'loja_aberta' => $isOpen && !$isPaused,
            ];
        }
    }

    // Calculate total delivery fee
    $taxa_entrega = 0;
    foreach ($partnerFees as $pid => $pf) {
        $fee = $pf['delivery_fee'];
        if ($pf['free_delivery_above'] > 0 && ($partnerSubtotals[$pid] ?? 0) >= $pf['free_delivery_above']) {
            $fee = 0;
        }
        // Multi-stop route: lojas secundarias tem frete zero
        if ($routeMode && $primaryPartnerId && (int)$pid !== $primaryPartnerId) {
            $fee = 0;
        }
        $taxa_entrega += $fee;
    }

    // Build partner info array
    $parceiros = [];
    foreach ($partnerFees as $pid => $pf) {
        $fee = $pf['delivery_fee'];
        $freeAbove = $pf['free_delivery_above'];
        $storeSub = $partnerSubtotals[$pid] ?? 0;
        $isFree = $freeAbove > 0 && $storeSub >= $freeAbove;

        $isRouteStore = $routeMode && $primaryPartnerId && (int)$pid !== $primaryPartnerId;

        // Progress toward free delivery — iFood/DoorDash-style "faltam R$X pro frete grátis"
        $freeDeliveryProgress = null;
        if ($freeAbove > 0 && !$isFree && !$isRouteStore) {
            $remaining = max(0, $freeAbove - $storeSub);
            $pct = $freeAbove > 0 ? min(100, (int)round(($storeSub / $freeAbove) * 100)) : 0;
            $freeDeliveryProgress = [
                'threshold' => round($freeAbove, 2),
                'remaining' => round($remaining, 2),
                'percent' => $pct,
                'message' => $remaining > 0
                    ? "Faltam R$ " . number_format($remaining, 2, ',', '.') . " para frete grátis"
                    : "Frete grátis desbloqueado!",
            ];
        } elseif ($isFree) {
            $freeDeliveryProgress = [
                'threshold' => round($freeAbove, 2),
                'remaining' => 0,
                'percent' => 100,
                'message' => "Frete grátis!",
            ];
        }

        $parceiros[] = [
            'id' => (int)$pid,
            'nome' => $pf['nome'],
            'taxa_entrega' => round($isRouteStore ? 0 : ($isFree ? 0 : $fee), 2),
            'taxa_entrega_base' => round($fee, 2),
            'entrega_gratis_acima' => $freeAbove > 0 ? round($freeAbove, 2) : null,
            'free_delivery_progress' => $freeDeliveryProgress,
            'subtotal' => round($storeSub, 2),
            'route_store' => $isRouteStore,
            'loja_aberta' => $pf['loja_aberta'],
        ];
    }

    // ─── Cupom aplicado (persistido em Redis por cupom.php) ───────────────
    // Antes o listar.php ignorava cupom: o frontend aplicava, o backend
    // calculava o desconto, mas na próxima leitura o total vinha sem ele.
    // Agora o cupom ativo fica em `cart_coupon:c{customer_id}` e é
    // re-validado/aplicado aqui — fonte única de verdade do cart.
    $applied = null;
    $desconto = 0;
    $freeDelivery = false;
    if ($customer_id > 0) {
        $appliedRaw = cacheGet("cart_coupon:c{$customer_id}");
        if (is_array($appliedRaw) && !empty($appliedRaw['code'])) {
            $applied = $appliedRaw;
            // Revalida min_order_value contra subtotal atual — user pode ter
            // removido itens depois de aplicar; nesse caso o cupom deixa de
            // valer mas continua "sticky" até ser revalidado positivamente.
            $minOrder = (float)($applied['min_order_value'] ?? 0);
            $meetsMin = ($minOrder <= 0 || $subtotal >= $minOrder);

            // Revalida partner match pra cupons store-specific
            $partnerOk = true;
            if (!empty($applied['specific_partners'])) {
                $allowed = is_array($applied['specific_partners'])
                    ? $applied['specific_partners']
                    : json_decode($applied['specific_partners'], true);
                if (is_array($allowed) && !empty($allowed)) {
                    $cartPids = array_unique(array_map('intval', array_column($itens, 'partner_id')));
                    $partnerOk = false;
                    foreach ($cartPids as $cpid) {
                        if (in_array($cpid, $allowed)) { $partnerOk = true; break; }
                    }
                }
            }

            if ($meetsMin && $partnerOk) {
                $type = $applied['discount_type'] ?? 'percentage';
                $value = (float)($applied['discount_value'] ?? 0);
                $maxDisc = !empty($applied['max_discount']) ? (float)$applied['max_discount'] : null;
                if ($type === 'percentage' || $type === 'cashback') {
                    $desconto = round($subtotal * ($value / 100), 2);
                    if ($maxDisc && $desconto > $maxDisc) $desconto = $maxDisc;
                } elseif ($type === 'fixed') {
                    $desconto = min($value, $subtotal);
                } elseif ($type === 'free_delivery') {
                    $freeDelivery = true;
                }
            } else {
                // Cupom não se aplica mais — limpa silenciosamente pra não
                // confundir user. Se ele adicionar itens novos, precisa re-aplicar.
                cacheDelete("cart_coupon:c{$customer_id}");
                $applied = null;
            }
        }
    }

    // Free delivery: zera a taxa de entrega antes de somar ao total
    $taxaEntregaFinal = $freeDelivery ? 0 : $taxa_entrega;
    $serviceFee = $subtotal > 0 ? 2.49 : 0;
    $totalFinal = round($subtotal + $taxaEntregaFinal + $serviceFee - $desconto, 2);
    if ($totalFinal < 0) $totalFinal = 0; // safety

    $couponPayload = $applied ? [
        'code' => $applied['code'],
        'coupon_id' => (int)($applied['coupon_id'] ?? 0),
        'tipo' => $applied['discount_type'] ?? 'percentage',
        'descricao' => $applied['descricao'] ?? '',
        'desconto' => round($desconto, 2),
        'free_delivery' => $freeDelivery,
    ] : null;

    $payload = [
        "parceiro" => [
            "id" => $itens[0]["partner_id"],
            "nome" => $itens[0]["parceiro_nome"],
            "loja_aberta" => $partnerFees[$itens[0]["partner_id"]]['loja_aberta'] ?? true
        ],
        "parceiros" => $parceiros,
        "itens" => array_map(function($i) {
            $preco = floatval($i["price"]);
            $promoPreco = ($i["special_price"] && floatval($i["special_price"]) > 0 && floatval($i["special_price"]) < $preco)
                ? floatval($i["special_price"]) : null;
            return [
                "id" => $i["cart_id"],
                "cart_id" => $i["cart_id"],
                "product_id" => $i["product_id"],
                "partner_id" => $i["partner_id"],
                "parceiro_nome" => $i["parceiro_nome"],
                "nome" => $i["name"],
                "imagem" => $i["image"],
                "preco" => $preco,
                "preco_promo" => $promoPreco,
                "quantidade" => (int)$i["quantity"],
                "subtotal" => round(($promoPreco ?? $preco) * $i["quantity"], 2),
                "notas" => $i["notes"]
            ];
        }, $itens),
        "subtotal" => round($subtotal, 2),
        "taxa_entrega" => round($taxaEntregaFinal, 2),
        "taxa_servico" => $serviceFee,
        "desconto" => round($desconto, 2),
        "discount" => round($desconto, 2),
        "coupon" => $couponPayload,
        "total" => $totalFinal,
        "breakdown" => [
            "subtotal" => round($subtotal, 2),
            "delivery_fee" => round($taxaEntregaFinal, 2),
            "service_fee" => $serviceFee,
            "discount" => round($desconto, 2),
            "cashback_applied" => 0,
            "total" => $totalFinal,
        ],
    ];
    cacheSet($cacheKey, $payload, 5);
    response(true, $payload);

} catch (Exception $e) {
    error_log("[carrinho/listar] Erro: " . $e->getMessage());
    response(false, null, 'Erro interno do servidor', 500);
}
