<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * GET /api/mercado/shopper/pedidos-disponiveis.php
 * Lista pedidos disponíveis para shoppers
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * REQUER: Autenticação de Shopper APROVADO pelo RH
 * Header: Authorization: Bearer <token>
 *
 * Query: ?lat=-23.5&lng=-46.6&raio=10
 *
 * SEGURANÇA:
 * - ✅ Autenticação obrigatória
 * - ✅ Verificação de aprovação RH
 * - ✅ Filtro por geolocalização funcional
 * - ✅ Prepared statements
 * - ✅ Cache curto (30s) para performance
 */

require_once __DIR__ . "/../config/auth.php";
require_once dirname(__DIR__, 2) . "/cache/CacheHelper.php";

try {
    $db = getDB();

    // ═══════════════════════════════════════════════════════════════════
    // AUTENTICAÇÃO - Shopper precisa estar aprovado pelo RH
    // ═══════════════════════════════════════════════════════════════════
    $auth = requireShopperAuth();
    $shopper_id = $auth['uid'];

    // Verificar se shopper está aprovado
    if (!om_auth()->isShopperApproved($shopper_id)) {
        response(false, [
            "status" => "pending",
            "pedidos" => []
        ], "Seu cadastro ainda não foi aprovado pelo RH. Aguarde a análise.", 403);
    }

    $lat = floatval($_GET["lat"] ?? 0);
    $lng = floatval($_GET["lng"] ?? 0);
    $raio = (int)($_GET["raio"] ?? 10); // km

    // Validar coordenadas
    $usarGeo = false;
    if ($lat != 0 && $lng != 0) {
        // Validar que são coordenadas válidas
        if ($lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180) {
            $usarGeo = true;
        }
    }

    // Cache curto pois pedidos mudam frequentemente
    // Cache key inclui localização para resultados personalizados
    $cacheKey = $usarGeo
        ? "shopper_pedidos_" . round($lat, 2) . "_" . round($lng, 2) . "_" . $raio
        : "shopper_pedidos_all";

    $data = CacheHelper::remember($cacheKey, 30, function() use ($db, $lat, $lng, $raio, $usarGeo) {

        if ($usarGeo) {
            // Query com filtro de distância usando fórmula de Haversine
            $sql = "
                SELECT o.*,
                    p.name as parceiro_nome,
                    p.address as parceiro_endereco,
                    p.logo,
                    p.latitude as parceiro_lat,
                    p.longitude as parceiro_lng,
                    (SELECT COUNT(*) FROM om_market_order_items WHERE order_id = o.order_id) as total_itens,
                    (
                        6371 * acos(
                            cos(radians(?)) * cos(radians(p.latitude)) *
                            cos(radians(p.longitude) - radians(?)) +
                            sin(radians(?)) * sin(radians(p.latitude))
                        )
                    ) AS distancia_km
                FROM om_market_orders o
                INNER JOIN om_market_partners p ON o.partner_id = p.partner_id
                WHERE o.status IN ('pendente', 'pending')
                AND o.shopper_id IS NULL
                AND LOWER(COALESCE(o.partner_categoria, 'mercado')) IN ('mercado', 'supermercado')
                AND p.latitude IS NOT NULL
                AND p.longitude IS NOT NULL
                HAVING distancia_km <= ?
                ORDER BY distancia_km ASC, o.date_added DESC
                LIMIT 20
            ";

            $stmt = $db->prepare($sql);
            $stmt->execute([$lat, $lng, $lat, $raio]);
        } else {
            // Query sem filtro de distância
            $sql = "
                SELECT o.*,
                    p.name as parceiro_nome,
                    p.address as parceiro_endereco,
                    p.logo,
                    p.latitude as parceiro_lat,
                    p.longitude as parceiro_lng,
                    (SELECT COUNT(*) FROM om_market_order_items WHERE order_id = o.order_id) as total_itens
                FROM om_market_orders o
                INNER JOIN om_market_partners p ON o.partner_id = p.partner_id
                WHERE o.status IN ('pendente', 'pending')
                AND o.shopper_id IS NULL
                AND LOWER(COALESCE(o.partner_categoria, 'mercado')) IN ('mercado', 'supermercado')
                ORDER BY o.date_added DESC
                LIMIT 20
            ";

            $stmt = $db->query($sql);
        }

        $pedidos = $stmt->fetchAll();

        return array_map(function($p) use ($lat, $lng, $usarGeo) {
            $distancia = null;
            if ($usarGeo && isset($p['distancia_km'])) {
                $distancia = round($p['distancia_km'], 1);
            }

            return [
                "order_id" => $p["order_id"],
                "parceiro" => [
                    "id" => $p["partner_id"],
                    "nome" => $p["parceiro_nome"],
                    "endereco" => $p["parceiro_endereco"],
                    "logo" => $p["logo"],
                    "distancia_km" => $distancia
                ],
                "total_itens" => (int)$p["total_itens"],
                "valor_total" => floatval($p["total"]),
                "endereco_entrega" => $p["delivery_address"],
                "criado_em" => $p["date_added"],
                "estimativa_ganho" => calcularEstimativaGanho($p)
            ];
        }, $pedidos);
    });

    response(true, [
        "total" => count($data),
        "filtro_geo" => $usarGeo,
        "raio_km" => $raio,
        "pedidos" => $data
    ]);

} catch (Exception $e) {
    error_log("[pedidos-disponiveis] Erro: " . $e->getMessage());
    response(false, null, "Erro ao buscar pedidos. Tente novamente.", 500);
}

/**
 * Calcula estimativa de ganho do shopper
 */
function calcularEstimativaGanho($pedido): array {
    $valor_venda = floatval($pedido['subtotal'] ?: ($pedido['total'] - ($pedido['delivery_fee'] ?? 0)));
    $qtd_itens = (int)($pedido['total_itens'] ?: 1);

    // 5% do subtotal + R$0.50/item (mesmo cálculo do simular-ganho.php)
    $percentual = 5;
    $valor_por_item = 0.50;
    $valor_minimo = 5.00;

    $valor_base = $valor_venda * ($percentual / 100);
    $valor_itens = $qtd_itens * $valor_por_item;
    $valor_calculado = $valor_base + $valor_itens;
    $valor_final = max($valor_calculado, $valor_minimo);

    return [
        "valor" => round($valor_final, 2),
        "valor_formatado" => "R$ " . number_format($valor_final, 2, ',', '.'),
        "minimo_garantido" => $valor_minimo
    ];
}
