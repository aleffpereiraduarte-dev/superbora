<?php
/**
 * GET /api/mercado/frete/calcular.php?partner_id=1&cep=01310100
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 2) . "/rate-limit/RateLimiter.php";

// SECURITY: Rate limiting — 30 req/min per IP
if (!RateLimiter::check(30, 60)) {
    exit;
}

try {
    $db = getDB();
    
    $partner_id = (int)($_GET["partner_id"] ?? 0);
    $cep = preg_replace('/\D/', '', $_GET["cep"] ?? "");

    // Buscar parceiro — only needed columns
    $stmt = $db->prepare("SELECT partner_id, delivery_fee, delivery_time_min, free_delivery_above FROM om_market_partners WHERE partner_id = ?");
    $stmt->execute([$partner_id]);
    $parceiro = $stmt->fetch();
    
    if (!$parceiro) response(false, null, "Parceiro não encontrado", 404);
    
    // Por enquanto, retorna taxa fixa do parceiro
    $taxa = $parceiro["delivery_fee"] ?? 5.00;
    $tempo = $parceiro["delivery_time_min"] ?? 60;
    
    response(true, [
        "taxa_entrega" => round($taxa, 2),
        "tempo_estimado_min" => $tempo,
        "entrega_gratis_acima" => $parceiro["free_delivery_above"] ?? null
    ]);
    
} catch (Exception $e) {
    error_log("[frete/calcular] Erro: " . $e->getMessage());
    response(false, null, 'Erro interno do servidor', 500);
}
