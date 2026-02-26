<?php
/**
 * GET /api/mercado/parceiros/por-cep.php?cep=01310100
 * Lista mercados/parceiros que atendem determinado CEP
 *
 * Estratégia de matching:
 * 1. cep_inicio/cep_fim na própria tabela om_market_partners (range de CEP)
 * 2. Mesma cidade (city matching)
 * 3. Prefixo de CEP (3 primeiros dígitos = mesma região)
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 2) . "/cache/CacheHelper.php";

try {
    $cep = preg_replace('/\D/', '', $_GET["cep"] ?? "");

    if (strlen($cep) !== 8) {
        response(false, null, "CEP inválido. Informe 8 dígitos.", 400);
    }

    $db = getDB();

    // Cache por 10 minutos (bypass com ?nocache=1 apenas para admins autenticados)
    $cacheKey = "parceiros_cep_{$cep}";
    $noCache = false;
    if (isset($_GET['nocache'])) {
        // Only allow cache bypass for authenticated admin users
        try {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
            if (preg_match('/Bearer\s+(\S+)/', $authHeader, $matches)) {
                require_once dirname(__DIR__, 2) . "/includes/classes/OmAuth.php";
                OmAuth::getInstance()->setDb($db);
                $payload = om_auth()->validateToken($matches[1]);
                if ($payload && ($payload['type'] ?? '') === 'admin') {
                    $noCache = true;
                }
            }
        } catch (Exception $e) {
            // Silently ignore auth errors - just don't bypass cache
        }
    }

    if ($noCache) {
        CacheHelper::forget($cacheKey);
    }

    $data = CacheHelper::remember($cacheKey, 600, function() use ($db, $cep) {
        $parceiros = [];
        $prefixo3 = substr($cep, 0, 3);
        $prefixo5 = substr($cep, 0, 5);

        // Buscar todos parceiros ativos
        $stmt = $db->query("
            SELECT p.partner_id, p.name, p.trade_name, p.logo, p.banner, p.address, p.neighborhood, p.bairro, p.city, p.cidade, p.state, p.estado, p.cep, p.categoria,
                   p.delivery_fee, p.taxa_entrega, p.minimum_order, p.min_order, p.min_order_value, p.delivery_time_min, p.tempo_preparo,
                   p.free_delivery_above, p.free_delivery_min, p.rating, p.is_open, p.is_featured, p.featured,
                   p.cep_inicio, p.cep_fim, p.delivery_radius_km, p.delivery_radius, p.raio_entrega_km,
                   (SELECT COUNT(*) FROM om_market_products mp WHERE mp.partner_id = p.partner_id AND mp.status::text = '1') as total_produtos
            FROM om_market_partners p
            WHERE p.status::text = '1'
            ORDER BY p.rating DESC NULLS LAST, p.delivery_fee ASC NULLS LAST
            LIMIT 100
        ");
        $todosParceiros = $stmt->fetchAll();

        foreach ($todosParceiros as $p) {
            $atende = false;
            $mensagem = '';

            // Limpa CEP do parceiro
            $cepParceiro = preg_replace('/\D/', '', $p['cep'] ?? '');

            // 1. Verificar range cep_inicio/cep_fim no próprio parceiro
            $cepInicio = preg_replace('/\D/', '', $p['cep_inicio'] ?? '');
            $cepFim = preg_replace('/\D/', '', $p['cep_fim'] ?? '');

            if ($cepInicio && $cepFim) {
                // Range pode ser 5 dígitos (07000-07199) ou 8 dígitos
                $cepCheck = $cep;
                if (strlen($cepInicio) === 5) {
                    $cepCheck = substr($cep, 0, 5);
                }
                if (intval($cepCheck) >= intval($cepInicio) && intval($cepCheck) <= intval($cepFim)) {
                    $atende = true;
                    $mensagem = 'Atende sua região';
                }
            }

            // 2. Mesma cidade
            if (!$atende) {
                $cidadeParceiro = strtolower(trim($p['city'] ?? $p['cidade'] ?? ''));
                // Lookup cidade do CEP via ViaCEP (cacheable)
                // Por ora, comparar prefixo de CEP
            }

            // 3. Prefixo de CEP (3 primeiros dígitos = mesma região metropolitana)
            if (!$atende && $cepParceiro) {
                $prefixoParceiro = substr($cepParceiro, 0, 3);
                if ($prefixoParceiro === $prefixo3) {
                    $atende = true;
                    $mensagem = 'Entrega na sua região';
                }
            }

            // 4. Raio de entrega grande
            if (!$atende) {
                $raio = floatval($p['delivery_radius_km'] ?? $p['delivery_radius'] ?? $p['raio_entrega_km'] ?? 0);
                if ($raio >= 50) {
                    $atende = true;
                    $mensagem = 'Entrega em toda a cidade';
                }
            }

            if ($atende) {
                $parceiros[$p['partner_id']] = formatarParceiro($p, true, $mensagem);
            }
        }

        return [
            "cep" => $cep,
            "total" => count($parceiros),
            "atende" => count($parceiros) > 0,
            "parceiros" => array_values($parceiros)
        ];
    });

    response(true, $data);

} catch (Exception $e) {
    error_log("[parceiros/por-cep] Erro: " . $e->getMessage());
    response(false, null, 'Erro interno do servidor', 500);
}

function formatarParceiro($p, $atende, $mensagem) {
    return [
        "id" => (int)$p["partner_id"],
        "nome" => $p["name"] ?? $p["trade_name"] ?? $p["nome"] ?? '',
        "logo" => $p["logo"] ?? null,
        "banner" => $p["banner"] ?? null,
        "endereco" => $p["address"] ?? $p["endereco"] ?? "",
        "bairro" => $p["neighborhood"] ?? $p["bairro"] ?? "",
        "cidade" => $p["city"] ?? $p["cidade"] ?? "",
        "estado" => $p["state"] ?? $p["estado"] ?? "",
        "cep" => $p["cep"] ?? "",
        "categoria" => $p["categoria"] ?? "",
        "taxa_entrega" => floatval($p["delivery_fee"] ?? $p["taxa_entrega"] ?? 0),
        "pedido_minimo" => floatval($p["minimum_order"] ?? $p["min_order"] ?? $p["min_order_value"] ?? 0),
        "tempo_estimado" => (int)($p["delivery_time_min"] ?? $p["tempo_preparo"] ?? 60),
        "entrega_gratis_acima" => floatval($p["free_delivery_above"] ?? $p["free_delivery_min"] ?? 0),
        "avaliacao" => floatval($p["rating"] ?? 5.0),
        "total_produtos" => (int)($p["total_produtos"] ?? 0),
        "aberto" => ($p["is_open"] ?? null) !== '0' && ($p["is_open"] ?? null) !== false,
        "destaque" => ($p["featured"] ?? $p["is_featured"] ?? '0') === '1',
        "atende" => $atende,
        "mensagem" => $mensagem
    ];
}
