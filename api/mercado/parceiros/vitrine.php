<?php
/**
 * GET /api/mercado/parceiros/vitrine.php
 * Vitrine de estabelecimentos - lista parceiros ativos para exibicao publica
 *
 * Parametros opcionais:
 *   ?categoria=mercado|restaurante|farmacia|loja
 *   ?busca=termo
 *   ?lat=&lng= (para calculo de distancia)
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 2) . "/cache/CacheHelper.php";

header('Cache-Control: public, max-age=300');

try {
    $categoria = $_GET["categoria"] ?? null;
    $busca = $_GET["busca"] ?? null;
    $lat = isset($_GET["lat"]) ? (float)$_GET["lat"] : null;
    $lng = isset($_GET["lng"]) ? (float)$_GET["lng"] : null;
    $city = isset($_GET["city"]) ? trim($_GET["city"]) : null;
    $state = isset($_GET["state"]) ? trim($_GET["state"]) : null;

    // Validar categoria se fornecida
    $categorias_validas = ['mercado', 'restaurante', 'farmacia', 'loja'];
    if ($categoria && !in_array($categoria, $categorias_validas)) {
        $categoria = null;
    }

    // Cache key baseado nos parametros
    $cacheKey = "vitrine_" . md5(($categoria ?? '') . ($busca ?? '') . ($lat ?? '') . ($lng ?? '') . ($city ?? '') . ($state ?? ''));

    $data = CacheHelper::remember($cacheKey, 300, function() use ($categoria, $busca, $lat, $lng, $city, $state) {
        $db = getDB();

        $params = [];
        $where = ["p.status::text = '1'"];

        // Filtro por cidade (prioritario para evitar lojas de outras cidades)
        if ($city && $city !== '') {
            $where[] = "LOWER(TRIM(p.city)) = LOWER(?)";
            $params[] = $city;
        }

        // Filtro por estado
        if ($state && $state !== '' && !$city) {
            $where[] = "UPPER(TRIM(p.state)) = UPPER(?)";
            $params[] = $state;
        }

        // Filtro por categoria
        if ($categoria) {
            $where[] = "p.categoria = ?";
            $params[] = $categoria;
        }

        // Filtro por busca (nome)
        if ($busca) {
            $buscaEscaped = str_replace(['%', '_'], ['\\%', '\\_'], $busca);
            $where[] = "p.name LIKE ?";
            $params[] = "%" . $buscaEscaped . "%";
        }

        // Campos de distancia (Haversine) se coordenadas fornecidas
        $distanciaSelect = "";
        $distanciaOrder = "";
        if ($lat !== null && $lng !== null && $lat != 0 && $lng != 0) {
            $distanciaSelect = ", (6371 * ACOS(
                LEAST(1, GREATEST(-1,
                    COS(RADIANS(?)) * COS(RADIANS(COALESCE(p.lat, 0))) * COS(RADIANS(COALESCE(p.lng, 0)) - RADIANS(?))
                    + SIN(RADIANS(?)) * SIN(RADIANS(COALESCE(p.lat, 0)))
                ))
            )) AS distancia";
            $distanciaOrder = "distancia ASC, ";
            // Parametros do Haversine (3x lat, lng)
            array_unshift($params, $lat, $lng, $lat);
        }

        $whereClause = implode(" AND ", $where);

        $sql = "SELECT p.partner_id, p.name, p.trade_name, p.logo, p.categoria,
                       p.address, p.city, p.state, p.phone,
                       p.open_time, p.close_time, p.is_open,
                       p.rating, p.delivery_fee, p.delivery_time_min,
                       p.lat, p.lng
                       {$distanciaSelect},
                       (SELECT COUNT(*) FROM om_market_products mp WHERE mp.partner_id = p.partner_id AND mp.status::text = '1') as total_produtos
                FROM om_market_partners p
                WHERE {$whereClause}
                ORDER BY {$distanciaOrder}p.name ASC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $parceiros = $stmt->fetchAll();

        return array_map(function($p) {
            return [
                "id" => (int)$p["partner_id"],
                "nome" => $p["name"] ?? $p["trade_name"] ?? "",
                "logo" => $p["logo"] ?? null,
                "categoria" => $p["categoria"] ?? "mercado",
                "endereco" => $p["address"] ?? "",
                "cidade" => $p["city"] ?? "",
                "estado" => $p["state"] ?? "",
                "telefone" => $p["phone"] ?? "",
                "horario_abertura" => $p["open_time"] ?? null,
                "horario_fechamento" => $p["close_time"] ?? null,
                "aberto" => (int)($p["is_open"] ?? 0) === 1,
                "avaliacao" => (float)($p["rating"] ?? 5.0),
                "taxa_entrega" => (float)($p["delivery_fee"] ?? 0),
                "tempo_estimado" => (int)($p["delivery_time_min"] ?? 60),
                "total_produtos" => (int)($p["total_produtos"] ?? 0),
                "distancia" => isset($p["distancia"]) ? round((float)$p["distancia"], 1) : null
            ];
        }, $parceiros);
    });

    response(true, [
        "total" => count($data),
        "parceiros" => $data
    ]);

} catch (Exception $e) {
    error_log("[parceiros/vitrine] Erro: " . $e->getMessage());
    response(false, null, 'Erro interno do servidor', 500);
}
