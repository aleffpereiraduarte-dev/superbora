<?php
/**
 * GET /api/mercado/partner/price-adjust-preview.php
 *
 * Preview de ajuste de preco em lote por categoria.
 *
 * Query: ?category_id=X&adjust_type=percent|fixed&adjust_value=10
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $payload = requirePartnerAuth();
    $partner_id = (int)$payload['uid'];

    $category_id = (int)($_GET['category_id'] ?? 0);
    $adjust_type = $_GET['adjust_type'] ?? 'percent'; // percent or fixed
    $adjust_value = (float)($_GET['adjust_value'] ?? 0);

    if (!$category_id) response(false, null, "category_id obrigatorio", 400);
    if ($adjust_value == 0) response(false, null, "adjust_value obrigatorio", 400);
    if (!in_array($adjust_type, ['percent', 'fixed'])) $adjust_type = 'percent';

    // Get products in category
    $stmt = $db->prepare("
        SELECT product_id, name, price, special_price
        FROM om_market_products
        WHERE partner_id = ? AND category_id = ? AND status != 'deleted'
        ORDER BY name ASC
    ");
    $stmt->execute([$partner_id, $category_id]);
    $products = $stmt->fetchAll();

    $preview = [];
    foreach ($products as $p) {
        $oldPrice = (float)$p['price'];
        if ($adjust_type === 'percent') {
            $newPrice = round($oldPrice * (1 + $adjust_value / 100), 2);
        } else {
            $newPrice = round($oldPrice + $adjust_value, 2);
        }
        $newPrice = max(0.01, $newPrice); // Never negative

        $preview[] = [
            'product_id' => (int)$p['product_id'],
            'name' => $p['name'],
            'old_price' => $oldPrice,
            'new_price' => $newPrice,
            'difference' => round($newPrice - $oldPrice, 2),
        ];
    }

    response(true, [
        'products' => $preview,
        'total_products' => count($preview),
        'adjust_type' => $adjust_type,
        'adjust_value' => $adjust_value,
    ], "Preview do ajuste");

} catch (Exception $e) {
    error_log("[partner/price-adjust-preview] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
