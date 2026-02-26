<?php
/**
 * GET /mercado/api/produtos.php?partner_id=1
 * Lista produtos de um mercado
 */
require_once __DIR__ . "/config.php";

try {
    $db = getDB();
    
    $partner_id = $_GET["partner_id"] ?? 0;
    $category_id = $_GET["category_id"] ?? null;
    $busca = $_GET["q"] ?? $_GET["busca"] ?? null;
    $limite = $_GET["limite"] ?? 100;
    
    $where = ["p.status = '1'"];
    if ($partner_id) $where[] = "p.partner_id = $partner_id";
    if ($category_id) $where[] = "p.category_id = $category_id";
    if ($busca) $where[] = "(p.name LIKE \"%$busca%\" OR p.description LIKE \"%$busca%\")";
    
    $whereSQL = implode(" AND ", $where);
    
    $sql = "SELECT p.*, c.name as categoria_nome
            FROM om_market_products p
            LEFT JOIN om_market_categories c ON p.category_id = c.category_id
            WHERE $whereSQL
            ORDER BY p.name
            LIMIT $limite";
    
    $produtos = $db->query($sql)->fetchAll();
    
    response(true, [
        "total" => count($produtos),
        "produtos" => array_map(function($p) {
            return [
                "id" => $p["product_id"],
                "nome" => $p["name"],
                "descricao" => $p["description"],
                "preco" => floatval($p["price"]),
                "imagem" => $p["image"],
                "categoria" => $p["categoria_nome"],
                "categoria_id" => $p["category_id"],
                "unidade" => $p["unit"] ?? "un",
                "estoque" => (int)($p["quantity"] ?? 999),
                "disponivel" => true
            ];
        }, $produtos)
    ]);
    
} catch (Exception $e) {
    response(false, null, $e->getMessage(), 500);
}
