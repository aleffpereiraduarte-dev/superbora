<?php
/**
 * GET /mercado/api/frete.php?partner_id=1
 * Calcular frete do mercado
 */
require_once __DIR__ . "/config.php";

try {
    $db = getDB();
    
    $partner_id = (int)($_GET["partner_id"] ?? 0);

    if (!$partner_id) {
        response(false, null, "partner_id obrigatório", 400);
    }

    // Usar prepared statement para prevenir SQL injection
    $stmt = $db->prepare("SELECT partner_id, name, delivery_fee, delivery_time_min, free_delivery_above
                          FROM om_market_partners WHERE partner_id = ?");
    $stmt->execute([$partner_id]);
    $parceiro = $stmt->fetch();
    
    if (!$parceiro) {
        response(false, null, "Parceiro não encontrado", 404);
    }
    
    response(true, [
        "partner_id" => $parceiro["partner_id"],
        "parceiro" => $parceiro["name"],
        "taxa_entrega" => floatval($parceiro["delivery_fee"] ?? 7.90),
        "tempo_estimado_min" => (int)($parceiro["delivery_time_min"] ?? 60),
        "entrega_gratis_acima" => floatval($parceiro["free_delivery_above"] ?? 0)
    ]);
    
} catch (Exception $e) {
    response(false, null, $e->getMessage(), 500);
}
