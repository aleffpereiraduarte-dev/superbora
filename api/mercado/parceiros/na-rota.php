<?php
/**
 * GET /api/mercado/parceiros/na-rota.php
 * Retorna lojas BoraUm que ficam no caminho da rota de entrega (dentro de 1km do segmento)
 *
 * Params: origin_lat, origin_lng, dest_lat, dest_lng, exclude_partner_id, limit (default 5)
 */
require_once __DIR__ . "/../config/database.php";
setCorsHeaders();
require_once dirname(__DIR__, 3) . "/includes/classes/OmPricing.php";
require_once dirname(__DIR__, 2) . "/rate-limit/RateLimiter.php";

// Rate limit: 20 req/min per IP
if (!RateLimiter::check(20, 60)) {
    exit;
}

try {
    $db = getDB();

    $originLat = (float)($_GET['origin_lat'] ?? 0);
    $originLng = (float)($_GET['origin_lng'] ?? 0);
    $destLat   = (float)($_GET['dest_lat'] ?? 0);
    $destLng   = (float)($_GET['dest_lng'] ?? 0);
    $excludeId = (int)($_GET['exclude_partner_id'] ?? 0);
    $limit     = min(10, max(1, (int)($_GET['limit'] ?? 5)));

    // Validate coordinates present (use isset check to allow 0.0 coords)
    if (!isset($_GET['origin_lat']) || !isset($_GET['origin_lng']) || !isset($_GET['dest_lat']) || !isset($_GET['dest_lng'])) {
        response(false, null, "Coordenadas obrigatorias: origin_lat, origin_lng, dest_lat, dest_lng", 400);
    }
    // Validate geographic bounds
    if (abs($originLat) > 90 || abs($destLat) > 90 || abs($originLng) > 180 || abs($destLng) > 180) {
        response(false, null, "Coordenadas fora do intervalo valido", 400);
    }
    if ($originLat == 0 && $originLng == 0 && $destLat == 0 && $destLng == 0) {
        response(false, null, "Coordenadas obrigatorias: origin_lat, origin_lng, dest_lat, dest_lng", 400);
    }

    // Distancia total da rota
    $rotaKm = OmPricing::calcularDistancia($originLat, $originLng, $destLat, $destLng);

    // Bounding box com 1.5km de margem (~0.014 graus)
    $margin = 0.014;
    $minLat = min($originLat, $destLat) - $margin;
    $maxLat = max($originLat, $destLat) + $margin;
    $minLng = min($originLng, $destLng) - $margin;
    $maxLng = max($originLng, $destLng) + $margin;

    // Buscar lojas BoraUm ativas dentro do bounding box
    $sql = "SELECT p.partner_id, p.name, p.trade_name, p.logo, p.categoria,
                   p.latitude, p.longitude, p.delivery_time_min, p.delivery_time_max,
                   p.is_open, p.horario_abre, p.horario_fecha
            FROM om_market_partners p
            WHERE p.status::text = '1'
              AND p.latitude IS NOT NULL AND p.longitude IS NOT NULL
              AND p.latitude BETWEEN ? AND ?
              AND p.longitude BETWEEN ? AND ?
              AND p.partner_id != ?
            ORDER BY p.partner_id";

    $stmt = $db->prepare($sql);
    $stmt->execute([$minLat, $maxLat, $minLng, $maxLng, $excludeId]);
    $candidatos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Filtrar por distancia perpendicular a rota
    $filteredCandidatos = [];
    $filteredPartnerIds = [];
    foreach ($candidatos as $c) {
        $lat = (float)$c['latitude'];
        $lng = (float)$c['longitude'];

        $result = OmPricing::distanciaPerpendicularRota(
            $originLat, $originLng,
            $destLat, $destLng,
            $lat, $lng
        );

        $distancia = $result['distancia'];
        $progresso = $result['progresso'];

        // Filtros: max 1km de desvio, entre 5% e 95% da rota
        if ($distancia > OmPricing::MULTISTOP_MAX_DESVIO_KM) continue;
        if ($progresso < 0.05 || $progresso > 0.95) continue;

        $c['_distancia'] = $distancia;
        $c['_progresso'] = $progresso;
        $c['_lat'] = $lat;
        $c['_lng'] = $lng;
        $filteredCandidatos[] = $c;
        $filteredPartnerIds[] = (int)$c['partner_id'];
    }

    // Batch query: fetch top 4 products for all filtered partners at once
    $allProducts = [];
    if (!empty($filteredPartnerIds)) {
        $ph = implode(',', array_fill(0, count($filteredPartnerIds), '?'));
        $stmtProd = $db->prepare("
            SELECT partner_id, product_id, name, price, image
            FROM (
                SELECT partner_id, product_id, name, price, image,
                       ROW_NUMBER() OVER (PARTITION BY partner_id ORDER BY sort_order ASC, product_id DESC) as rn
                FROM om_market_products
                WHERE partner_id IN ($ph) AND status::text = '1' AND price > 0
            ) ranked
            WHERE rn <= 4
        ");
        $stmtProd->execute($filteredPartnerIds);
        foreach ($stmtProd->fetchAll(PDO::FETCH_ASSOC) as $prod) {
            $allProducts[(int)$prod['partner_id']][] = $prod;
        }
    }

    $lojas = [];
    foreach ($filteredCandidatos as $c) {
        $lat = $c['_lat'];
        $lng = $c['_lng'];
        $distancia = $c['_distancia'];
        $progresso = $c['_progresso'];

        $produtos = $allProducts[(int)$c['partner_id']] ?? [];

        // Pular loja sem produtos
        if (empty($produtos)) continue;

        $tempoExtra = 5; // ~5 min por parada
        if ($distancia > 0.5) $tempoExtra = 7;

        // Check if store is currently open
        $this_open = true;
        if (isset($c['is_open']) && $c['is_open'] !== null) {
            $this_open = (bool)$c['is_open'];
        }
        if ($this_open && !empty($c['horario_abre']) && !empty($c['horario_fecha'])) {
            $now = date('H:i');
            $abre = substr($c['horario_abre'], 0, 5);
            $fecha = substr($c['horario_fecha'], 0, 5);
            if ($abre < $fecha) {
                $this_open = ($now >= $abre && $now < $fecha);
            } else {
                // Crosses midnight
                $this_open = ($now >= $abre || $now < $fecha);
            }
        }

        $lojas[] = [
            'id' => (int)$c['partner_id'],
            'nome' => $c['trade_name'] ?: $c['name'],
            'logo' => $c['logo'] ?: null,
            'categoria' => $c['categoria'] ?: null,
            'lat' => $lat,
            'lng' => $lng,
            'distancia_rota_m' => (int)round($distancia * 1000),
            'progresso' => round($progresso, 2),
            'tempo_extra_min' => $tempoExtra,
            'aberto' => $this_open,
            'produtos' => array_map(function($p) {
                return [
                    'product_id' => (int)$p['product_id'],
                    'name' => $p['name'],
                    'price' => (float)$p['price'],
                    'image' => $p['image'] ?: null,
                ];
            }, $produtos),
        ];
    }

    // Ordenar por progresso na rota (ordem que motorista vai passar)
    usort($lojas, fn($a, $b) => $a['progresso'] <=> $b['progresso']);

    // Limitar
    $lojas = array_slice($lojas, 0, $limit);

    response(true, [
        'rota_km' => round($rotaKm, 1),
        'lojas' => $lojas,
    ]);

} catch (Exception $e) {
    error_log("[na-rota] Erro: " . $e->getMessage());
    response(false, null, "Erro ao buscar lojas na rota", 500);
}
