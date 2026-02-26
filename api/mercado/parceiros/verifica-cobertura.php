<?php
/**
 * GET /api/mercado/parceiros/verifica-cobertura.php?partner_id=1&cep=01310100
 * Verifica se um parceiro específico atende determinado CEP
 */
require_once __DIR__ . "/../config/database.php";

try {
    $partner_id = (int)($_GET["partner_id"] ?? 0);
    $cep = preg_replace('/\D/', '', $_GET["cep"] ?? "");

    if (!$partner_id) {
        response(false, null, "partner_id obrigatório", 400);
    }

    if (strlen($cep) !== 8) {
        response(false, null, "CEP inválido. Informe 8 dígitos.", 400);
    }

    $db = getDB();

    // Buscar parceiro
    $stmt = $db->prepare("SELECT partner_id, name, trade_name, logo, city, state, zipcode, cep, delivery_fee, delivery_time_min, delivery_radius_km FROM om_market_partners WHERE partner_id = ? AND status::text = '1'");
    $stmt->execute([$partner_id]);
    $parceiro = $stmt->fetch();

    if (!$parceiro) {
        response(false, null, "Parceiro não encontrado", 404);
    }

    // 1. Verificar se tem cobertura específica cadastrada
    // IMPORTANTE: Usar CAST para comparação numérica correta de CEP
    // Sem CAST, '02000000' BETWEEN '01000000' AND '01999999' = FALSE (comparação string)
    // Com CAST, comparação numérica correta
    $stmt = $db->prepare("
        SELECT * FROM om_partner_coverage
        WHERE partner_id = ?
          AND CAST(? AS BIGINT) BETWEEN CAST(cep_inicio AS BIGINT) AND CAST(cep_fim AS BIGINT)
        LIMIT 1
    ");
    $stmt->execute([$partner_id, $cep]);
    $cobertura = $stmt->fetch();

    if ($cobertura) {
        // Tem cobertura específica e o CEP está dentro
        response(true, [
            "atende" => true,
            "parceiro" => formatarParceiro($parceiro),
            "mensagem" => "Entregamos no seu endereço!",
            "taxa_entrega" => floatval($cobertura["taxa_entrega"] ?? $parceiro["delivery_fee"] ?? 0),
            "tempo_estimado" => (int)($cobertura["tempo_estimado"] ?? $parceiro["delivery_time_min"] ?? 60)
        ]);
    }

    // 2. Se não tem cobertura específica, verificar por região (3 primeiros dígitos)
    $cepParceiro = preg_replace('/\D/', '', $parceiro['zipcode'] ?? '');
    $prefixoParceiro = substr($cepParceiro, 0, 3);
    $prefixoCliente = substr($cep, 0, 3);

    // Mesma região ou raio grande = atende
    $mesmaRegiao = ($prefixoParceiro === $prefixoCliente);
    $raioGrande = ($parceiro['delivery_radius_km'] ?? 0) >= 50;

    if ($mesmaRegiao || $raioGrande) {
        response(true, [
            "atende" => true,
            "parceiro" => formatarParceiro($parceiro),
            "mensagem" => "Entregamos na sua região!",
            "taxa_entrega" => floatval($parceiro["delivery_fee"] ?? 0),
            "tempo_estimado" => (int)($parceiro["delivery_time_min"] ?? 60)
        ]);
    }

    // 3. Não atende
    response(true, [
        "atende" => false,
        "parceiro" => formatarParceiro($parceiro),
        "mensagem" => "Infelizmente este mercado ainda não entrega no seu CEP.",
        "sugestao" => "Veja outros mercados que entregam na sua região"
    ]);

} catch (Exception $e) {
    error_log("[parceiros/verifica-cobertura] Erro: " . $e->getMessage());
    response(false, null, 'Erro interno do servidor', 500);
}

function formatarParceiro($p) {
    return [
        "id" => (int)$p["partner_id"],
        "nome" => $p["name"] ?? $p["trade_name"],
        "logo" => $p["logo"] ?? null,
        "cidade" => $p["city"] ?? ""
    ];
}
