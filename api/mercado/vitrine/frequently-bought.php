<?php
/**
 * GET /api/mercado/vitrine/frequently-bought.php
 * Retorna produtos frequentemente comprados juntos
 */
require_once __DIR__ . "/../config/database.php";

setCorsHeaders();

try {
    $db = getDB();

    $productId = (int)($_GET['product_id'] ?? 0);
    $partnerId = (int)($_GET['partner_id'] ?? 0);
    $limit = min(5, max(2, (int)($_GET['limit'] ?? 3)));

    if (!$productId || !$partnerId) {
        response(false, null, "product_id e partner_id obrigatorios", 400);
    }

    // Buscar produtos que aparecem junto em pedidos
    // Estratégia: produtos da mesma loja que aparecem em pedidos com o produto atual
    $stmt = $db->prepare("
        SELECT DISTINCT p.product_id, p.name, p.image, p.price, p.special_price,
               COUNT(DISTINCT oi2.order_id) as bought_together_count
        FROM om_market_order_items oi1
        INNER JOIN om_market_order_items oi2 ON oi1.order_id = oi2.order_id AND oi1.product_id != oi2.product_id
        INNER JOIN om_market_products p ON oi2.product_id = p.product_id
        WHERE oi1.product_id = ?
        AND p.partner_id = ?
        AND p.status = '1'
        GROUP BY p.product_id, p.name, p.image, p.price, p.special_price
        ORDER BY bought_together_count DESC
        LIMIT ?
    ");
    $stmt->execute([$productId, $partnerId, $limit]);
    $items = $stmt->fetchAll();

    // Se não houver dados de co-compra, retornar produtos populares da loja
    if (empty($items)) {
        $stmt = $db->prepare("
            SELECT p.product_id, p.name, p.image, p.price, p.special_price,
                   COALESCE(SUM(oi.quantity), 0) as total_sold
            FROM om_market_products p
            LEFT JOIN om_market_order_items oi ON p.product_id = oi.product_id
            WHERE p.partner_id = ?
            AND p.product_id != ?
            AND p.status = '1'
            GROUP BY p.product_id, p.name, p.image, p.price, p.special_price
            ORDER BY total_sold DESC, p.product_id DESC
            LIMIT ?
        ");
        $stmt->execute([$partnerId, $productId, $limit]);
        $items = $stmt->fetchAll();
    }

    response(true, [
        'items' => array_map(function($p) {
            return [
                'id' => (int)$p['product_id'],
                'nome' => $p['name'],
                'imagem' => $p['image'],
                'preco' => (float)$p['price'],
                'preco_promocional' => $p['special_price'] ? (float)$p['special_price'] : null,
            ];
        }, $items)
    ]);

} catch (Exception $e) {
    error_log("[vitrine/frequently-bought] Erro: " . $e->getMessage());
    response(false, null, "Erro ao buscar produtos relacionados", 500);
}
