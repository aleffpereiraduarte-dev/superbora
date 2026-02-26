<?php
/**
 * GET /mercado/api/parceiros.php
 * Lista todos os mercados parceiros
 */
require_once __DIR__ . "/config.php";

try {
    $db = getDB();
    
    $lat = $_GET["lat"] ?? null;
    $lng = $_GET["lng"] ?? null;
    
    $sql = "SELECT p.*, 
            (SELECT COUNT(*) FROM om_market_products WHERE partner_id = p.partner_id AND status = '1') as total_produtos
            FROM om_market_partners p
            WHERE p.status = '1'
            ORDER BY p.partner_id DESC";
    
    $parceiros = $db->query($sql)->fetchAll();
    
    response(true, [
        "total" => count($parceiros),
        "parceiros" => array_map(function($p) {
            return [
                "id" => $p["partner_id"],
                "nome" => $p["name"] ?? $p["trade_name"],
                "logo" => $p["logo"],
                "endereco" => $p["address"],
                "cidade" => $p["city"],
                "total_produtos" => (int)$p["total_produtos"],
                "taxa_entrega" => floatval($p["delivery_fee"] ?? 0),
                "tempo_estimado" => (int)($p["delivery_time_min"] ?? 60),
                "avaliacao" => floatval($p["rating"] ?? 5),
                "aberto" => true
            ];
        }, $parceiros)
    ]);
    
} catch (Exception $e) {
    response(false, null, $e->getMessage(), 500);
}
