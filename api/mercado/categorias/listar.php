<?php
/**
 * GET /api/mercado/categorias/listar.php
 * Lista categorias do mercado
 * Otimizado com cache (TTL: 30 min)
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 2) . "/cache/CacheHelper.php";

header('Cache-Control: public, max-age=1800');

try {
    $data = CacheHelper::remember("mercado_categorias", 1800, function() {
        $db = getDB();
        $categorias = $db->query("SELECT category_id, name, icon, sort_order FROM om_market_categories WHERE status::text = '1' ORDER BY sort_order, name")->fetchAll();
        return ["categorias" => $categorias];
    });

    response(true, $data);

} catch (Exception $e) {
    error_log("[categorias/listar] Erro: " . $e->getMessage());
    response(false, null, 'Erro interno do servidor', 500);
}
