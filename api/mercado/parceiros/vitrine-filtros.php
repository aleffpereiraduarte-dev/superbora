<?php
/**
 * GET /api/mercado/parceiros/vitrine-filtros.php
 * Listagem de lojas com filtros avancados estilo iFood
 *
 * Params: lat, lng, cep, store_type, open_now, free_delivery, max_delivery_time,
 *         max_delivery_fee, min_rating, category, sort_by, page, limit, q (search)
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmPricing.php";
require_once dirname(__DIR__, 2) . "/rate-limit/RateLimiter.php";

// Rate limit: 30 req/min per IP
if (!RateLimiter::check(30, 60)) {
    exit;
}

try {
    $db = getDB();

    // Params
    $lat = (float)($_GET['lat'] ?? 0);
    $lng = (float)($_GET['lng'] ?? 0);
    $storeType = $_GET['store_type'] ?? null; // restaurante, mercado, farmacia, bebidas, pet
    $openNow = ($_GET['open_now'] ?? null) === '1';
    $freeDelivery = ($_GET['free_delivery'] ?? null) === '1';
    $maxDeliveryTime = (int)($_GET['max_delivery_time'] ?? 0);
    $maxDeliveryFee = (float)($_GET['max_delivery_fee'] ?? 0);
    $minRating = (float)($_GET['min_rating'] ?? 0);
    $category = $_GET['category'] ?? null;
    $sortBy = $_GET['sort_by'] ?? 'relevance'; // relevance, distance, rating, delivery_time, min_order
    $allowedSorts = ['relevance', 'distance', 'rating', 'delivery_time', 'min_order'];
    if (!in_array($sortBy, $allowedSorts)) {
        $sortBy = 'relevance';
    }
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
    $search = trim($_GET['q'] ?? '');
    $offset = ($page - 1) * $limit;

    // Build query
    $where = ["p.status::text = '1'", "p.is_test = 0"];
    $params = [];

    if ($storeType) {
        $where[] = "p.store_type = ?";
        $params[] = $storeType;
    }

    if ($category) {
        $where[] = "(p.categoria = ? OR p.specialty = ?)";
        $params[] = $category;
        $params[] = $category;
    }

    if ($minRating > 0) {
        $where[] = "COALESCE(p.rating, 0) >= ?";
        $params[] = $minRating;
    }

    if ($maxDeliveryTime > 0) {
        $where[] = "COALESCE(p.delivery_time_min, p.default_prep_time, 60) <= ?";
        $params[] = $maxDeliveryTime;
    }

    if ($freeDelivery) {
        $where[] = "(p.free_delivery_above IS NOT NULL AND p.free_delivery_above > 0)";
    }

    if ($search) {
        $searchEscaped = str_replace(['%', '_'], ['\\%', '\\_'], $search);
        $where[] = "(p.name ILIKE ? OR p.trade_name ILIKE ? OR p.display_name ILIKE ? OR p.categoria ILIKE ?)";
        $searchParam = "%{$searchEscaped}%";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    }

    $whereSQL = implode(' AND ', $where);

    // Distance calculation
    $distanceExpr = "0";
    if ($lat && $lng) {
        $distanceExpr = "(6371 * acos(LEAST(1.0, cos(radians(?)) * cos(radians(COALESCE(p.latitude, p.lat, 0))) * cos(radians(COALESCE(p.longitude, p.lng, 0)) - radians(?)) + sin(radians(?)) * sin(radians(COALESCE(p.latitude, p.lat, 0))))))";
        array_unshift($params, $lat, $lng, $lat);
    }

    // Sort
    $orderBy = match($sortBy) {
        'distance' => "distance_km ASC",
        'rating' => "COALESCE(p.rating, 0) DESC",
        'delivery_time' => "delivery_time ASC",
        'min_order' => "COALESCE(p.min_order_value, p.min_order, 0) ASC",
        default => "p.featured DESC, COALESCE(p.rating, 0) DESC, p.total_orders DESC",
    };

    $sql = "SELECT p.partner_id, p.name, p.trade_name, p.display_name, p.logo, p.banner,
            p.categoria, p.store_type, p.rating,
            COALESCE(p.delivery_time_min, p.default_prep_time, 30) as delivery_time,
            COALESCE(p.delivery_time_max, COALESCE(p.delivery_time_min, 30) + 15) as delivery_time_max,
            COALESCE(p.min_order_value, p.min_order, 0) as min_order,
            COALESCE(p.free_delivery_above, 0) as free_delivery_above,
            COALESCE(p.delivery_fee, p.taxa_entrega, 0) as delivery_fee,
            p.featured, p.is_open,
            p.opens_at, p.closes_at, p.weekly_hours, p.horario_funcionamento,
            p.horario_abre, p.horario_fecha, p.open_time, p.close_time,
            p.open_sunday, p.sunday_opens_at, p.sunday_closes_at,
            p.busy_mode, p.busy_mode_until, p.entrega_propria,
            p.latitude, p.longitude, p.lat, p.lng,
            {$distanceExpr} as distance_km
        FROM om_market_partners p
        WHERE {$whereSQL}
        ORDER BY {$orderBy}
        LIMIT ? OFFSET ?";

    $params[] = $limit;
    $params[] = $offset;

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $partners = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Include horarios.php isOpenNow function
    require_once __DIR__ . '/horarios.php';

    $results = [];
    foreach ($partners as $p) {
        $openStatus = isOpenNow($p);

        // Filtrar se open_now ativo
        if ($openNow && !$openStatus['is_open']) continue;

        // Filtrar max_delivery_fee
        if ($maxDeliveryFee > 0 && (float)$p['delivery_fee'] > $maxDeliveryFee) continue;

        $deliveryTime = (int)$p['delivery_time'];
        $deliveryTimeMax = (int)$p['delivery_time_max'];

        $results[] = [
            'id' => (int)$p['partner_id'],
            'name' => $p['trade_name'] ?: $p['display_name'] ?: $p['name'],
            'logo' => $p['logo'],
            'banner' => $p['banner'],
            'category' => $p['categoria'] ?: $p['store_type'],
            'store_type' => $p['store_type'] ?: 'restaurante',
            'rating' => $p['rating'] ? round((float)$p['rating'], 1) : null,
            'delivery_time_label' => "{$deliveryTime}-{$deliveryTimeMax} min",
            'delivery_time_min' => $deliveryTime,
            'delivery_fee' => round((float)$p['delivery_fee'], 2),
            'free_delivery_above' => (float)$p['free_delivery_above'],
            'has_free_delivery' => (float)$p['free_delivery_above'] > 0,
            'min_order' => round((float)$p['min_order'], 2),
            'is_open' => $openStatus['is_open'],
            'status_message' => $openStatus['message'],
            'is_featured' => (bool)$p['featured'],
            'distance_km' => $lat ? round((float)$p['distance_km'], 1) : null,
            'entrega_propria' => (bool)$p['entrega_propria'],
        ];
    }

    // Count total
    $countParams = array_slice($params, 0, -2); // remove limit/offset
    if ($lat && $lng) $countParams = array_slice($countParams, 3); // remove distance params for count
    // Simplified count
    $countWhere = $where;
    $countSQL = "SELECT COUNT(*) FROM om_market_partners p WHERE " . implode(' AND ', $countWhere);
    $countStmtParams = [];
    if ($storeType) $countStmtParams[] = $storeType;
    if ($category) { $countStmtParams[] = $category; $countStmtParams[] = $category; }
    if ($minRating > 0) $countStmtParams[] = $minRating;
    if ($maxDeliveryTime > 0) $countStmtParams[] = $maxDeliveryTime;
    if ($search) { $countStmtParams[] = $searchParam; $countStmtParams[] = $searchParam; $countStmtParams[] = $searchParam; $countStmtParams[] = $searchParam; }
    $countStmt = $db->prepare($countSQL);
    $countStmt->execute($countStmtParams);
    $total = (int)$countStmt->fetchColumn();

    // Filtros disponiveis
    $filters = [
        'store_types' => [
            ['value' => 'restaurante', 'label' => 'Restaurantes', 'icon' => 'restaurant'],
            ['value' => 'mercado', 'label' => 'Mercados', 'icon' => 'cart'],
            ['value' => 'farmacia', 'label' => 'Farmacias', 'icon' => 'medical'],
            ['value' => 'bebidas', 'label' => 'Bebidas', 'icon' => 'wine'],
            ['value' => 'pet', 'label' => 'Pet Shop', 'icon' => 'paw'],
            ['value' => 'conveniencia', 'label' => 'Conveniencia', 'icon' => 'storefront'],
        ],
        'delivery_times' => [
            ['value' => 30, 'label' => 'Ate 30 min'],
            ['value' => 45, 'label' => 'Ate 45 min'],
            ['value' => 60, 'label' => 'Ate 1 hora'],
        ],
        'sort_options' => [
            ['value' => 'relevance', 'label' => 'Relevancia'],
            ['value' => 'distance', 'label' => 'Mais perto'],
            ['value' => 'rating', 'label' => 'Melhor avaliado'],
            ['value' => 'delivery_time', 'label' => 'Mais rapido'],
            ['value' => 'min_order', 'label' => 'Menor pedido minimo'],
        ],
    ];

    response(true, [
        'stores' => $results,
        'total' => $total,
        'page' => $page,
        'limit' => $limit,
        'has_more' => ($page * $limit) < $total,
        'filters' => $filters,
    ]);

} catch (Exception $e) {
    error_log("[VitrineFiltros] " . $e->getMessage());
    response(false, null, 'Erro interno', 500);
}
