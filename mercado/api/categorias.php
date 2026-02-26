<?php
/**
 * GET /mercado/api/categorias.php
 * Lista categorias
 */
require_once __DIR__ . "/config.php";

try {
    $db = getDB();
    
    $partner_id = $_GET["partner_id"] ?? null;
    
    if ($partner_id) {
        $sql = "SELECT DISTINCT c.* FROM om_market_categories c
                INNER JOIN om_market_products p ON p.category_id = c.category_id
                WHERE p.partner_id = $partner_id AND p.status = '1' AND c.status = '1'
                ORDER BY c.sort_order, c.name";
    } else {
        $sql = "SELECT * FROM om_market_categories WHERE status = '1' ORDER BY sort_order, name";
    }
    
    $categorias = $db->query($sql)->fetchAll();
    
    response(true, [
        "total" => count($categorias),
        "categorias" => array_map(function($c) {
            return [
                "id" => $c["category_id"],
                "nome" => $c["name"],
                "icone" => $c["icon"] ?? null,
                "imagem" => $c["image"] ?? null
            ];
        }, $categorias)
    ]);
    
} catch (Exception $e) {
    response(false, null, $e->getMessage(), 500);
}
