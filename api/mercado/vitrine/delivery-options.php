<?php
/**
 * GET /api/mercado/vitrine/delivery-options.php
 * Query: ?partner_id=X&lat=Y&lng=Z
 *
 * Returns available delivery options for a partner based on customer location:
 * - standard: normal delivery with time range
 * - express: if partner has express enabled and customer is within radius
 * - pickup: if partner allows pickup (shows store address)
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 2) . "/cache/CacheHelper.php";

setCorsHeaders();
header('Cache-Control: public, max-age=60');

try {
    $partnerId = (int)($_GET['partner_id'] ?? 0);
    $customerLat = (float)($_GET['lat'] ?? 0);
    $customerLng = (float)($_GET['lng'] ?? 0);

    if (!$partnerId) {
        response(false, null, "partner_id obrigatorio", 400);
    }

    $db = getDB();

    // Get partner info
    $stmtPartner = $db->prepare("
        SELECT
            p.partner_id,
            p.name,
            p.trade_name,
            p.address,
            p.city,
            p.state,
            p.cep,
            p.lat,
            p.lng,
            p.delivery_fee,
            p.free_delivery_above,
            p.delivery_time_min,
            p.min_order,
            p.aceita_retirada,
            p.phone
        FROM om_market_partners p
        WHERE p.partner_id = ? AND p.status = '1'
    ");
    $stmtPartner->execute([$partnerId]);
    $partner = $stmtPartner->fetch();

    if (!$partner) {
        response(false, null, "Parceiro nao encontrado", 404);
    }

    // Get express delivery config (partner-specific or global)
    $expressConfig = null;
    try {
        $stmtExpress = $db->prepare("
            SELECT * FROM om_express_delivery_config
            WHERE partner_id = ?
        ");
        $stmtExpress->execute([$partnerId]);
        $expressConfig = $stmtExpress->fetch();

        // If no partner-specific config, get global
        if (!$expressConfig) {
            $stmtGlobal = $db->prepare("
                SELECT * FROM om_express_delivery_config
                WHERE partner_id IS NULL
            ");
            $stmtGlobal->execute();
            $expressConfig = $stmtGlobal->fetch();
        }
    } catch (Exception $e) {
        // Table om_express_delivery_config may not exist yet — express delivery unavailable
        error_log("[vitrine/delivery-options] Tabela om_express_delivery_config nao encontrada: " . $e->getMessage());
        $expressConfig = null;
    }

    // Calculate distance if we have coordinates
    $distanceKm = null;
    $partnerLat = (float)($partner['lat'] ?? 0);
    $partnerLng = (float)($partner['lng'] ?? 0);

    if ($customerLat && $customerLng && $partnerLat && $partnerLng) {
        $distanceKm = haversineDistance($customerLat, $customerLng, $partnerLat, $partnerLng);
    }

    // Calculate surge pricing
    $surgeMultiplier = calculateSurgeMultiplier($db);

    // Build delivery options
    $options = [];

    // 1. Standard delivery (always available for delivery partners)
    $baseDeliveryFee = (float)($partner['delivery_fee'] ?? 5.99);
    $standardFee = round($baseDeliveryFee * $surgeMultiplier, 2);
    $standardTimeMin = (int)($partner['delivery_time_min'] ?? 45);
    $standardTimeMax = $standardTimeMin + 20;

    $options['standard'] = [
        'type' => 'standard',
        'name' => 'Entrega Padrao',
        'description' => 'Entrega no endereco selecionado',
        'available' => true,
        'fee' => $standardFee,
        'fee_original' => $baseDeliveryFee,
        'free_above' => (float)($partner['free_delivery_above'] ?? 0),
        'time_estimate' => "{$standardTimeMin}-{$standardTimeMax} min",
        'time_min' => $standardTimeMin,
        'time_max' => $standardTimeMax,
        'surge_active' => $surgeMultiplier > 1.0,
        'surge_multiplier' => $surgeMultiplier,
        'icon' => 'truck'
    ];

    // 2. Express delivery (if configured and within radius)
    $expressAvailable = false;
    $expressReason = null;

    if ($expressConfig && (bool)$expressConfig['enabled']) {
        $maxDistanceKm = (float)($expressConfig['max_distance_km'] ?? 5);
        $availableFrom = $expressConfig['available_from'] ?? '10:00:00';
        $availableUntil = $expressConfig['available_until'] ?? '22:00:00';
        $expressTimeMinutes = (int)($expressConfig['express_time_minutes'] ?? 20);
        $expressFee = (float)($expressConfig['express_fee'] ?? 9.90);

        // Check time availability
        $now = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
        $currentTime = $now->format('H:i:s');
        $isWithinHours = ($currentTime >= $availableFrom && $currentTime <= $availableUntil);

        // Check distance
        $isWithinDistance = ($distanceKm === null || $distanceKm <= $maxDistanceKm);

        // Check order capacity
        $maxOrdersPerHour = (int)($expressConfig['max_orders_per_hour'] ?? 10);
        $stmtExpressOrders = $db->prepare("
            SELECT COUNT(*) as cnt FROM om_market_orders
            WHERE partner_id = ?
            AND is_express = 1
            AND date_added >= NOW() - INTERVAL '1 hours'
            AND status NOT IN ('cancelled', 'entregue')
        ");
        $stmtExpressOrders->execute([$partnerId]);
        $expressOrdersRow = $stmtExpressOrders->fetch();
        $currentExpressOrders = (int)$expressOrdersRow['cnt'];
        $hasCapacity = $currentExpressOrders < $maxOrdersPerHour;

        if (!$isWithinHours) {
            $expressReason = "Disponivel das " . substr($availableFrom, 0, 5) . " as " . substr($availableUntil, 0, 5);
        } elseif (!$isWithinDistance && $distanceKm !== null) {
            $expressReason = "Disponivel ate " . number_format($maxDistanceKm, 1, ',', '.') . " km";
        } elseif (!$hasCapacity) {
            $expressReason = "Limite de pedidos express atingido";
        }

        $expressAvailable = $isWithinHours && $isWithinDistance && $hasCapacity;

        $options['express'] = [
            'type' => 'express',
            'name' => 'Entrega Express',
            'description' => $expressAvailable
                ? "Entrega em ate {$expressTimeMinutes} minutos"
                : $expressReason,
            'available' => $expressAvailable,
            'fee' => $expressFee,
            'fee_original' => $expressFee,
            'free_above' => null, // Express never free
            'time_estimate' => "{$expressTimeMinutes} min",
            'time_min' => $expressTimeMinutes,
            'time_max' => $expressTimeMinutes + 5,
            'surge_active' => false, // Express has fixed price
            'surge_multiplier' => 1.0,
            'icon' => 'zap',
            'badge' => 'EXPRESS',
            'badge_color' => '#f59e0b',
            'unavailable_reason' => $expressAvailable ? null : $expressReason,
            'max_distance_km' => $maxDistanceKm,
            'distance_km' => $distanceKm ? round($distanceKm, 2) : null,
            'available_from' => substr($availableFrom, 0, 5),
            'available_until' => substr($availableUntil, 0, 5)
        ];
    }

    // 3. Pickup option
    $allowsPickup = (bool)($partner['aceita_retirada'] ?? true); // Default to true if not set

    // Format store address for pickup
    $storeAddress = trim(implode(', ', array_filter([
        $partner['address'],
        $partner['city'],
        $partner['state']
    ])));

    if (!$storeAddress) {
        $storeAddress = 'Endereco da loja';
    }

    $options['pickup'] = [
        'type' => 'pickup',
        'name' => 'Retirar na Loja',
        'description' => $allowsPickup
            ? $storeAddress
            : 'Nao disponivel para este estabelecimento',
        'available' => $allowsPickup,
        'fee' => 0,
        'fee_original' => 0,
        'free_above' => null,
        'time_estimate' => '15-25 min',
        'time_min' => 15,
        'time_max' => 25,
        'surge_active' => false,
        'surge_multiplier' => 1.0,
        'icon' => 'shopping-bag',
        'store_address' => $storeAddress,
        'store_phone' => $partner['phone'] ?? null,
        'pickup_instructions' => 'Apresente o codigo do pedido no balcao',
        'store_lat' => $partnerLat ?: null,
        'store_lng' => $partnerLng ?: null
    ];

    // Response
    response(true, [
        'partner_id' => $partnerId,
        'partner_name' => $partner['trade_name'] ?: $partner['name'],
        'options' => $options,
        'default_option' => 'standard',
        'min_order' => (float)($partner['min_order'] ?? 0),
        'distance_km' => $distanceKm ? round($distanceKm, 2) : null
    ]);

} catch (Exception $e) {
    error_log("[vitrine/delivery-options] Erro: " . $e->getMessage());
    response(false, null, "Erro ao carregar opcoes de entrega", 500);
}

/**
 * Calculate distance between two points using Haversine formula
 */
function haversineDistance($lat1, $lng1, $lat2, $lng2) {
    $earthRadius = 6371; // km

    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);

    $a = sin($dLat / 2) * sin($dLat / 2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLng / 2) * sin($dLng / 2);

    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earthRadius * $c;
}

/**
 * Calculate surge pricing multiplier based on current demand
 */
function calculateSurgeMultiplier($db) {
    $surgeMultiplier = 1.0;

    // Check if surge is enabled
    $stmtCfg = $db->prepare("SELECT valor FROM om_config WHERE chave = 'surge_enabled'");
    $stmtCfg->execute();
    $cfgRow = $stmtCfg->fetch();
    $surgeEnabled = $cfgRow ? (int)$cfgRow['valor'] : 1;

    if (!$surgeEnabled) {
        return 1.0;
    }

    // Calculate load
    $loadSql = "SELECT
        (SELECT COUNT(*) FROM om_market_orders WHERE status IN ('pending','pendente','aceito','preparando','pronto') AND DATE(date_added) = CURRENT_DATE) as active_orders,
        (SELECT COUNT(*) FROM om_market_shoppers WHERE status = '1' AND (is_online = 1 OR is_online IS NULL)) as available_shoppers";
    $loadStmt = $db->query($loadSql);
    $load = $loadStmt->fetch();

    $activeOrders = (int)$load['active_orders'];
    $availableShoppers = max((int)$load['available_shoppers'], 1);
    $ratio = $activeOrders / $availableShoppers;

    // Load thresholds
    $stmtTh = $db->prepare("SELECT valor FROM om_config WHERE chave = 'surge_thresholds'");
    $stmtTh->execute();
    $thCfg = $stmtTh->fetch();
    $thresholds = $thCfg ? json_decode($thCfg['valor'], true) : null;

    if (!$thresholds || !is_array($thresholds)) {
        $thresholds = [
            ['ratio' => 2, 'multiplier' => 1.5],
            ['ratio' => 4, 'multiplier' => 2.0],
            ['ratio' => 6, 'multiplier' => 2.5],
        ];
    }

    usort($thresholds, function($a, $b) { return $b['ratio'] - $a['ratio']; });
    foreach ($thresholds as $t) {
        if ($ratio > $t['ratio']) {
            $surgeMultiplier = (float)$t['multiplier'];
            break;
        }
    }

    // Time bonus for peak hours
    $hour = (int)date('G');
    $dayOfWeek = (int)date('N');
    if ($dayOfWeek <= 5 && (($hour >= 11 && $hour < 14) || ($hour >= 18 && $hour < 22))) {
        $surgeMultiplier += 0.2;
    }

    return round(min($surgeMultiplier, 3.0), 1);
}
