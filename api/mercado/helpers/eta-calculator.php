<?php
/**
 * Smart ETA Calculator
 * Replaces the simple `distance * 4` formula with data-driven estimates.
 *
 * Uses:
 * 1. Partner's avg prep time (last 30 days)
 * 2. Average delivery time for similar distances
 * 3. Rush hour multiplier (11-13h and 18-20h)
 * 4. Status-based calculation (pending=full, preparing=partial, etc.)
 * 5. Fallback: $distanceKm * 4
 *
 * Usage:
 *   require_once __DIR__ . '/../helpers/eta-calculator.php';
 *   $minutes = calculateSmartETA($db, $partnerId, $distanceKm, 'pendente');
 */

/**
 * Calculate smart ETA in minutes based on historical data.
 *
 * @param PDO    $db            Database connection
 * @param int    $partnerId     Partner ID
 * @param float  $distanceKm    Distance in kilometers
 * @param string $currentStatus Order status (pendente, preparando, pronto, em_entrega)
 * @return int   Estimated minutes remaining
 */
function calculateSmartETA(PDO $db, int $partnerId, float $distanceKm, string $currentStatus): int {
    $distanceKm = max(0.1, $distanceKm);

    // ── 1. Get partner's average prep time (last 30 days) ──
    $avgPrepMinutes = getAvgPrepTime($db, $partnerId);

    // ── 2. Get average delivery time for similar distances ──
    $avgDeliveryMinutes = getAvgDeliveryTime($db, $distanceKm);

    // ── 3. Rush hour multiplier ──
    $rushMultiplier = getRushHourMultiplier();

    // ── 4. Calculate based on current status ──
    $totalMinutes = 0;

    switch ($currentStatus) {
        case 'pendente':
        case 'confirmado':
            // Full ETA: acceptance wait + prep + delivery
            $acceptanceWait = 3; // avg 3 min for partner to accept
            $totalMinutes = $acceptanceWait + $avgPrepMinutes + $avgDeliveryMinutes;
            break;

        case 'aceito':
        case 'preparando':
            // Partial: prep (assume halfway if 'preparando') + delivery
            $prepRemaining = ($currentStatus === 'preparando')
                ? $avgPrepMinutes * 0.5
                : $avgPrepMinutes;
            $totalMinutes = $prepRemaining + $avgDeliveryMinutes;
            break;

        case 'pronto':
            // Only delivery time
            $totalMinutes = $avgDeliveryMinutes;
            break;

        case 'em_entrega':
        case 'saiu_entrega':
            // Delivery in progress — estimate remaining as half
            $totalMinutes = $avgDeliveryMinutes * 0.5;
            break;

        default:
            // Unknown status — full estimate
            $totalMinutes = $avgPrepMinutes + $avgDeliveryMinutes;
            break;
    }

    // Apply rush hour multiplier
    $totalMinutes *= $rushMultiplier;

    // Clamp to reasonable range: min 5, max 120 minutes
    $totalMinutes = max(5, min(120, (int)round($totalMinutes)));

    return $totalMinutes;
}

/**
 * Get average preparation time for a partner from completed orders.
 *
 * Measures time between 'aceito'/'preparando' and 'pronto' statuses.
 * Falls back to 15 minutes if no data.
 *
 * @param PDO $db
 * @param int $partnerId
 * @return float Average prep time in minutes
 */
function getAvgPrepTime(PDO $db, int $partnerId): float {
    try {
        // Check cached metrics first
        $stmt = $db->prepare("
            SELECT avg_prep_minutes FROM om_partner_metrics
            WHERE partner_id = ? AND calculated_at > NOW() - INTERVAL '24 hours'
        ");
        $stmt->execute([$partnerId]);
        $cached = $stmt->fetch();

        if ($cached && $cached['avg_prep_minutes'] !== null) {
            return (float)$cached['avg_prep_minutes'];
        }

        // Calculate from order timestamps
        // Prep time = time between accepted_at and prep_finished_at
        $stmt = $db->prepare("
            SELECT AVG(EXTRACT(EPOCH FROM (prep_finished_at - accepted_at)) / 60.0) as avg_prep
            FROM om_market_orders
            WHERE partner_id = ?
              AND accepted_at IS NOT NULL
              AND prep_finished_at IS NOT NULL
              AND prep_finished_at > accepted_at
              AND status NOT IN ('cancelado', 'cancelled')
              AND date_added >= NOW() - INTERVAL '30 days'
        ");
        $stmt->execute([$partnerId]);
        $result = $stmt->fetch();

        if ($result && $result['avg_prep'] !== null && (float)$result['avg_prep'] > 0) {
            return round((float)$result['avg_prep'], 1);
        }
    } catch (Exception $e) {
        error_log("[eta-calculator] Error getting avg prep time for partner {$partnerId}: " . $e->getMessage());
    }

    // Fallback: 15 minutes
    return 15.0;
}

/**
 * Get average delivery time for similar distances.
 *
 * Groups historical deliveries by distance band and returns the
 * average for the matching band. Falls back to distance * 4.
 *
 * @param PDO   $db
 * @param float $distanceKm
 * @return float Average delivery time in minutes
 */
function getAvgDeliveryTime(PDO $db, float $distanceKm): float {
    try {
        // Distance bands: 0-2km, 2-5km, 5-10km, 10+km
        $distMin = 0;
        $distMax = 2;
        if ($distanceKm > 2) { $distMin = 2; $distMax = 5; }
        if ($distanceKm > 5) { $distMin = 5; $distMax = 10; }
        if ($distanceKm > 10) { $distMin = 10; $distMax = 50; }

        $stmt = $db->prepare("
            SELECT AVG(EXTRACT(EPOCH FROM (delivered_at - delivering_at)) / 60.0) as avg_delivery
            FROM om_market_orders
            WHERE delivering_at IS NOT NULL
              AND delivered_at IS NOT NULL
              AND delivered_at > delivering_at
              AND distancia_km >= ? AND distancia_km < ?
              AND status = 'entregue'
              AND date_added >= NOW() - INTERVAL '30 days'
        ");
        $stmt->execute([$distMin, $distMax]);
        $result = $stmt->fetch();

        if ($result && $result['avg_delivery'] !== null && (float)$result['avg_delivery'] > 0) {
            return round((float)$result['avg_delivery'], 1);
        }
    } catch (Exception $e) {
        error_log("[eta-calculator] Error getting avg delivery time: " . $e->getMessage());
    }

    // Fallback: distance * 4 minutes per km
    return round($distanceKm * 4, 1);
}

/**
 * Get rush hour multiplier based on current time.
 *
 * Peak periods:
 * - Lunch:  11:00-13:00 → 1.3x
 * - Dinner: 18:00-20:00 → 1.3x
 * - Normal: 1.0x
 *
 * @return float Multiplier (1.0 to 1.3)
 */
function getRushHourMultiplier(): float {
    $hour = (int)(new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('G');

    // Lunch rush: 11-13h
    if ($hour >= 11 && $hour < 13) {
        return 1.3;
    }

    // Dinner rush: 18-20h
    if ($hour >= 18 && $hour < 20) {
        return 1.3;
    }

    // Moderate traffic: 13-14h, 20-21h
    if ($hour === 13 || $hour === 20) {
        return 1.15;
    }

    return 1.0;
}
