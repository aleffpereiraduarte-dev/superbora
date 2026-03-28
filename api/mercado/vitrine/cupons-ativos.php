<?php
/**
 * GET /api/mercado/vitrine/cupons-ativos.php
 * Returns active coupons visible to customers
 */
require_once __DIR__ . "/../config/database.php";

setCorsHeaders();
header('Cache-Control: public, max-age=300');

try {
    $db = getDB();
    $customerId = null;

    // Optional auth — shows personalized coupons if logged in
    try {
        require_once dirname(__DIR__, 3) . '/includes/classes/OmAuth.php';
        OmAuth::getInstance()->setDb($db);
        $token = om_auth()->getTokenFromRequest();
        if ($token) {
            $payload = om_auth()->validateToken($token);
            if ($payload && $payload['type'] === 'customer') {
                $customerId = (int)$payload['uid'];
            }
        }
    } catch (\Throwable $e) {}

    $limit = min(20, max(1, (int)($_GET['limit'] ?? 10)));

    // Get public coupons (not customer-specific, not expired, not maxed out)
    $sql = "
        SELECT id, code, name, description, discount_type, discount_value, max_discount,
               min_order_value, first_order_only, valid_until,
               specific_partners, specific_categories
        FROM om_market_coupons
        WHERE status IN ('1', 'active')
          AND (valid_until IS NULL OR valid_until > NOW())
          AND (max_uses IS NULL OR max_uses = 0 OR current_uses < max_uses)
          AND (specific_customers IS NULL OR specific_customers = '[]' OR specific_customers = 'null')
        ORDER BY
            CASE discount_type
                WHEN 'free_delivery' THEN 0
                WHEN 'percentage' THEN 1
                ELSE 2
            END,
            discount_value DESC
        LIMIT ?
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([$limit]);
    $coupons = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format for frontend
    $result = [];
    $gradients = [
        ['#00A868', '#059669'],
        ['#f59e0b', '#d97706'],
        ['#3b82f6', '#1d4ed8'],
        ['#8b5cf6', '#6d28d9'],
        ['#ef4444', '#dc2626'],
    ];

    foreach ($coupons as $i => $c) {
        $colors = $gradients[$i % count($gradients)];

        $title = '';
        $subtitle = $c['description'] ?: $c['name'] ?: '';

        if ($c['discount_type'] === 'free_delivery') {
            $title = 'FRETE GRÁTIS';
            if (!$subtitle) $subtitle = 'Em pedidos acima de R$ ' . number_format($c['min_order_value'], 0, ',', '.');
        } elseif ($c['discount_type'] === 'percentage') {
            $title = (int)$c['discount_value'] . '% OFF';
            if (!$subtitle) $subtitle = 'Desconto de ' . (int)$c['discount_value'] . '%';
        } else {
            $title = 'R$' . number_format($c['discount_value'], 0, ',', '.') . ' OFF';
            if (!$subtitle) $subtitle = 'R$ ' . number_format($c['discount_value'], 2, ',', '.') . ' de desconto';
        }

        if ($c['min_order_value'] > 0 && strpos($subtitle, 'acima') === false) {
            $subtitle .= ' • Mín R$ ' . number_format($c['min_order_value'], 0, ',', '.');
        }

        $result[] = [
            'id' => (int)$c['id'],
            'code' => $c['code'],
            'title' => $title,
            'subtitle' => $subtitle,
            'discount_type' => $c['discount_type'],
            'discount_value' => (float)$c['discount_value'],
            'min_order' => (float)$c['min_order_value'],
            'first_order_only' => (bool)$c['first_order_only'],
            'valid_until' => $c['valid_until'],
            'colors' => $colors,
        ];
    }

    response(true, $result);

} catch (Exception $e) {
    error_log("[cupons-ativos] Error: " . $e->getMessage());
    response(false, null, "Erro ao carregar cupons", 500);
}
