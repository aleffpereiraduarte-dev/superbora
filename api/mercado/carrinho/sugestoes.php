<?php
/**
 * POST /api/mercado/carrinho/sugestoes.php
 * AI-powered cart suggestions using Claude.
 *
 * Input:  { items: [{product_id, name, category?}], partner_id, subtotal }
 * Output: { suggestions: [{product_id, name, image, price, sale_price}], savings_tip, coupon_hint, coupon_code }
 *
 * Falls back to top-N by popularity if Claude is unavailable.
 * Redis cached 5min keyed on cart contents + partner.
 */
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../helpers/claude-client.php";
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { response(false, null, 'Method not allowed', 405); }

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $items = $input['items'] ?? [];
    $partnerId = (int)($input['partner_id'] ?? 0);
    $subtotal = floatval($input['subtotal'] ?? 0);

    if (empty($items) || $partnerId <= 0) {
        response(false, null, 'items and partner_id required', 400);
    }

    $db = getDB();

    // Auth (optional but needed for coupon filtering)
    $customerId = null;
    try {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(\S+)/', $authHeader, $m)) {
            require_once dirname(__DIR__, 3) . '/includes/classes/OmAuth.php';
            $auth = OmAuth::getInstance();
            $auth->setDb($db);
            $payload = $auth->validateToken($m[1]);
            if ($payload && ($payload['type'] ?? '') === 'customer') {
                $customerId = intval($payload['sub'] ?? $payload['uid'] ?? 0);
            }
        }
    } catch (Exception $e) { /* anonymous */ }

    // Cache key based on cart contents + partner
    $cartProductIds = array_map(fn($i) => (int)($i['product_id'] ?? 0), $items);
    sort($cartProductIds);
    $cacheKey = "cart_suggest:" . md5($partnerId . ':' . implode(',', $cartProductIds));

    // Try Redis cache
    $redis = null;
    try {
        $redis = new Redis();
        $redis->connect('127.0.0.1', 6379);
        $cached = $redis->get($cacheKey);
        if ($cached) {
            $data = json_decode($cached, true);
            if ($data) {
                response(true, array_merge($data, ['source' => 'cache']));
            }
        }
    } catch (Exception $e) { /* no redis */ }

    // 1. Get partner free delivery threshold
    $stmt = $db->prepare("SELECT free_delivery_above, delivery_fee, taxa_entrega FROM om_market_partners WHERE partner_id = ?");
    $stmt->execute([$partnerId]);
    $partner = $stmt->fetch(PDO::FETCH_ASSOC);
    $freeDeliveryThreshold = floatval($partner['free_delivery_above'] ?? 0);
    $deliveryFee = floatval($partner['delivery_fee'] ?? $partner['taxa_entrega'] ?? 0);

    // 2. Find available coupons customer hasn't used
    $couponHint = null;
    $couponCode = null;
    try {
        $couponSql = "
            SELECT c.code, c.discount_type, c.discount_value, c.max_discount, c.min_order_value
            FROM om_market_coupons c
            WHERE c.status = 'active'
              AND (c.valid_from IS NULL OR c.valid_from <= NOW())
              AND (c.valid_until IS NULL OR c.valid_until >= NOW())
              AND (c.max_uses = 0 OR c.current_uses < c.max_uses)
              AND (c.specific_partners IS NULL OR c.specific_partners::text = '[]' OR c.specific_partners::jsonb @> ?::jsonb)
        ";
        $params = [json_encode([$partnerId])];

        if ($customerId) {
            $couponSql .= " AND NOT EXISTS (
                SELECT 1 FROM om_market_coupon_usage cu WHERE cu.coupon_id = c.id AND cu.customer_id = ?
            )";
            $params[] = $customerId;
        }

        $couponSql .= " ORDER BY c.discount_value DESC LIMIT 3";
        $stmt = $db->prepare($couponSql);
        $stmt->execute($params);
        $coupons = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($coupons)) {
            $best = $coupons[0];
            $couponCode = $best['code'];
            if ($best['discount_type'] === 'percentage') {
                $maxDisc = $best['max_discount'] ? " (max R\$" . number_format($best['max_discount'], 2, ',', '.') . ")" : "";
                $couponHint = "Use o cupom {$best['code']} e ganhe {$best['discount_value']}% de desconto{$maxDisc}";
            } elseif ($best['discount_type'] === 'fixed') {
                $couponHint = "Use o cupom {$best['code']} e ganhe R\$" . number_format($best['discount_value'], 2, ',', '.') . " de desconto";
            } elseif ($best['discount_type'] === 'free_delivery') {
                $couponHint = "Use o cupom {$best['code']} para frete gratis!";
            } elseif ($best['discount_type'] === 'cashback') {
                $couponHint = "Use o cupom {$best['code']} e ganhe {$best['discount_value']}% de cashback";
            }
        }
    } catch (Exception $e) {
        error_log("[carrinho/sugestoes] Coupon query error: " . $e->getMessage());
    }

    // 3. Savings tip (free delivery)
    $savingsTip = null;
    if ($freeDeliveryThreshold > 0 && $subtotal < $freeDeliveryThreshold && $deliveryFee > 0) {
        $remaining = $freeDeliveryThreshold - $subtotal;
        $savingsTip = "Adicione R\$" . number_format($remaining, 2, ',', '.') . " para frete gratis!";
    }

    // 4. Fetch popular products from this partner NOT in cart
    $placeholders = implode(',', array_fill(0, count($cartProductIds), '?'));
    $stmt = $db->prepare("
        SELECT p.product_id, p.name, p.price, p.special_price as sale_price, p.image, p.unit,
               p.category_id, c.name as category_name,
               COALESCE(oc.cnt, 0) as sales_count
        FROM om_market_products p
        LEFT JOIN om_market_categories c ON c.category_id = p.category_id
        LEFT JOIN (
            SELECT oi.product_id, COUNT(*) as cnt
            FROM om_market_order_items oi
            JOIN om_market_orders o ON o.order_id = oi.order_id
            WHERE o.date_added > NOW() - INTERVAL '90 days'
            GROUP BY oi.product_id
        ) oc ON oc.product_id = p.product_id
        WHERE p.partner_id = ?
          AND p.status = 1
          AND p.product_id NOT IN ($placeholders)
          AND p.name IS NOT NULL AND TRIM(p.name) != ''
          AND p.price > 0
          AND p.image IS NOT NULL AND TRIM(p.image) != ''
        ORDER BY oc.cnt DESC NULLS LAST
        LIMIT 20
    ");
    $params = array_merge([$partnerId], $cartProductIds);
    $stmt->execute($params);
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($candidates)) {
        $result = ['suggestions' => [], 'savings_tip' => $savingsTip, 'coupon_hint' => $couponHint, 'coupon_code' => $couponCode];
        cacheResult($redis, $cacheKey, $result);
        response(true, $result);
    }

    // 5. Ask Claude to pick complementary items
    $suggestions = claudeSuggest($items, $candidates);

    // Fallback: top 6 by popularity
    if (empty($suggestions)) {
        $suggestions = array_slice(array_map(fn($p) => [
            'product_id' => $p['product_id'],
            'name' => $p['name'],
            'image' => $p['image'],
            'price' => floatval($p['price']),
            'sale_price' => $p['sale_price'] ? floatval($p['sale_price']) : null,
        ], $candidates), 0, 6);
    }

    $result = [
        'suggestions' => $suggestions,
        'savings_tip' => $savingsTip,
        'coupon_hint' => $couponHint,
        'coupon_code' => $couponCode,
    ];

    cacheResult($redis, $cacheKey, $result);
    response(true, $result);

} catch (Exception $e) {
    error_log("[carrinho/sugestoes] Error: " . $e->getMessage());
    response(true, ['suggestions' => [], 'savings_tip' => null, 'coupon_hint' => null, 'coupon_code' => null]);
}

function claudeSuggest(array $cartItems, array $candidates): array {
    try {
        $claude = new ClaudeClient('claude-haiku-4-5-20251001', 10, 0);

        $cartNames = array_map(fn($i) => $i['name'] ?? 'item', $cartItems);
        $candidateList = [];
        foreach ($candidates as $i => $p) {
            $candidateList[] = [
                'i' => $i,
                'n' => $p['name'],
                'p' => floatval($p['price']),
                'cat' => $p['category_name'] ?? '',
            ];
        }

        $system = "Voce e assistente de compras do SuperBora Mercado. Responda APENAS com JSON array de indices.";
        $userMsg = "O carrinho tem: " . implode(', ', $cartNames) . ".\n\n"
            . "Sugira 4-6 itens complementares desta lista. Priorize: 1) Itens que combinam (ex: pao→manteiga) "
            . "2) Variedade de categorias 3) Bom custo-beneficio.\n\n"
            . "Retorne JSON array com indices (campo 'i'). Ex: [0,3,7,12]\n\n"
            . "Produtos disponiveis: " . json_encode($candidateList, JSON_UNESCAPED_UNICODE);

        $result = $claude->send($system, [['role' => 'user', 'content' => $userMsg]], 256);

        if ($result['success'] && !empty($result['text'])) {
            $indices = ClaudeClient::parseJson($result['text']);
            if (is_array($indices) && count($indices) >= 2) {
                $suggestions = [];
                foreach ($indices as $idx) {
                    $idx = intval($idx);
                    if (isset($candidates[$idx])) {
                        $p = $candidates[$idx];
                        $suggestions[] = [
                            'product_id' => $p['product_id'],
                            'name' => $p['name'],
                            'image' => $p['image'],
                            'price' => floatval($p['price']),
                            'sale_price' => $p['sale_price'] ? floatval($p['sale_price']) : null,
                        ];
                    }
                }
                if (count($suggestions) >= 2) return $suggestions;
            }
        }
    } catch (Exception $e) {
        error_log("[carrinho/sugestoes] Claude error: " . $e->getMessage());
    }
    return [];
}

function cacheResult($redis, string $key, array $data): void {
    try {
        if ($redis) {
            $redis->setex($key, 300, json_encode($data));
        }
    } catch (Exception $e) { /* skip */ }
}
