<?php
/**
 * GET /api/mercado/customer/ai-coupons.php
 * Generate personalized coupon suggestions using Claude AI
 * Based on customer purchase history, frequency, and business profit margins
 *
 * Returns: { success: true, data: { coupons: [...], message: "..." } }
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 2) . "/cache/CacheHelper.php";
require_once __DIR__ . "/../helpers/claude-client.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Autenticacao necessaria", 401);
    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== 'customer') response(false, null, "Token invalido", 401);
    $customerId = (int)$payload['uid'];

    // Cache per customer — refresh every 6 hours
    $cacheKey = "ai_coupons_customer_{$customerId}";
    $cached = CacheHelper::get($cacheKey);
    if ($cached) {
        response(true, $cached);
    }

    // 1. Fetch customer profile
    $stmtCustomer = $db->prepare("
        SELECT customer_id, name, email, created_at
        FROM om_customers WHERE customer_id = ?
    ");
    $stmtCustomer->execute([$customerId]);
    $customer = $stmtCustomer->fetch(PDO::FETCH_ASSOC);
    if (!$customer) response(false, null, "Cliente nao encontrado", 404);

    // 2. Fetch purchase history (last 90 days)
    $stmtOrders = $db->prepare("
        SELECT o.order_id, o.total, o.status, o.created_at, o.partner_id,
               p.name as partner_name, p.categoria as partner_category
        FROM om_market_orders o
        LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
        WHERE o.customer_id = ? AND o.created_at >= NOW() - INTERVAL '90 days'
        ORDER BY o.created_at DESC
        LIMIT 50
    ");
    $stmtOrders->execute([$customerId]);
    $orders = $stmtOrders->fetchAll(PDO::FETCH_ASSOC);

    // 3. Fetch most purchased products
    $stmtTopProducts = $db->prepare("
        SELECT oi.product_name, SUM(oi.quantity) as total_qty, AVG(oi.price) as avg_price,
               COUNT(DISTINCT oi.order_id) as order_count
        FROM om_market_order_items oi
        INNER JOIN om_market_orders o ON oi.order_id = o.order_id
        WHERE o.customer_id = ? AND o.created_at >= NOW() - INTERVAL '90 days'
        GROUP BY oi.product_name
        ORDER BY total_qty DESC
        LIMIT 10
    ");
    $stmtTopProducts->execute([$customerId]);
    $topProducts = $stmtTopProducts->fetchAll(PDO::FETCH_ASSOC);

    // 4. Fetch existing active coupons available to this customer
    $stmtExistingCoupons = $db->prepare("
        SELECT code, discount_type, discount_value, min_order_value, valid_until
        FROM om_market_coupons
        WHERE status = 'active' AND (valid_until IS NULL OR valid_until > NOW())
        AND (specific_customers IS NULL OR specific_customers::text LIKE ?)
        ORDER BY created_at DESC
        LIMIT 10
    ");
    $stmtExistingCoupons->execute(['%' . $customerId . '%']);
    $existingCoupons = $stmtExistingCoupons->fetchAll(PDO::FETCH_ASSOC);

    // 5. Calculate customer metrics
    $totalOrders = count($orders);
    $totalSpent = array_sum(array_column($orders, 'total'));
    $avgOrderValue = $totalOrders > 0 ? round($totalSpent / $totalOrders, 2) : 0;
    $daysSinceLastOrder = 999;
    if ($totalOrders > 0) {
        $lastDate = new DateTime($orders[0]['created_at']);
        $now = new DateTime();
        $daysSinceLastOrder = $now->diff($lastDate)->days;
    }

    // Frequency: orders per week
    $ordersPerWeek = $totalOrders > 0 ? round($totalOrders / 13, 2) : 0; // 90 days ~ 13 weeks

    // 6. Determine customer segment
    $segment = 'new';
    if ($totalOrders >= 10 && $avgOrderValue >= 80) $segment = 'vip';
    elseif ($totalOrders >= 5) $segment = 'regular';
    elseif ($totalOrders >= 1) $segment = 'returning';
    elseif ($daysSinceLastOrder > 30 && $totalOrders > 0) $segment = 'churning';

    // 7. Build context for Claude
    $customerContext = [
        'segment' => $segment,
        'total_orders_90d' => $totalOrders,
        'total_spent_90d' => round($totalSpent, 2),
        'avg_order_value' => $avgOrderValue,
        'days_since_last_order' => $daysSinceLastOrder,
        'orders_per_week' => $ordersPerWeek,
        'member_since' => $customer['created_at'],
        'top_products' => array_map(function($p) {
            return $p['product_name'] . ' (x' . $p['total_qty'] . ', avg R$' . round($p['avg_price'], 2) . ')';
        }, $topProducts),
        'favorite_stores' => array_unique(array_filter(array_column($orders, 'partner_name'))),
        'existing_coupons' => array_map(function($c) {
            return $c['code'] . ' (' . $c['discount_type'] . ': ' . $c['discount_value'] . ')';
        }, $existingCoupons),
    ];

    // 8. Call Claude AI
    $claude = new ClaudeClient('claude-sonnet-4-20250514', 30);

    $systemPrompt = <<<PROMPT
Voce e um especialista em marketing de supermercado e delivery (SuperBora).
Sua funcao e gerar cupons personalizados para clientes baseado no historico de compras.

REGRAS IMPORTANTES:
- Gere exatamente 3-5 cupons personalizados
- Descontos entre 5% e 25% (nunca mais que 25%)
- Valor fixo maximo de R$15 de desconto
- Frete gratis so para pedidos acima de R$50
- Cashback maximo de 10%
- Cada cupom deve ter um motivo claro (recompensa por fidelidade, incentivo a voltar, experimentar nova categoria, etc)
- Codigos devem ser unicos, curtos (6-8 chars), em MAIUSCULA
- Pedido minimo deve ser razoavel (R$25-R$100)
- Validade entre 3 e 14 dias

FORMATO DE RESPOSTA (JSON puro, sem markdown):
{
  "coupons": [
    {
      "code": "VOLTA10",
      "title": "Sentimos sua falta!",
      "description": "10% de desconto no proximo pedido",
      "discount_type": "percentage",
      "discount_value": 10,
      "min_order_value": 30,
      "max_discount": 15,
      "valid_days": 7,
      "reason": "Cliente inativo ha X dias",
      "emoji": "emoji adequado",
      "color": "cor hex adequada",
      "priority": 1
    }
  ],
  "greeting": "Mensagem curta e amigavel para o cliente"
}
PROMPT;

    $userMessage = "Dados do cliente:\n" . json_encode($customerContext, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    $result = $claude->send($systemPrompt, [
        ['role' => 'user', 'content' => $userMessage]
    ], 2048);

    if (!$result['success']) {
        // Fallback: return generic coupons based on segment
        $fallbackCoupons = generateFallbackCoupons($segment, $avgOrderValue, $daysSinceLastOrder);
        $responseData = [
            'coupons' => $fallbackCoupons,
            'greeting' => getGreeting($segment, $customer['name']),
            'ai_generated' => false,
        ];
        CacheHelper::set($cacheKey, $responseData, 21600);
        response(true, $responseData);
    }

    $parsed = ClaudeClient::parseJson($result['text']);

    if (!$parsed || empty($parsed['coupons'])) {
        $fallbackCoupons = generateFallbackCoupons($segment, $avgOrderValue, $daysSinceLastOrder);
        $responseData = [
            'coupons' => $fallbackCoupons,
            'greeting' => getGreeting($segment, $customer['name']),
            'ai_generated' => false,
        ];
        CacheHelper::set($cacheKey, $responseData, 21600);
        response(true, $responseData);
    }

    // 9. Validate and sanitize AI-generated coupons
    $coupons = [];
    foreach ($parsed['coupons'] as $c) {
        $code = strtoupper(preg_replace('/[^A-Z0-9]/', '', substr($c['code'] ?? '', 0, 10)));
        if (strlen($code) < 4) continue;

        // Cap discount values for safety
        $discountValue = min((float)($c['discount_value'] ?? 0), 25);
        $maxDiscount = min((float)($c['max_discount'] ?? 15), 20);
        $minOrder = max((float)($c['min_order_value'] ?? 25), 15);
        $validDays = min(max((int)($c['valid_days'] ?? 7), 1), 30);

        $coupons[] = [
            'code' => $code,
            'title' => substr($c['title'] ?? '', 0, 100),
            'description' => substr($c['description'] ?? '', 0, 200),
            'discount_type' => in_array($c['discount_type'] ?? '', ['percentage', 'fixed', 'free_delivery', 'cashback'])
                ? $c['discount_type'] : 'percentage',
            'discount_value' => $discountValue,
            'min_order_value' => $minOrder,
            'max_discount' => $maxDiscount,
            'valid_days' => $validDays,
            'valid_until' => date('Y-m-d', strtotime("+{$validDays} days")),
            'reason' => substr($c['reason'] ?? '', 0, 200),
            'emoji' => substr($c['emoji'] ?? '🎫', 0, 8),
            'color' => preg_match('/^#[0-9a-fA-F]{6}$/', $c['color'] ?? '') ? $c['color'] : '#00A868',
            'priority' => (int)($c['priority'] ?? 5),
        ];
    }

    // Sort by priority
    usort($coupons, function($a, $b) { return $a['priority'] - $b['priority']; });

    // 10. Ensure coupons exist in database (create if needed)
    foreach ($coupons as &$coupon) {
        $stmtCheck = $db->prepare("SELECT id FROM om_market_coupons WHERE code = ?");
        $stmtCheck->execute([$coupon['code']]);
        $existingId = $stmtCheck->fetchColumn();

        if (!$existingId) {
            $stmtInsert = $db->prepare("
                INSERT INTO om_market_coupons
                    (code, discount_type, discount_value, min_order_value, max_discount,
                     max_uses, max_uses_per_user, valid_from, valid_until, status,
                     description, specific_customers, created_at)
                VALUES (?, ?, ?, ?, ?, 1, 1, NOW(), ?, 'active', ?, ?::jsonb, NOW())
                RETURNING id
            ");
            $stmtInsert->execute([
                $coupon['code'],
                $coupon['discount_type'],
                $coupon['discount_value'],
                $coupon['min_order_value'],
                $coupon['max_discount'],
                $coupon['valid_until'],
                $coupon['title'] . ' - ' . $coupon['description'],
                json_encode([$customerId]),
            ]);
            $row = $stmtInsert->fetch(PDO::FETCH_ASSOC);
            $coupon['coupon_id'] = (int)($row['id'] ?? 0);
        } else {
            $coupon['coupon_id'] = (int)$existingId;
        }
    }
    unset($coupon);

    $responseData = [
        'coupons' => $coupons,
        'greeting' => $parsed['greeting'] ?? getGreeting($segment, $customer['name']),
        'segment' => $segment,
        'ai_generated' => true,
    ];

    CacheHelper::set($cacheKey, $responseData, 21600); // 6 hours
    response(true, $responseData);

} catch (Exception $e) {
    error_log("[ai-coupons] Erro: " . $e->getMessage());
    response(false, null, "Erro ao gerar cupons", 500);
}

// ── Fallback coupon generator (no AI) ──
function generateFallbackCoupons(string $segment, float $avgOrder, int $daysSince): array {
    $coupons = [];
    $baseCode = strtoupper(substr(md5(time() . $segment), 0, 6));

    if ($segment === 'churning' || $daysSince > 14) {
        $coupons[] = [
            'code' => 'VOLTA' . substr($baseCode, 0, 4),
            'title' => 'Sentimos sua falta!',
            'description' => '15% de desconto para voce voltar',
            'discount_type' => 'percentage',
            'discount_value' => 15,
            'min_order_value' => 30,
            'max_discount' => 15,
            'valid_days' => 7,
            'valid_until' => date('Y-m-d', strtotime('+7 days')),
            'emoji' => "\u{1F49A}",
            'color' => '#00A868',
            'priority' => 1,
        ];
    }

    if ($segment === 'vip') {
        $coupons[] = [
            'code' => 'VIP' . substr($baseCode, 0, 5),
            'title' => 'Exclusivo para VIP',
            'description' => 'R$10 de desconto por ser cliente especial',
            'discount_type' => 'fixed',
            'discount_value' => 10,
            'min_order_value' => 50,
            'max_discount' => 10,
            'valid_days' => 14,
            'valid_until' => date('Y-m-d', strtotime('+14 days')),
            'emoji' => "\u{1F451}",
            'color' => '#f59e0b',
            'priority' => 1,
        ];
    }

    $coupons[] = [
        'code' => 'SUPER' . substr($baseCode, 0, 4),
        'title' => 'Desconto especial',
        'description' => '10% no proximo pedido',
        'discount_type' => 'percentage',
        'discount_value' => 10,
        'min_order_value' => 25,
        'max_discount' => 12,
        'valid_days' => 7,
        'valid_until' => date('Y-m-d', strtotime('+7 days')),
        'emoji' => "\u{1F381}",
        'color' => '#8b5cf6',
        'priority' => 2,
    ];

    $coupons[] = [
        'code' => 'FRETE' . substr($baseCode, 0, 4),
        'title' => 'Entrega gratis',
        'description' => 'Frete gratis em pedidos acima de R$50',
        'discount_type' => 'free_delivery',
        'discount_value' => 0,
        'min_order_value' => 50,
        'max_discount' => 0,
        'valid_days' => 5,
        'valid_until' => date('Y-m-d', strtotime('+5 days')),
        'emoji' => "\u{1F69A}",
        'color' => '#0284c7',
        'priority' => 3,
    ];

    return $coupons;
}

function getGreeting(string $segment, string $name): string {
    $firstName = explode(' ', trim($name))[0] ?? 'Cliente';
    switch ($segment) {
        case 'vip': return "Ola {$firstName}! Como cliente VIP, preparamos ofertas exclusivas para voce.";
        case 'regular': return "Ola {$firstName}! Temos cupons especiais baseados nas suas compras.";
        case 'churning': return "Ola {$firstName}! Sentimos sua falta! Veja o que preparamos para voce.";
        case 'returning': return "Ola {$firstName}! Obrigado por voltar. Confira seus cupons exclusivos.";
        default: return "Ola {$firstName}! Confira seus cupons personalizados.";
    }
}
