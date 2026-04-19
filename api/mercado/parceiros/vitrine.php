<?php
/**
 * GET /api/mercado/parceiros/vitrine.php
 * Vitrine de estabelecimentos - lista parceiros ativos para exibicao publica
 *
 * Parametros opcionais:
 *   ?categoria=mercado|restaurante|farmacia|loja
 *   ?busca=termo
 *   ?lat=&lng= (para calculo de distancia)
 *
 * Personalizacao: se usuario autenticado, reordena por score ML (historico + horario + favoritos).
 * Cache por customer_id quando autenticado, senao cache global.
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 2) . "/cache/CacheHelper.php";
require_once __DIR__ . "/../helpers/cache.php";
require_once __DIR__ . "/../helpers/boraum-api.php";
require_once __DIR__ . '/horarios.php'; // isOpenNow() for store hours
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();
header('Cache-Control: private, max-age=30');

try {
    $categoria = $_GET["categoria"] ?? null;
    $busca = $_GET["busca"] ?? null;
    $lat = isset($_GET["lat"]) ? (float)$_GET["lat"] : null;
    $lng = isset($_GET["lng"]) ? (float)$_GET["lng"] : null;
    $city = isset($_GET["city"]) ? trim($_GET["city"]) : null;
    $state = isset($_GET["state"]) ? trim($_GET["state"]) : null;

    // Optional customer auth — if present, enables personalized ranking
    $customerId = null;
    try {
        OmAuth::getInstance()->setDb(getDB());
        $token = om_auth()->getTokenFromRequest();
        if ($token) {
            $payload = om_auth()->validateToken($token);
            if ($payload && ($payload['type'] ?? '') === 'customer') {
                $customerId = (int)$payload['uid'];
            }
        }
    } catch (Exception $e) { /* anonymous fallback */ }

    // Validar categoria se fornecida
    $categorias_validas = ['mercado', 'restaurante', 'farmacia', 'loja'];
    if ($categoria && !in_array($categoria, $categorias_validas)) {
        $categoria = null;
    }

    // Cache key baseado nos parametros — inclui customer_id se autenticado (personalizacao varia por usuario)
    $cacheKey = "vitrine:" . md5(($categoria ?? '') . ($busca ?? '') . ($lat ?? '') . ($lng ?? '') . ($city ?? '') . ($state ?? '') . ':cid=' . ($customerId ?? ''));

    $data = cachedQuery($cacheKey, 60, function() use ($categoria, $busca, $lat, $lng, $city, $state, $customerId) {
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
            $where[] = "p.name ILIKE ?";
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
                       p.opens_at, p.closes_at, p.weekly_hours, p.horario_funcionamento,
                       p.horario_abre, p.horario_fecha,
                       p.open_sunday, p.sunday_opens_at, p.sunday_closes_at,
                       p.rating, p.delivery_fee, p.delivery_time_min,
                       p.min_order, p.free_delivery_above,
                       p.busy_mode, p.current_prep_time,
                       p.lat, p.lng, p.banner, p.description,
                       p.aceita_boraum, p.entrega_propria
                       {$distanciaSelect},
                       (SELECT COUNT(*) FROM om_market_products mp WHERE mp.partner_id = p.partner_id AND mp.status::text = '1') as total_produtos
                FROM om_market_partners p
                WHERE {$whereClause}
                ORDER BY {$distanciaOrder}p.name ASC
                LIMIT 50";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $parceiros = $stmt->fetchAll();

        // Check BoraUm driver availability if customer coordinates are provided
        $boraUmAvail = null;
        if ($lat !== null && $lng !== null && $lat != 0 && $lng != 0) {
            $boraUmAvail = getBoraUmAvailability($lat, $lng);
        }
        $driversAvailable = ($boraUmAvail !== null) ? hasAvailableDrivers($boraUmAvail) : null;

        // ── PERSONALIZATION: build user signals (only if authenticated) ──
        $userSignals = ['order_count' => [], 'category_affinity' => [], 'hour_affinity' => [], 'favorites' => []];
        if ($customerId) {
            try {
                // Orders in last 90 days: count per partner + hour distribution + category affinity
                $stmt = $db->prepare("
                    SELECT o.partner_id, EXTRACT(HOUR FROM o.date_added)::int AS hr,
                           p.categoria
                    FROM om_market_orders o
                    LEFT JOIN om_market_partners p ON p.partner_id = o.partner_id
                    WHERE o.customer_id = ?
                      AND o.date_added > NOW() - INTERVAL '90 days'
                      AND o.order_status_id NOT IN (7, 11)
                    LIMIT 500
                ");
                $stmt->execute([$customerId]);
                while ($row = $stmt->fetch()) {
                    $pid = (int)$row['partner_id'];
                    $hr = (int)$row['hr'];
                    $cat = $row['categoria'] ?? '';
                    $userSignals['order_count'][$pid] = ($userSignals['order_count'][$pid] ?? 0) + 1;
                    $userSignals['hour_affinity'][$hr] = ($userSignals['hour_affinity'][$hr] ?? 0) + 1;
                    if ($cat) $userSignals['category_affinity'][$cat] = ($userSignals['category_affinity'][$cat] ?? 0) + 1;
                }

                // Favorites (uses om_customer_favorites with partner_id column)
                try {
                    $stmt = $db->prepare("SELECT partner_id FROM om_customer_favorites WHERE customer_id = ? AND partner_id IS NOT NULL");
                    $stmt->execute([$customerId]);
                    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $favId) {
                        $userSignals['favorites'][(int)$favId] = true;
                    }
                } catch (Exception $e) { /* table variant may differ across envs */ }
            } catch (Exception $e) {
                error_log("[vitrine personalization signals] " . $e->getMessage());
            }
        }

        $currentHour = (int)date('G');

        return array_map(function($p) use ($driversAvailable, $userSignals, $currentHour, $customerId) {
            // Determine if this store uses BoraUm for delivery
            $usaBoraUm = !($p['entrega_propria'] ?? false) && ($p['aceita_boraum'] ?? true);

            // delivery_available: false only if store uses BoraUm AND we confirmed no drivers
            $deliveryAvailable = true;
            if ($usaBoraUm && $driversAvailable === false) {
                $deliveryAvailable = false;
            }

            // Compute open/close status from partner hours table
        $horarioStatus = null;
        try {
            $horarioStatus = isOpenNow($p);
        } catch (\Throwable $e) {
            // Fallback: use is_open flag
        }

        $isAberto = $horarioStatus ? (bool)$horarioStatus['is_open'] : ((int)($p["is_open"] ?? 0) === 1);
        $horarioMsg = $horarioStatus['message'] ?? null;
        $fechaAs = $horarioStatus['closes_at'] ?? null;
        $abreAs = $horarioStatus['opens_at'] ?? null;

        // ── PERSONALIZATION SCORE ──
        $partnerId = (int)$p["partner_id"];
        $score = 0.0;
        $reasons = [];
        if ($customerId) {
            $orderCount = $userSignals['order_count'][$partnerId] ?? 0;
            if ($orderCount > 0) {
                $score += min(80, 30 + $orderCount * 12);
                $reasons[] = $orderCount === 1 ? 'Voce pediu aqui' : "Voce pediu aqui {$orderCount}x";
            }
            $cat = $p["categoria"] ?? '';
            $catCount = $userSignals['category_affinity'][$cat] ?? 0;
            if ($catCount > 0 && $orderCount === 0) {
                $score += min(25, $catCount * 4);
            }
            if (!empty($userSignals['favorites'][$partnerId])) {
                $score += 40;
                $reasons[] = 'Favorito';
            }
            // Time-of-day affinity: boost if user historically orders at this hour (±1)
            foreach ([-1, 0, 1] as $offset) {
                $hr = ($currentHour + $offset + 24) % 24;
                if (!empty($userSignals['hour_affinity'][$hr])) {
                    $score += min(15, $userSignals['hour_affinity'][$hr] * 1.5);
                    break;
                }
            }
        }
        // Open stores always win tiebreakers
        if ($isAberto) $score += 20;
        // Rating bump
        $rating = (float)($p["rating"] ?? 5.0);
        if ($rating >= 4.5) $score += 8;
        elseif ($rating >= 4.0) $score += 4;
        // Distance penalty (closer is better)
        if (isset($p["distancia"])) {
            $score -= min(40, (float)$p["distancia"] * 3);
        }

        return [
                "id" => $partnerId,
                "nome" => $p["name"] ?? $p["trade_name"] ?? "",
                "logo" => $p["logo"] ?? null,
                "categoria" => $p["categoria"] ?? "mercado",
                "_score" => round($score, 2),
                "personalization_reason" => $reasons ? $reasons[0] : null,
                "endereco" => $p["address"] ?? "",
                "cidade" => $p["city"] ?? "",
                "estado" => $p["state"] ?? "",
                "telefone" => $p["phone"] ?? "",
                "horario_abertura" => $p["open_time"] ?? null,
                "horario_fechamento" => $p["close_time"] ?? null,
                "aberto" => $isAberto,
                "fecha_as" => $fechaAs,
                "abre_as" => $abreAs,
                "horario_status" => $horarioMsg,
                "busy_mode" => (bool)($p["busy_mode"] ?? false),
                "avaliacao" => (float)($p["rating"] ?? 5.0),
                "taxa_entrega" => (float)($p["delivery_fee"] ?? 0),
                "tempo_estimado" => ($p["busy_mode"] && $p["current_prep_time"])
                    ? (int)$p["current_prep_time"]
                    : (int)($p["delivery_time_min"] ?? 60),
                "total_produtos" => (int)($p["total_produtos"] ?? 0),
                "distancia" => isset($p["distancia"]) ? round((float)$p["distancia"], 1) : null,
                "delivery_available" => $deliveryAvailable,
                "banner" => $p["banner"] ?? null,
                "descricao" => $p["description"] ?? null,
                "pedido_minimo" => (float)($p["min_order"] ?? 0),
                "frete_gratis_acima" => (float)($p["free_delivery_above"] ?? 0),
            ];
        }, $parceiros);

        // Reorder by personalization score (descending). Ties keep original order (distance).
        if ($customerId) {
            usort($data, function($a, $b) {
                return ($b['_score'] ?? 0) <=> ($a['_score'] ?? 0);
            });
        }
        // Strip internal _score before returning (keep personalization_reason for UI badge)
        foreach ($data as &$d) { unset($d['_score']); }
        unset($d);

        return $data;
    });

    response(true, [
        "total" => count($data),
        "parceiros" => $data,
        "personalized" => !!$customerId,
    ]);

} catch (Exception $e) {
    error_log("[parceiros/vitrine] Erro: " . $e->getMessage());
    response(false, null, 'Erro interno do servidor', 500);
}
