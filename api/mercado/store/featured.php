<?php
/**
 * GET /api/mercado/store/featured.php?partner_id=X
 * Lista produtos mais pedidos/populares de uma loja
 */

require_once __DIR__ . "/../config/database.php";

setCorsHeaders();

try {
    $db = getDB();

    $partnerId = (int)($_GET['partner_id'] ?? 0);
    if (!$partnerId) response(false, null, "partner_id obrigatorio", 400);

    // Get products ordered by total_vendas (sales count) or by a popularity score
    $stmt = $db->prepare("
        SELECT
            p.product_id as id,
            p.name as nome,
            p.description as descricao,
            p.price as preco,
            p.promo_price as preco_promo,
            p.image as imagem,
            p.unit as unidade,
            p.status as disponivel,
            p.stock as estoque,
            COALESCE(p.total_vendas, 0) as total_vendas,
            CASE
                WHEN p.promo_price > 0 AND p.promo_price < p.price
                THEN ROUND((1 - p.promo_price / p.price) * 100)
                ELSE 0
            END as desconto
        FROM om_market_products p
        WHERE p.partner_id = ?
          AND p.status = '1'
          AND p.stock > 0
        ORDER BY COALESCE(p.total_vendas, 0) DESC, p.product_id DESC
        LIMIT 10
    ");
    $stmt->execute([$partnerId]);
    $products = $stmt->fetchAll();

    // Convert numeric fields
    foreach ($products as &$p) {
        $p['id'] = (int)$p['id'];
        $p['preco'] = (float)$p['preco'];
        $p['preco_promo'] = (float)$p['preco_promo'];
        $p['disponivel'] = (bool)$p['disponivel'];
        $p['estoque'] = (int)$p['estoque'];
        $p['total_vendas'] = (int)$p['total_vendas'];
        $p['desconto'] = (int)$p['desconto'];
    }

    response(true, ['products' => $products]);

} catch (Exception $e) {
    error_log("[store/featured] Erro: " . $e->getMessage());
    response(true, ['products' => []]);
}
