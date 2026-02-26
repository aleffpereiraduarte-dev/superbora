<?php
/**
 * GET /api/mercado/pricing/simular.php
 * Simulacao de precificacao para o frontend
 *
 * Params: partner_id, subtotal, lat, lng, is_pickup, customer_id (opcional)
 *
 * Retorna: frete, comissao, pedido_minimo, cashback estimado, pontos, etc.
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmPricing.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmDailyBudget.php";

try {
    $db = getDB();

    $partner_id = (int)($_GET['partner_id'] ?? 0);
    $subtotal = (float)($_GET['subtotal'] ?? 0);
    $lat = (float)($_GET['lat'] ?? 0);
    $lng = (float)($_GET['lng'] ?? 0);
    $is_pickup = (bool)($_GET['is_pickup'] ?? false);
    $customer_id = (int)($_GET['customer_id'] ?? 0);

    if (!$partner_id) {
        response(false, null, "partner_id obrigatorio", 400);
    }

    // Buscar parceiro
    $stmt = $db->prepare("
        SELECT partner_id, name, trade_name, latitude, lat, longitude, lng,
               entrega_propria, min_order_value, min_order, free_delivery_above,
               delivery_fee, commission_rate, is_open, status
        FROM om_market_partners WHERE partner_id = ?
    ");
    $stmt->execute([$partner_id]);
    $parceiro = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$parceiro) {
        response(false, null, "Parceiro nao encontrado", 404);
    }

    $usaBoraUm = !$is_pickup && !$parceiro['entrega_propria'];

    // Calcular distancia
    $lat_parceiro = (float)($parceiro['latitude'] ?? $parceiro['lat'] ?? 0);
    $lng_parceiro = (float)($parceiro['longitude'] ?? $parceiro['lng'] ?? 0);
    $distancia_km = 3.0;
    if ($lat && $lng && $lat_parceiro && $lng_parceiro) {
        $distancia_km = OmPricing::calcularDistancia($lat_parceiro, $lng_parceiro, $lat, $lng);
    }

    // Frete
    $frete = OmPricing::calcularFrete($parceiro, $subtotal, $distancia_km, $is_pickup, $usaBoraUm, $db, $customer_id);

    // Comissao
    $comissao = OmPricing::calcularComissao($subtotal, $usaBoraUm ? 'boraum' : 'proprio');

    // Pedido minimo
    $pedido_minimo = $usaBoraUm ? OmPricing::getMinimoBoraUm($distancia_km) : (float)($parceiro['min_order_value'] ?? $parceiro['min_order'] ?? 0);

    // Frete gratis a partir de
    $frete_gratis_a_partir = (float)($parceiro['free_delivery_above'] ?? 0);

    // SuperBora+
    $isMembro = ($customer_id > 0) ? OmPricing::isSuperboraPlus($db, $customer_id) : false;

    // Pontos estimados
    $pontos = ($subtotal > 0) ? OmPricing::calcularPontos($subtotal, $isMembro) : 0;

    // Cashback estimado (2% base)
    $cashback = round($subtotal * 0.02, 2);
    if ($cashback > OmPricing::CASHBACK_MAX_POR_PEDIDO) {
        $cashback = OmPricing::CASHBACK_MAX_POR_PEDIDO;
    }

    // Modo do dia
    $budget = OmDailyBudget::getInstance()->setDb($db);
    $modo = $budget->getModo();

    // Beneficio variavel
    $beneficio = null;
    if ($customer_id > 0) {
        $beneficio = OmPricing::getBeneficioVariavel($db, $customer_id, $isMembro, [
            'subtotal' => $subtotal,
            'delivery_fee' => $frete['frete'],
            'custo_boraum' => $frete['custo_boraum'],
            'tipo_entrega' => $usaBoraUm ? 'boraum' : ($is_pickup ? 'retirada' : 'proprio'),
        ]);
    }

    // Viabilidade
    $viavel = true;
    $mensagem_bloqueio = null;
    if ($usaBoraUm && $subtotal > 0 && $subtotal < $pedido_minimo) {
        $viavel = false;
        $mensagem_bloqueio = "Pedido minimo R$ " . number_format($pedido_minimo, 2, ',', '.') . " para esta distancia";
    }

    response(true, [
        'frete' => round($frete['frete'], 2),
        'frete_gratis' => $frete['gratis'],
        'frete_gratis_a_partir' => $frete_gratis_a_partir,
        'custo_boraum' => round($frete['custo_boraum'], 2),
        'desconto_plus_frete' => round($frete['desconto_plus'], 2),
        'comissao_pct' => $comissao['taxa'] * 100,
        'service_fee' => OmPricing::TAXA_SERVICO,
        'pedido_minimo' => $pedido_minimo,
        'distancia_km' => $distancia_km,
        'usa_boraum' => $usaBoraUm,
        'cashback_estimado' => $cashback,
        'pontos_estimados' => $pontos,
        'is_superbora_plus' => $isMembro,
        'beneficio_variavel' => $beneficio,
        'modo_dia' => $modo,
        'viavel' => $viavel,
        'mensagem_bloqueio' => $mensagem_bloqueio,
    ]);

} catch (Exception $e) {
    error_log("[pricing/simular] Erro: " . $e->getMessage());
    response(false, null, "Erro ao simular precificacao", 500);
}
