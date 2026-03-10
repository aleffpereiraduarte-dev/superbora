<?php
/**
 * GET /api/mercado/produtos/detalhe.php?id=123
 * Returns full product details with partner info and option groups
 */
require_once __DIR__ . "/../config/database.php";

header('Cache-Control: public, max-age=120');

try {
    $id = (int)($_GET['id'] ?? $_GET['product_id'] ?? 0);
    if (!$id) {
        response(false, null, "id obrigatorio", 400);
    }

    $db = getDB();

    // Product + partner info
    $stmt = $db->prepare("
        SELECT p.id, p.name, p.description, p.price, p.special_price,
               p.special_start, p.special_end, p.image, p.images,
               p.partner_id, p.category_id, p.quantity, p.in_stock,
               p.unit, p.weight, p.brand, p.is_organic, p.is_fresh,
               p.is_frozen, p.is_featured, p.dietary_tags, p.allergens,
               p.min_quantity, p.max_quantity, p.step_quantity, p.is_combo,
               pa.nome as partner_name, pa.status as partner_status,
               c.name as category_name
        FROM om_market_products p
        LEFT JOIN om_market_partners pa ON pa.partner_id = p.partner_id
        LEFT JOIN om_market_categories c ON c.category_id = p.category_id
        WHERE p.id = ? AND p.status::text = '1'
    ");
    $stmt->execute([$id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    // Fallback: try product_id if not found by p.id
    if (!$product) {
        $stmt2 = $db->prepare("
            SELECT p.id, p.name, p.description, p.price, p.special_price,
                   p.special_start, p.special_end, p.image, p.images,
                   p.partner_id, p.category_id, p.quantity, p.in_stock,
                   p.unit, p.weight, p.brand, p.is_organic, p.is_fresh,
                   p.is_frozen, p.is_featured, p.dietary_tags, p.allergens,
                   p.min_quantity, p.max_quantity, p.step_quantity, p.is_combo,
                   pa.nome as partner_name, pa.status as partner_status,
                   c.name as category_name
            FROM om_market_products p
            LEFT JOIN om_market_partners pa ON pa.partner_id = p.partner_id
            LEFT JOIN om_market_categories c ON c.category_id = p.category_id
            WHERE p.product_id = ? AND p.status::text = '1'
        ");
        $stmt2->execute([$id]);
        $product = $stmt2->fetch(PDO::FETCH_ASSOC);
    }

    if (!$product) {
        response(false, null, "Produto nao encontrado", 404);
    }

    // Check if partner is active
    if ($product['partner_status'] !== '1') {
        response(false, null, "Produto indisponivel", 404);
    }

    // Option groups
    $stmt = $db->prepare("
        SELECT id, name, min_select, max_select, required, sort_order
        FROM om_product_option_groups
        WHERE product_id = ? AND active = 1
        ORDER BY sort_order, id
    ");
    $stmt->execute([$id]);
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($groups as &$group) {
        $stmt = $db->prepare("
            SELECT id, name, price_extra as price, available, sort_order
            FROM om_product_options
            WHERE group_id = ? AND (available = true OR available IS NULL)
            ORDER BY sort_order, id
        ");
        $stmt->execute([$group['id']]);
        $group['options'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($group);

    // Check special price validity
    $hasSpecial = false;
    if ($product['special_price'] && $product['special_price'] > 0) {
        $now = date('Y-m-d H:i:s');
        $startOk = !$product['special_start'] || $product['special_start'] <= $now;
        $endOk = !$product['special_end'] || $product['special_end'] >= $now;
        $hasSpecial = $startOk && $endOk;
    }

    // Build response
    $result = [
        'id' => (int)$product['id'],
        'name' => $product['name'],
        'description' => $product['description'],
        'price' => (float)$product['price'],
        'image' => $product['image'],
        'images' => $product['images'] ? json_decode($product['images'], true) : null,
        'partner_id' => (int)$product['partner_id'],
        'partner_name' => $product['partner_name'],
        'category_id' => $product['category_id'] ? (int)$product['category_id'] : null,
        'category_name' => $product['category_name'],
        'quantity' => (int)$product['quantity'],
        'in_stock' => $product['in_stock'] ? true : ($product['quantity'] > 0),
        'unit' => $product['unit'],
        'weight' => $product['weight'],
        'brand' => $product['brand'],
        'is_organic' => (bool)$product['is_organic'],
        'is_fresh' => (bool)$product['is_fresh'],
        'is_frozen' => (bool)$product['is_frozen'],
        'is_featured' => (bool)$product['is_featured'],
        'is_combo' => (bool)$product['is_combo'],
        'dietary_tags' => $product['dietary_tags'] ? json_decode($product['dietary_tags'], true) : [],
        'allergens' => $product['allergens'] ? json_decode($product['allergens'], true) : [],
        'min_quantity' => (int)($product['min_quantity'] ?: 1),
        'max_quantity' => (int)($product['max_quantity'] ?: 99),
        'step_quantity' => (int)($product['step_quantity'] ?: 1),
        'option_groups' => $groups,
    ];

    if ($hasSpecial) {
        $result['special_price'] = (float)$product['special_price'];
        $result['original_price'] = (float)$product['price'];
        $result['price'] = (float)$product['special_price'];
        $result['discount_percent'] = $product['price'] > 0
            ? round((1 - $product['special_price'] / $product['price']) * 100)
            : 0;
    }

    response(true, ['product' => $result]);

} catch (Exception $e) {
    response(false, null, "Erro interno", 500);
}
