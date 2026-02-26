<?php
/**
 * GET /api/mercado/store/flash-deals.php?partner_id=X
 * Lista ofertas relampago ativas de uma loja
 */

require_once __DIR__ . "/../config/database.php";

setCorsHeaders();

header('Cache-Control: public, max-age=60');

try {
    $db = getDB();

    $partnerId = (int)($_GET['partner_id'] ?? 0);
    if (!$partnerId) response(false, null, "partner_id obrigatorio", 400);

    $now = date('Y-m-d H:i:s');

    // Get products with active sale_price (flash deals)
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
            CASE WHEN p.price > 0 THEN ROUND((1 - p.special_price / p.price) * 100) ELSE 0 END as discount_percent
        FROM om_market_products p
        WHERE p.partner_id = ?
          AND p.status = '1'
          AND p.special_price IS NOT NULL
          AND p.special_price > 0
          AND p.special_price < p.price
        ORDER BY
            CASE WHEN p.price > 0 THEN (1 - p.special_price / p.price) ELSE 0 END DESC
        LIMIT 10
    ");
    $stmt->execute([$partnerId]);
    $deals = $stmt->fetchAll();

    foreach ($deals as &$deal) {
        $deal['preco'] = (float)$deal['preco'];
        $deal['price'] = (float)$deal['price'];
        $deal['special_price'] = (float)$deal['special_price'];
        $deal['discount_percent'] = (int)$deal['discount_percent'];
        $deal['flash_deal'] = true;
    }

    response(true, ['deals' => $deals, 'flash_deals' => $deals]);

} catch (Exception $e) {
    error_log("[store/flash-deals] Erro: " . $e->getMessage());
    response(false, ['deals' => [], 'flash_deals' => []], "Erro ao listar ofertas", 500);
}
