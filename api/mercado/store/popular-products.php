<?php
/**
 * GET /api/mercado/store/popular-products.php?partner_id=X
 * Lista produtos mais vendidos/populares de uma loja
 */

require_once __DIR__ . "/../config/database.php";

setCorsHeaders();

header('Cache-Control: public, max-age=120');

try {
    $db = getDB();

    $partnerId = (int)($_GET['partner_id'] ?? 0);
    $limit = min(20, max(1, (int)($_GET['limit'] ?? 10)));

    if (!$partnerId) response(false, null, "partner_id obrigatorio", 400);

    // Get products ordered by sales count (from order items), falling back to sort_order
    $stmt = $db->prepare("
        SELECT
            p.product_id as id,
            p.product_id,
            p.name as nome,
            p.name,
            p.description as descricao,
            p.price as preco,
            p.price,
            p.special_price,
            p.image,
            p.category_id,
            p.unit,
            p.stock,
            COALESCE(sales.total_sold, 0) as total_sold
        FROM om_market_products p
        LEFT JOIN (
            SELECT oi.product_id, SUM(oi.quantity) as total_sold
            FROM om_market_order_items oi
            JOIN om_market_orders o ON o.order_id = oi.order_id
            WHERE o.partner_id = ? AND o.status NOT IN ('cancelled', 'refunded')
            GROUP BY oi.product_id
        ) sales ON sales.product_id = p.product_id
        WHERE p.partner_id = ? AND p.status = '1'
        ORDER BY COALESCE(sales.total_sold, 0) DESC, p.sort_order ASC
        LIMIT ?
    ");
    $stmt->execute([$partnerId, $partnerId, $limit]);
    $products = $stmt->fetchAll();

    // Format prices
    foreach ($products as &$product) {
        $product['preco'] = (float)$product['preco'];
        $product['price'] = (float)$product['price'];
        $product['special_price'] = $product['special_price'] ? (float)$product['special_price'] : null;
        $product['total_sold'] = (int)$product['total_sold'];
        $product['popular'] = true;
    }

    response(true, ['products' => $products]);

} catch (Exception $e) {
    error_log("[store/popular-products] Erro: " . $e->getMessage());
    response(false, ['products' => []], "Erro ao listar produtos populares", 500);
}
