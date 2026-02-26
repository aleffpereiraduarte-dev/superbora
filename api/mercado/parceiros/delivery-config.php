<?php
/**
 * GET /api/mercado/parceiros/delivery-config.php?ids=101,102,103
 * Retorna configuracao de entrega de multiplos parceiros.
 * Usado pelo carrinho e checkout para calcular frete corretamente.
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmPricing.php";
require_once dirname(__DIR__, 2) . "/rate-limit/RateLimiter.php";

setCorsHeaders();

// Rate limit: 30 req/min per IP
if (!RateLimiter::check(30, 60)) {
    exit;
}

try {
    $db = getDB();

    $idsRaw = trim($_GET['ids'] ?? '');
    if (empty($idsRaw)) {
        response(false, null, "ids obrigatorio", 400);
    }

    // Coordenadas do cliente (opcional — para calcular distancia e minimo por distancia)
    $lat_cliente = (float)($_GET['lat'] ?? 0);
    $lng_cliente = (float)($_GET['lng'] ?? 0);

    // Sanitizar: aceitar apenas numeros separados por virgula
    $ids = array_filter(array_map('intval', explode(',', $idsRaw)));
    if (empty($ids)) {
        response(false, null, "ids invalidos", 400);
    }

    // Limitar a 20 parceiros por request
    $ids = array_slice($ids, 0, 20);

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("
        SELECT
            partner_id,
            delivery_fee,
            taxa_entrega,
            free_delivery_above,
            free_delivery_min,
            min_order,
            min_order_value,
            delivery_time_min,
            delivery_time_max,
            aceita_retirada,
            entrega_propria,
            aceita_boraum,
            delivery_radius_km,
            latitude,
            longitude
        FROM om_market_partners
        WHERE partner_id IN ($placeholders)
    ");
    $stmt->execute($ids);

    $result = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $pid = (int)$row['partner_id'];
        $fee = (float)($row['delivery_fee'] ?: $row['taxa_entrega'] ?: 0);
        $freeAbove = (float)($row['free_delivery_above'] ?: 0);
        $minOrder = (float)($row['min_order'] ?: $row['min_order_value'] ?: 0);

        // BoraUm: calcular distancia e minimo por distancia
        // BUG 3 fix: usa_boraum considera aceita_boraum (nao so entrega_propria)
        $usaBoraUm = !$row['entrega_propria'] && ($row['aceita_boraum'] ?? true);
        $distancia_km = 0;
        $minimo_distancia = 0;

        if ($usaBoraUm) {
            // Calcular distancia se temos coordenadas
            $lat_parceiro = (float)($row['latitude'] ?? 0);
            $lng_parceiro = (float)($row['longitude'] ?? 0);
            if ($lat_cliente && $lng_cliente && $lat_parceiro && $lng_parceiro) {
                $distancia_km = OmPricing::calcularDistancia($lat_parceiro, $lng_parceiro, $lat_cliente, $lng_cliente);
            }
            // BUG 4 fix: sem coordenadas = distancia 0, frontend mostra aviso

            // Minimo por distancia (regra BoraUm)
            $minimo_distancia = OmPricing::getMinimoBoraUm($distancia_km);

            // Frete real baseado na distancia
            $custoBoraUm = OmPricing::calcularCustoBoraUm($distancia_km);
            $fee = max($fee, $custoBoraUm + 1.0); // custo + R$1 margem

            if ($fee < OmPricing::BORAUM_MINIMO) {
                $fee = OmPricing::BORAUM_MINIMO;
            }
        }

        // Pedido minimo = max(parceiro, distancia)
        $pedidoMinimo = max($minOrder, $minimo_distancia);

        $result[(string)$pid] = [
            'taxa_entrega' => round($fee, 2),
            'entrega_gratis_acima' => $freeAbove > 0 ? $freeAbove : null,
            'pedido_minimo' => $pedidoMinimo,
            'pedido_minimo_parceiro' => $minOrder,
            'pedido_minimo_distancia' => $minimo_distancia,
            'distancia_km' => round($distancia_km, 1),
            'tempo_min' => (int)($row['delivery_time_min'] ?: 30),
            'tempo_max' => (int)($row['delivery_time_max'] ?: 60),
            'aceita_retirada' => (bool)$row['aceita_retirada'],
            'entrega_propria' => (bool)$row['entrega_propria'],
            'aceita_boraum' => (bool)$row['aceita_boraum'],
            'raio_km' => (float)($row['delivery_radius_km'] ?: 10),
            'usa_boraum' => $usaBoraUm,
            'latitude' => (float)($row['latitude'] ?? 0),
            'longitude' => (float)($row['longitude'] ?? 0),
        ];
    }

    response(true, $result);

} catch (Exception $e) {
    error_log("[delivery-config] Erro: " . $e->getMessage());
    response(false, null, "Erro ao buscar configuracao", 500);
}
