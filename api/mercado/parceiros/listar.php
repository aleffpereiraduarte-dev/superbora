<?php
/**
 * GET /api/mercado/parceiros/listar.php
 * Lista mercados disponíveis
 * Otimizado com cache (TTL: 10 min)
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 2) . "/cache/CacheHelper.php";

header('Cache-Control: public, max-age=600');

try {
    $lat = $_GET["lat"] ?? null;
    $lng = $_GET["lng"] ?? null;
    $raio = $_GET["raio"] ?? 10;

    // Cache key baseado na localização
    $cacheKey = "mercado_parceiros_" . md5($lat . $lng . $raio);

    $data = CacheHelper::remember($cacheKey, 600, function() use ($lat, $lng, $raio) {
        $db = getDB();

        $sql = "SELECT p.*,
                (SELECT COUNT(*) FROM om_market_products WHERE partner_id = p.partner_id AND status::text = '1') as total_produtos
                FROM om_market_partners p
                WHERE p.status::text = '1'
                ORDER BY p.partner_id DESC";

        $parceiros = $db->query($sql)->fetchAll();

        return [
            "total" => count($parceiros),
            "parceiros" => array_map(function($p) {
                return [
                    "id" => $p["partner_id"],
                    "nome" => $p["name"] ?? $p["trade_name"],
                    "logo" => $p["logo"] ?? null,
                    "endereco" => $p["address"] ?? "",
                    "cidade" => $p["city"] ?? "",
                    "total_produtos" => $p["total_produtos"],
                    "taxa_entrega" => $p["delivery_fee"] ?? 0,
                    "tempo_estimado" => $p["delivery_time_min"] ?? 60,
                    "avaliacao" => $p["rating"] ?? 5.0,
                    // TODO: Replace hardcoded open status with dynamic isOpenNow($p) check from horarios.php
                    "aberto" => ($p["is_open"] ?? null) !== '0' && ($p["is_open"] ?? null) !== false
                ];
            }, $parceiros)
        ];
    });

    response(true, $data);

} catch (Exception $e) {
    error_log("[parceiros/listar] Erro: " . $e->getMessage());
    response(false, null, 'Erro interno do servidor', 500);
}
