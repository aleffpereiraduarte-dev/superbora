<?php
/**
 * GET /api/mercado/vitrine/smart-promo.php
 * Smart Promotional Engine - Budget-aware promotional offers
 *
 * Checks daily P&L budget and user behavior to decide if we should offer
 * a promotion (coupon/free delivery). NEVER goes negative on daily P&L.
 *
 * Query: ?customer_id=X&partner_id=Y&cart_total=Z&lat=LAT&lng=LNG
 * Returns: promo offer or null
 *
 * Auth: Optional (better promos for logged-in users)
 */
require_once __DIR__ . "/../config/database.php";
setCorsHeaders();
require_once __DIR__ . "/../config/redis.php";
require_once dirname(__DIR__, 3) . '/includes/classes/OmDailyBudget.php';
require_once dirname(__DIR__, 3) . '/includes/classes/OmPricing.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    response(false, null, "Metodo nao permitido", 405);
}

try {
    $db = getDB();

    // ── Auth (optional) ──────────────────────────────────────────────────────
    $customerId = getCustomerIdFromToken();

    // ── Input validation ─────────────────────────────────────────────────────
    $partnerId  = (int)($_GET['partner_id'] ?? 0);
    $cartTotal  = max(0, (float)($_GET['cart_total'] ?? 0));
    $lat        = (float)($_GET['lat'] ?? 0);
    $lng        = (float)($_GET['lng'] ?? 0);

    // ── Budget mode check ────────────────────────────────────────────────────
    $budget = OmDailyBudget::getInstance()->setDb($db);
    $mode   = $budget->getModo();

    // Protecao mode = no promos, ever
    if ($mode === 'protecao') {
        response(true, ['has_promo' => false, 'reason' => 'budget_protection']);
    }

    // ── Redis cooldown: don't spam same customer within 1 hour ───────────────
    $redis = om_redis();
    if ($customerId > 0 && $redis->isAvailable()) {
        $cooldownKey = "smart_promo_{$customerId}";
        $lastPromo = $redis->get($cooldownKey);
        if ($lastPromo) {
            response(true, ['has_promo' => false, 'reason' => 'cooldown']);
        }
    }

    // ── Gather user signals ──────────────────────────────────────────────────
    $signals = [
        'has_cart'           => $cartTotal > 0,
        'cart_above_30'      => $cartTotal >= 30,
        'cart_above_50'      => $cartTotal >= 50,
        'is_authenticated'   => $customerId > 0,
        'is_returning'       => false,
        'has_abandoned_cart'  => false,
        'order_count'        => 0,
    ];

    if ($customerId > 0) {
        // Check order history
        $stmtOrders = dbQuery($db,
            "SELECT COUNT(*) AS cnt FROM om_market_orders WHERE customer_id = ? AND status NOT IN ('cancelado')",
            [$customerId]
        );
        $orderRow = $stmtOrders->fetch();
        $signals['order_count']  = (int)($orderRow['cnt'] ?? 0);
        $signals['is_returning'] = $signals['order_count'] > 0;

        // Check for abandoned carts (orders created but cancelled/expired in last 7 days)
        $stmtAbandoned = dbQuery($db,
            "SELECT COUNT(*) AS cnt FROM om_market_orders
             WHERE customer_id = ? AND status IN ('cancelado', 'expirado')
             AND created_at > NOW() - INTERVAL '7 days'",
            [$customerId]
        );
        $abandonedRow = $stmtAbandoned->fetch();
        $signals['has_abandoned_cart'] = ((int)($abandonedRow['cnt'] ?? 0)) > 0;
    }

    // ── Decision matrix ──────────────────────────────────────────────────────
    $promo = decidePromo($mode, $signals, $cartTotal);

    if (!$promo) {
        response(true, ['has_promo' => false, 'reason' => 'no_applicable_promo']);
    }

    // ── Verify budget can absorb the promo cost ──────────────────────────────
    $promoCost = estimatePromoCost($promo);
    if (!$budget->podeSubsidiar($promoCost)) {
        $budget->registrarSubsidioNegado();
        response(true, ['has_promo' => false, 'reason' => 'budget_insufficient']);
    }

    // ── Create temporary coupon (30 min, single use) ─────────────────────────
    $couponCode = 'SB-SMART-' . strtoupper(bin2hex(random_bytes(4)));
    $minOrder   = $promo['min_order'];

    // Checkout only honors one discount_type per coupon:
    //   'free_delivery' → zeros delivery fee (ignores discount_value)
    //   'fixed'         → subtracts discount_value from subtotal
    // For combo promos (free delivery + fixed discount), we use 'free_delivery'
    // type since that's the higher-value benefit the user sees first.
    // The stated "R$X off" portion is the delivery savings itself.
    if ($promo['free_delivery']) {
        $discountType  = 'free_delivery';
        $discountValue = 0; // checkout ignores this for free_delivery type
    } else {
        $discountType  = 'fixed';
        $discountValue = $promo['discount_value'];
    }

    $description = $promo['title'] . ' (Smart Promo - ' . $mode . ')';

    $stmtInsert = dbQuery($db,
        "INSERT INTO om_market_coupons
            (code, discount_type, discount_value, min_order_value,
             max_uses, max_uses_per_user, valid_from, valid_until, status,
             description, specific_customers, created_at)
         VALUES (?, ?, ?, ?, 1, 1, NOW(), NOW() + INTERVAL '30 minutes', 'active',
                 ?, ?::jsonb, NOW())
         RETURNING id",
        [
            $couponCode,
            $discountType,
            $discountValue,
            $minOrder,
            $description,
            $customerId > 0 ? json_encode([$customerId]) : null,
        ]
    );
    $couponRow = $stmtInsert->fetch();
    $couponId  = (int)($couponRow['id'] ?? 0);

    if (!$couponId) {
        error_log("[SmartPromo] Failed to create coupon for customer={$customerId}");
        response(true, ['has_promo' => false, 'reason' => 'coupon_creation_failed']);
    }

    // ── Set Redis cooldown (1 hour) ──────────────────────────────────────────
    if ($customerId > 0 && $redis->isAvailable()) {
        $redis->set("smart_promo_{$customerId}", json_encode([
            'type'      => $promo['type'],
            'coupon_id' => $couponId,
            'created'   => date('c'),
        ]), 3600);
    }

    // ── Build expiration timestamp ───────────────────────────────────────────
    $expiresAt = date('c', strtotime('+30 minutes'));

    // ── Response ─────────────────────────────────────────────────────────────
    response(true, [
        'has_promo'      => true,
        'type'           => $promo['type'],           // free_delivery|discount|combo
        'title'          => $promo['title'],
        'subtitle'       => $promo['subtitle'],
        'discount_value' => round((float)$promo['discount_value'], 2),
        'free_delivery'  => (bool)$promo['free_delivery'],
        'coupon_code'    => $couponCode,
        'coupon_id'      => $couponId,
        'expires_at'     => $expiresAt,
        'min_order'      => round($minOrder, 2),
        'mode'           => $mode,
    ]);

} catch (Exception $e) {
    error_log("[SmartPromo] Error: " . $e->getMessage());
    response(false, null, "Erro ao verificar promocoes", 500);
}

// ══════════════════════════════════════════════════════════════════════════════
// Helper functions
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Decide which promo to offer based on mode + user signals
 *
 * | Mode        | Cart > R$30            | Cart > R$50              | Cart = 0 (browsing)       |
 * |-------------|------------------------|--------------------------|---------------------------|
 * | Agressivo   | Frete gratis + R$5 off | Frete gratis + R$8 off   | R$5 off (incentive)       |
 * | Normal      | Frete gratis           | R$5 off                  | R$3 off on orders > R$40  |
 * | Conservador | R$2 off on > R$50      | R$3 off on > R$60        | Nothing                   |
 * | Protecao    | Nothing                | Nothing                  | Nothing                   |
 */
function decidePromo(string $mode, array $signals, float $cartTotal): ?array {

    // ── Messages pool (Portuguese) ───────────────────────────────────────────
    $msgs = [
        'free_delivery' => [
            ['Frete gratis neste pedido!', 'Entrega por nossa conta!'],
            ['Entrega gratis so pra voce!', 'Aproveite, frete R$0!'],
        ],
        'discount' => [
            ['Economize agora!', 'Desconto exclusivo pra voce!'],
            ['Oferta especial!', 'So pra voce, por tempo limitado!'],
        ],
        'combo' => [
            ['Frete gratis + desconto!', 'Oferta especial pra voce!'],
            ['Super oferta! Frete + desconto!', 'Aproveite antes que expire!'],
        ],
    ];

    $pick = function(string $type) use ($msgs) {
        $set = $msgs[$type][array_rand($msgs[$type])];
        return ['title' => $set[0], 'subtitle' => $set[1]];
    };

    // ── Agressivo ────────────────────────────────────────────────────────────
    if ($mode === 'agressivo') {
        if ($signals['cart_above_50']) {
            $m = $pick('combo');
            return [
                'type'           => 'combo',
                'title'          => "Frete gratis + R\$8 off!",
                'subtitle'       => "Oferta especial pra voce!",
                'discount_value' => 8.00,
                'free_delivery'  => true,
                'min_order'      => 50.00,
            ];
        }
        if ($signals['cart_above_30']) {
            return [
                'type'           => 'combo',
                'title'          => "Frete gratis + R\$5 off!",
                'subtitle'       => "Aproveite antes que expire!",
                'discount_value' => 5.00,
                'free_delivery'  => true,
                'min_order'      => 30.00,
            ];
        }
        // Browsing (no cart)
        $m = $pick('discount');
        return [
            'type'           => 'discount',
            'title'          => "R\$5 de desconto so pra voce!",
            'subtitle'       => $m['subtitle'],
            'discount_value' => 5.00,
            'free_delivery'  => false,
            'min_order'      => 30.00,
        ];
    }

    // ── Normal ───────────────────────────────────────────────────────────────
    if ($mode === 'normal') {
        if ($signals['cart_above_50']) {
            $m = $pick('discount');
            return [
                'type'           => 'discount',
                'title'          => "R\$5 de desconto so pra voce!",
                'subtitle'       => $m['subtitle'],
                'discount_value' => 5.00,
                'free_delivery'  => false,
                'min_order'      => 50.00,
            ];
        }
        if ($signals['cart_above_30']) {
            $m = $pick('free_delivery');
            return [
                'type'           => 'free_delivery',
                'title'          => "Frete gratis neste pedido!",
                'subtitle'       => $m['subtitle'],
                'discount_value' => 0,
                'free_delivery'  => true,
                'min_order'      => 30.00,
            ];
        }
        // Browsing — only offer if authenticated (we know them)
        if ($signals['is_authenticated']) {
            return [
                'type'           => 'discount',
                'title'          => "R\$3 de desconto pra voce!",
                'subtitle'       => "Em pedidos acima de R\$40",
                'discount_value' => 3.00,
                'free_delivery'  => false,
                'min_order'      => 40.00,
            ];
        }
        return null;
    }

    // ── Conservador ──────────────────────────────────────────────────────────
    if ($mode === 'conservador') {
        if ($signals['cart_above_50']) {
            // Only returning customers or abandoned cart get the nudge
            if ($signals['is_returning'] || $signals['has_abandoned_cart']) {
                return [
                    'type'           => 'discount',
                    'title'          => "R\$3 de desconto pra voce!",
                    'subtitle'       => "Em pedidos acima de R\$60",
                    'discount_value' => 3.00,
                    'free_delivery'  => false,
                    'min_order'      => 60.00,
                ];
            }
            return [
                'type'           => 'discount',
                'title'          => "R\$2 de desconto pra voce!",
                'subtitle'       => "Em pedidos acima de R\$50",
                'discount_value' => 2.00,
                'free_delivery'  => false,
                'min_order'      => 50.00,
            ];
        }
        // Conservador: nothing for carts < R$50 or browsing
        return null;
    }

    return null;
}

/**
 * Estimate total promo cost for budget check.
 * Free delivery costs approximately R$8-12 on average; we use R$10 as estimate.
 */
function estimatePromoCost(array $promo): float {
    $cost = (float)$promo['discount_value'];

    if ($promo['free_delivery']) {
        // Average delivery cost subsidy
        $cost += 10.00;
    }

    return $cost;
}
